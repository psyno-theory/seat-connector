<?php

namespace Warlof\Seat\Connector\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Services\Settings\Profile;
use Seat\Web\Events\SecLog;
use Seat\Web\Models\Squads\Squad;
use Seat\Web\Models\User as SeatUser;
use Warlof\Seat\Connector\Drivers\ISet;
use Warlof\Seat\Connector\Jobs\NotifyDriver;
use Warlof\Seat\Connector\Models\Set;
use Warlof\Seat\Connector\Models\StrictExemption;
use Warlof\Seat\Connector\Models\User;
use Warlof\Seat\Connector\Traits\ConnectorPolicyManagement;

/** Real Laravel, SeAT relations/settings and connector enforcement; no EVE or Discord calls. */
class StrictExemptionsTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
        if (getenv('CONNECTOR_TEST_DB_DRIVER') === 'mysql') {
            $app['config']->set('database.connections.testing', [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
                'database' => 'connector_test', 'username' => 'root',
                'password' => getenv('CONNECTOR_TEST_DB_PASSWORD'),
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '', 'strict' => true,
            ]);
        } else {
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('auth.providers.users.model', SeatUser::class);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function defineRoutes($router)
    {
        $router->aliasMiddleware('locale', \Seat\Web\Http\Middleware\Locale::class);
        require __DIR__.'/../src/Http/routes.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Only isolated test databases may be cleared between native SQL cases.
        $this->assertContains(DB::connection()->getDatabaseName(), [':memory:', 'connector_test']);
        Schema::dropAllTables();
        $this->createSchema();
        Profile::define('language', 'en');
        Gate::define('global.superuser', fn (SeatUser $user): bool => $user->isAdmin());
        Gate::define('seat-connector.security', fn (SeatUser $user): bool => true);
        Event::listen('security.log', SecLog::class);
        Queue::fake();
        setting(['seat-connector.strict', true], true);
    }

    public function testRevokedAltBlocksMappedSquadRoleUntilExempted(): void
    {
        $profile = $this->spud();
        $this->grantSquadRole($profile);
        $this->assertFalse($profile->areAllTokensValid());
        $this->assertSame([], $profile->allowedSets());
        $this->exempt($profile, [1002]);
        $this->assertTrue($profile->areAllTokensValid());
        $this->assertSame(['tournament'], $profile->allowedSets());
        $this->exempt($profile, []);
        $this->assertSame([], $profile->allowedSets());
    }

    public function testAnotherRevokedAltStillBlocks(): void
    {
        $profile = $this->spud();
        $this->character($profile->user, 1003, false);
        $this->exempt($profile, [1002]);
        $this->assertFalse($profile->areAllTokensValid());
        $this->assertSame([], $profile->allowedSets());
    }

    public function testExemptionDoesNotGrantAnUnmappedRole(): void
    {
        $profile = $this->spud();
        Set::create(['connector_type' => 'discord', 'connector_id' => 'unmapped', 'name' => 'Unmapped']);
        $this->exempt($profile, [1002]);
        $this->assertTrue($profile->areAllTokensValid());
        $this->assertSame([], $profile->allowedSets());
    }

    public function testExemptingAnActiveAltFiltersBothSidesOfTheCheck(): void
    {
        $profile = $this->spud();
        $this->character($profile->user, 1003, true);
        $this->exempt($profile, [1002, 1003]);
        $this->assertTrue($profile->areAllTokensValid());
    }

    public function testAllCharactersExemptOrOnlyExemptCharactersActiveCannotAuthorize(): void
    {
        $profile = $this->spud();
        $this->grantSquadRole($profile);
        $this->exempt($profile, [1001, 1002]);
        $this->assertFalse($profile->areAllTokensValid());
        $this->assertSame([], $profile->allowedSets());
        $this->exempt($profile, [1001]);
        $this->assertFalse($profile->areAllTokensValid());
        $this->assertSame([], $profile->allowedSets());
    }

    public function testInactiveAccountIsDeniedDespiteValidRemainingCharacter(): void
    {
        $profile = $this->spud();
        $this->grantSquadRole($profile);
        $this->exempt($profile, [1002]);
        $profile->user->update(['active' => false]);
        $this->assertSame([], $profile->allowedSets());
    }

    public function testNormalModeAllowsRevokedAltsButStillRequiresAnActiveToken(): void
    {
        $profile = $this->spud();
        $this->grantSquadRole($profile);
        setting(['seat-connector.strict', false], true);
        $this->assertSame(['tournament'], $profile->allowedSets());
        DB::table('refresh_tokens')->where('character_id', 1001)->update(['deleted_at' => now()]);
        $this->assertSame([], $profile->allowedSets());
    }

    public function testExemptionsAreScopedToAccountAndDiscord(): void
    {
        $profile = $this->spud();
        $this->exempt($profile, [1002]);
        $other = $this->identity($this->account('Other'));
        $this->character($other->user, 2001, true);
        $this->character($other->user, 2002, false);
        $this->assertSame([], $other->getStrictExemptCharacterIds());
        $this->assertFalse($other->areAllTokensValid());
        $teamspeak = $this->identity($profile->user, 'teamspeak');
        $this->assertSame([], $teamspeak->getStrictExemptCharacterIds());
        $this->assertFalse($teamspeak->areAllTokensValid());
    }

    public function testDatabaseExemptionsSurviveCacheFlush(): void
    {
        $profile = $this->spud();
        $this->exempt($profile, [1002]);
        $this->assertSame([1002], $profile->getStrictExemptCharacterIds());
        Cache::flush();
        $this->assertSame([1002], $profile->fresh()->getStrictExemptCharacterIds());
        $this->assertTrue($profile->fresh()->areAllTokensValid());
    }

    public function testLegacySettingsCannotBypassTheDatabaseCheck(): void
    {
        $profile = $this->spud();
        Profile::set('seat-connector.discord_strict_exempt_characters', 'invalid', $profile->user_id);
        $this->assertSame([], $profile->getStrictExemptCharacterIds());
        $this->assertFalse($profile->areAllTokensValid());
    }

    public function testRevokedTokenWithoutCharacterInfoStillBlocksUnlessExempt(): void
    {
        $profile = $this->spud();
        CharacterInfo::where('character_id', 1002)->delete();
        $this->assertFalse($profile->areAllTokensValid());
        $this->exempt($profile, [1002]);
        $this->assertTrue($profile->areAllTokensValid());
    }

    public function testLoadedTokenRelationsDoNotHideSubsequentRevocation(): void
    {
        $profile = $this->spud();
        $this->exempt($profile, [1002]);
        $profile->user->load('refresh_tokens');
        $this->assertTrue($profile->areAllTokensValid());
        DB::table('refresh_tokens')->where('character_id', 1001)->update(['deleted_at' => now()]);
        $this->assertFalse($profile->areAllTokensValid());
        $this->assertSame([], $profile->allowedSets());
    }

    public function testSuperuserCanReadAndSaveRevokedAltWithoutChangingItsData(): void
    {
        $profile = $this->spud();
        $admin = $this->account('Administrator', true);
        $this->actingAs($admin);
        $response = $this->getJson($this->endpoint($profile))->assertOk();
        $characters = collect($response->json('characters'))->keyBy('id');
        $this->assertTrue($characters[1001]['has_token']);
        $this->assertFalse($characters[1002]['has_token']);
        $this->assertFalse($characters[1002]['exempt']);
        $this->assertArrayHasKey('update_url', $response->json());
        $this->assertStringNotContainsString('test-refresh-secret', $response->getContent());
        $this->assertStringNotContainsString('test-access-secret', $response->getContent());
        $before = DB::table('refresh_tokens')->orderBy('character_id')->get()->toJson();
        $this->putJson($this->endpoint($profile), ['characters' => [1002]])->assertOk()->assertJson(['success' => true]);
        $this->assertSame([1002], $profile->getStrictExemptCharacterIds());
        $this->assertTrue($profile->areAllTokensValid());
        $this->assertSame($before, DB::table('refresh_tokens')->orderBy('character_id')->get()->toJson());
        $this->assertDatabaseCount('character_infos', 2);
        $this->assertDatabaseCount('security_logs', 1);
        $this->assertDatabaseHas('security_logs', ['user_id' => $admin->id]);
        Queue::assertPushed(NotifyDriver::class, fn (NotifyDriver $job): bool => $job->queue === 'high');
        $this->putJson($this->endpoint($profile), ['characters' => []])->assertOk();
        $this->assertSame([], $profile->getStrictExemptCharacterIds());
        $this->assertFalse($profile->areAllTokensValid());
    }

    public function testSuperuserCanSaveAllExemptButTheAccountStillCannotAuthorize(): void
    {
        $profile = $this->spud();
        $this->grantSquadRole($profile);
        $this->actingAs($this->account('Administrator', true));
        $this->putJson($this->endpoint($profile), ['characters' => [1001, 1002]])->assertOk();
        $this->assertFalse($profile->areAllTokensValid());
        $this->assertSame([], $profile->allowedSets());
        DB::table('refresh_tokens')->where('character_id', 1001)->update(['deleted_at' => now()]);
        $this->putJson($this->endpoint($profile), ['characters' => []])->assertOk();
        $this->assertSame([], $profile->allowedSets());
    }

    public function testGuestsAndConnectorStaffCannotReadOrChangeExemptions(): void
    {
        $profile = $this->spud();
        $this->getJson($this->endpoint($profile))->assertUnauthorized();
        $this->putJson($this->endpoint($profile), ['characters' => [1002]])->assertUnauthorized();
        $this->actingAs($this->account('Connector staff'));
        $this->getJson($this->endpoint($profile))->assertForbidden();
        $this->putJson($this->endpoint($profile), ['characters' => [1002]])->assertForbidden();
        $this->assertSame([], $profile->getStrictExemptCharacterIds());
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('security_logs', 0);
    }

    /** @dataProvider invalidSelections */
    public function testInvalidOrForeignSelectionsDoNotWrite(array $payload): void
    {
        $profile = $this->spud();
        $other = $this->account('Other');
        $this->character($other, 2001, false);
        $this->actingAs($this->account('Administrator', true));
        $this->putJson($this->endpoint($profile), $payload)->assertUnprocessable();
        $this->assertSame([], $profile->getStrictExemptCharacterIds());
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('security_logs', 0);
    }

    public static function invalidSelections(): array
    {
        return [
            'missing field' => [[]],
            'scalar list' => [['characters' => '1002']],
            'duplicate' => [['characters' => [1002, 1002]]],
            'zero' => [['characters' => [0]]],
            'negative' => [['characters' => [-1]]],
            'fractional' => [['characters' => [1002.5]]],
            'unknown' => [['characters' => [9999]]],
            'other account' => [['characters' => [2001]]],
        ];
    }

    public function testModalOnlyRendersTheExemptionFormForSuperusers(): void
    {
        $this->app['view']->addNamespace('seat-connector', __DIR__.'/../src/resources/views');
        $this->actingAs($this->account('Administrator', true));
        $html = view('seat-connector::users.partials.edit-modal')->render();
        $this->assertStringContainsString('id="strict-exemptions-form"', $html);
        $this->actingAs($this->account('Connector staff'));
        $html = view('seat-connector::users.partials.edit-modal')->render();
        $this->assertStringNotContainsString('id="strict-exemptions-form"', $html);
    }

    public function testEditButtonUsesTheMappingIdAndEscapesTheNickname(): void
    {
        $profile = $this->spud();
        $profile->name_override = '" onmouseover="alert(1)';
        $this->app['view']->addNamespace('seat-connector', __DIR__.'/../src/resources/views');
        $this->actingAs($this->account('Administrator', true));
        $html = view('seat-connector::users.partials.actions-button', ['row' => $profile])->render();
        $this->assertStringContainsString('data-connector-type="discord"', $html);
        $this->assertStringContainsString('/seat-connector/users/'.$profile->id.'/strict-exemptions', $html);
        $this->assertStringContainsString('&quot; onmouseover=&quot;alert(1)', $html);
        $this->assertStringNotContainsString('data-name-override="" onmouseover=', $html);
    }

    public function testMappingIdIsNotUsedAsTheSeatSettingsOwner(): void
    {
        $placeholder = $this->account('Placeholder');
        $this->identity($placeholder);
        $this->identity($placeholder, 'teamspeak');
        $profile = $this->spud();
        $this->assertNotEquals($profile->id, $profile->user_id);
        $this->actingAs($this->account('Administrator', true));
        $this->putJson($this->endpoint($profile), ['characters' => [1002]])->assertOk();
        $this->assertDatabaseHas('seat_connector_strict_exemptions', ['user_id' => $profile->user_id, 'character_id' => 1002]);
        $this->assertDatabaseMissing('seat_connector_strict_exemptions', ['user_id' => $profile->id]);
    }

    public function testAccountWithoutAnyTokensCannotAuthorizeInEitherMode(): void
    {
        $profile = $this->identity($this->account());
        $this->grantSquadRole($profile);
        $this->assertFalse($profile->areAllTokensValid());
        $this->assertSame([], $profile->allowedSets());
        setting(['seat-connector.strict', false], true);
        $this->assertSame([], $profile->allowedSets());
    }

    public function testSavedExemptionsRemainVisibleIfQueuingTheSyncFails(): void
    {
        $profile = $this->spud();
        $this->actingAs($this->account('Administrator', true));
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        $response = $this->putJson($this->endpoint($profile), ['characters' => [1002]])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertStringContainsString('could not be queued', $response->json('message'));
        $this->assertSame([1002], $profile->getStrictExemptCharacterIds());
        $this->assertTrue($profile->areAllTokensValid());
    }

    public function testNonDiscordAndMissingIdentitiesReturnNotFound(): void
    {
        $profile = $this->spud();
        $other = $this->identity($profile->user, 'teamspeak');
        $this->actingAs($this->account('Administrator', true));
        foreach ([$this->endpoint($other), '/seat-connector/users/999999/strict-exemptions'] as $url) {
            $this->getJson($url)->assertNotFound();
            $this->putJson($url, ['characters' => []])->assertNotFound();
        }
        Queue::assertNothingPushed();
    }

    public function testExcludedRolesStillSkipBothGrantsAndDrops(): void
    {
        foreach (['allowed' => false, 'denied' => false, 'excluded' => true] as $id => $excluded) {
            Set::create(['connector_type' => 'discord', 'connector_id' => $id, 'name' => $id, 'is_excluded' => $excluded]);
        }
        $profile = $this->getMockBuilder(User::class)->onlyMethods(['allowedSets'])->getMock();
        $profile->connector_type = 'discord';
        $profile->method('allowedSets')->willReturn(['allowed', 'excluded']);
        $sets = [];
        foreach (['allowed', 'denied', 'excluded'] as $id) {
            $set = $this->createMock(ISet::class);
            $set->method('getId')->willReturn($id);
            $sets[] = $set;
        }
        $policy = new PolicyHarness();
        $this->assertSame(['denied'], $policy->drops($profile, $sets)->all());
        $this->assertSame(['allowed'], $policy->adds($profile, [])->all());
        $policy->terminator = true;
        $this->assertSame(['allowed', 'denied'], $policy->drops($profile, $sets)->all());
        $this->assertSame([], $policy->adds($profile, [])->all());
    }


    public function testMigrationImportsOwnedActiveAndRevokedCharactersWithoutChangingLegacyRows(): void
    {
        $profile = $this->spud();
        $this->character($profile->user, 6000000001, false);
        $other = $this->account('Other');
        $this->character($other, 2001, false);
        Profile::set('seat-connector.discord_strict_exempt_characters',
            [1001, 1002, 6000000001, 1002, 2001, 9999], $profile->user_id);
        $before = DB::table('user_settings')->orderBy('id')->get()->toJson();
        $this->legacyMigration()->up();
        $this->assertSame([1001, 1002, 6000000001], $profile->getStrictExemptCharacterIds());
        $this->assertDatabaseCount('seat_connector_strict_exemptions', 3);
        $this->assertSame($before, DB::table('user_settings')->orderBy('id')->get()->toJson());
    }

    public function testRollbackExportsCurrentSelectionsAndInvalidatesOldSettingsCache(): void
    {
        $profile = $this->spud();
        Profile::set('seat-connector.discord_strict_exempt_characters', [1002], $profile->user_id);
        $this->legacyMigration()->up();
        $this->exempt($profile, [1001]);
        $this->assertSame([1002], Profile::get('seat-connector.discord_strict_exempt_characters', $profile->user_id));
        $this->legacyMigration()->down();
        $this->schemaMigration()->down();
        $this->assertFalse(Schema::hasTable('seat_connector_strict_exemptions'));
        $this->assertSame([1001], Profile::get('seat-connector.discord_strict_exempt_characters', $profile->user_id));
        $this->schemaMigration()->up();
        $this->legacyMigration()->up();
        $this->assertSame([1001], $profile->getStrictExemptCharacterIds());
    }

    public function testRollbackOfClearedFlagsDoesNotResurrectOldExemptions(): void
    {
        $profile = $this->spud();
        Profile::set('seat-connector.discord_strict_exempt_characters', [1002], $profile->user_id);
        $this->legacyMigration()->up();
        $this->exempt($profile, []);
        $this->legacyMigration()->down();
        $this->schemaMigration()->down();
        $this->assertSame([], Profile::get('seat-connector.discord_strict_exempt_characters', $profile->user_id));
        $this->schemaMigration()->up();
        $this->legacyMigration()->up();
        $this->assertSame([], $profile->getStrictExemptCharacterIds());
        $this->assertFalse($profile->areAllTokensValid());
    }

    public function testRollbackCreatesLegacyRowForNewExemptions(): void
    {
        $profile = $this->spud();
        $this->assertNull(Profile::get('seat-connector.discord_strict_exempt_characters', $profile->user_id));
        $this->exempt($profile, [1002]);
        $this->legacyMigration()->down();
        $this->schemaMigration()->down();
        $this->assertSame([1002], Profile::get('seat-connector.discord_strict_exempt_characters', $profile->user_id));
    }

    public function testLegacySelectionsAreIgnoredByTheNewRuntime(): void
    {
        $profile = $this->spud();
        Profile::set('seat-connector.discord_strict_exempt_characters', [1002], $profile->user_id);
        $this->assertSame([], $profile->getStrictExemptCharacterIds());
        $this->assertFalse($profile->areAllTokensValid());
    }

    public function testExemptionsSurviveSoftDeletionButCascadeOnTokenOrUserDeletion(): void
    {
        $profile = $this->spud();
        $this->exempt($profile, [1001, 1002]);
        DB::table('refresh_tokens')->where('character_id', 1001)->update(['deleted_at' => now()]);
        $this->assertDatabaseCount('seat_connector_strict_exemptions', 2);
        DB::table('refresh_tokens')->where('character_id', 1001)->delete();
        $this->assertDatabaseMissing('seat_connector_strict_exemptions', ['character_id' => 1001]);
        $this->assertDatabaseHas('seat_connector_strict_exemptions', ['character_id' => 1002]);
        DB::table('users')->where('id', $profile->user_id)->delete();
        $this->assertDatabaseCount('seat_connector_strict_exemptions', 0);
        $this->assertDatabaseCount('character_infos', 2);
    }

    public function testTransferredCharacterDoesNotCarryItsPreviousOwnersExemption(): void
    {
        $profile = $this->spud();
        $this->exempt($profile, [1002]);
        $other = $this->identity($this->account('Other'));
        $this->character($other->user, 2001, true);
        DB::table('refresh_tokens')->where('character_id', 1002)->update(['user_id' => $other->user_id]);
        $this->assertSame([], $profile->getStrictExemptCharacterIds());
        $this->assertSame([], $other->getStrictExemptCharacterIds());
        $this->assertFalse($other->areAllTokensValid());
    }

    public function testDatabaseRejectsDuplicatePairsAndMissingParents(): void
    {
        $profile = $this->spud();
        $this->exempt($profile, [1002]);
        foreach ([[$profile->user_id, 1002], [999999, 1002], [$profile->user_id, 999999]] as [$userId, $characterId]) {
            try {
                DB::table('seat_connector_strict_exemptions')->insert([
                    'user_id' => $userId, 'character_id' => $characterId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->fail('Expected an integrity constraint violation.');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertSame('23000', $exception->errorInfo[0]);
            }
        }
        $this->assertDatabaseCount('seat_connector_strict_exemptions', 1);
    }

    /** @dataProvider malformedLegacyValues */
    public function testMigrationRejectsMalformedLegacyLists(string $value): void
    {
        $profile = $this->spud();
        DB::table('user_settings')->insert([
            'user_id' => $profile->user_id, 'name' => 'seat-connector.discord_strict_exempt_characters',
            'value' => $value, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->legacyMigration()->up();
        $this->assertDatabaseCount('seat_connector_strict_exemptions', 0);
        $this->assertFalse($profile->areAllTokensValid());
    }

    public static function malformedLegacyValues(): array
    {
        return [
            'invalid JSON' => ['not-json'], 'null' => ['null'], 'object' => ['{"1002":true}'],
            'numeric string' => ['["1002"]'], 'mixed list' => ['[1002,"wrong"]'],
            'zero' => ['[0]'], 'negative' => ['[-1]'],
        ];
    }

    public function testMigrationUsesOnlyTheFirstLegacyRowAndSkipsMissingOwners(): void
    {
        $profile = $this->spud();
        foreach ([[$profile->user_id, '[]'], [$profile->user_id, '[1002]'], [999999, '[1002]']] as [$userId, $value]) {
            DB::table('user_settings')->insert([
                'user_id' => $userId, 'name' => 'seat-connector.discord_strict_exempt_characters',
                'value' => $value, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->legacyMigration()->up();
        $this->assertDatabaseCount('seat_connector_strict_exemptions', 0);
    }

    public function testSavingExemptionsAndAuditIsAtomic(): void
    {
        $profile = $this->spud();
        $this->exempt($profile, [1002]);
        $this->actingAs($this->account('Administrator', true));
        Event::listen('security.log', function (): void { throw new \RuntimeException('Audit unavailable'); });
        $this->putJson($this->endpoint($profile), ['characters' => [1001]])->assertStatus(500);
        $this->assertSame([1002], $profile->getStrictExemptCharacterIds());
        $this->assertDatabaseCount('security_logs', 0);
        Queue::assertNothingPushed();
    }

    public function testArtisanRunsAndRollsBackBothPackageMigrations(): void
    {
        $profile = $this->spud();
        Profile::set('seat-connector.discord_strict_exempt_characters', [1002], $profile->user_id);
        $this->schemaMigration()->down();
        $paths = [
            realpath(__DIR__.'/../src/database/migrations/2026_10_14_000000_create_seat_connector_strict_exemptions_table.php'),
            realpath(__DIR__.'/../src/database/migrations/2026_10_14_000001_import_legacy_strict_character_exemptions.php'),
        ];
        $this->artisan('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true])->assertExitCode(0);
        $this->assertSame([1002], $profile->getStrictExemptCharacterIds());
        $this->assertDatabaseCount('migrations', 2);
        $this->artisan('migrate:rollback', [
            '--path' => $paths, '--realpath' => true, '--step' => 2, '--force' => true,
        ])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('seat_connector_strict_exemptions'));
        $this->assertSame([1002], Profile::get('seat-connector.discord_strict_exempt_characters', $profile->user_id));
    }

    private function schemaMigration(): \Illuminate\Database\Migrations\Migration
    {
        return require __DIR__.'/../src/database/migrations/2026_10_14_000000_create_seat_connector_strict_exemptions_table.php';
    }

    private function legacyMigration(): \Illuminate\Database\Migrations\Migration
    {
        return require __DIR__.'/../src/database/migrations/2026_10_14_000001_import_legacy_strict_character_exemptions.php';
    }

    private function account(string $name = 'Spud', bool $admin = false): SeatUser
    {
        return SeatUser::create(['name' => $name, 'active' => true, 'admin' => $admin, 'main_character_id' => 1001]);
    }

    private function identity(SeatUser $user, string $driver = 'discord'): User
    {
        return User::create([
            'connector_type' => $driver, 'connector_id' => 'identity-'.$user->id.'-'.$driver,
            'connector_name' => $user->name, 'user_id' => $user->id,
            'unique_id' => 'test-'.$user->id.'-'.$driver,
        ]);
    }

    private function character(SeatUser $user, int $id, bool $active): void
    {
        CharacterInfo::create(['character_id' => $id, 'name' => 'Character '.$id]);
        RefreshToken::create([
            'character_id' => $id, 'user_id' => $user->id, 'version' => 2,
            'refresh_token' => 'test-refresh-secret', 'token' => 'test-access-secret',
            'expires_on' => now()->addHour(), 'scopes' => ['publicData'],
            'character_owner_hash' => 'test-owner',
        ]);
        if (! $active) {
            DB::table('refresh_tokens')->where('character_id', $id)->update(['deleted_at' => now()]);
        }
    }

    private function spud(): User
    {
        $user = $this->account();
        $this->character($user, 1001, true);
        $this->character($user, 1002, false);
        return $this->identity($user);
    }

    private function exempt(User $profile, array $ids): void
    {
        StrictExemption::where('user_id', $profile->user_id)->delete();
        foreach ($ids as $id) {
            StrictExemption::create(['user_id' => $profile->user_id, 'character_id' => $id]);
        }
    }

    private function grantSquadRole(User $profile): void
    {
        $squad = Squad::create(['name' => 'Tournament', 'type' => 'manual', 'filters' => '{}']);
        $profile->user->squads()->attach($squad->id);
        $set = Set::create(['connector_type' => 'discord', 'connector_id' => 'tournament', 'name' => 'Tournament']);
        $set->squads()->attach($squad->id);
    }

    private function endpoint(User $profile): string
    {
        return '/seat-connector/users/'.$profile->id.'/strict-exemptions';
    }

    /** Narrow schema fixtures cover the actual package relations without app-wide migrations. */
    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id'); $table->string('name')->unique(); $table->boolean('active')->default(true);
            $table->boolean('admin')->default(false); $table->bigInteger('main_character_id')->nullable();
            $table->rememberToken(); $table->timestamps();
        });
        Schema::create('character_infos', function (Blueprint $table): void {
            $table->bigInteger('character_id')->primary(); $table->string('name'); $table->timestamps();
        });
        Schema::create('character_affiliations', function (Blueprint $table): void {
            $table->bigInteger('character_id')->primary(); $table->bigInteger('corporation_id')->nullable();
            $table->bigInteger('alliance_id')->nullable(); $table->timestamps();
        });
        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->bigInteger('character_id')->primary(); $table->bigInteger('user_id');
            $table->integer('version')->default(2); $table->text('refresh_token'); $table->text('token')->nullable();
            $table->text('scopes'); $table->integer('scopes_profile')->nullable(); $table->timestamp('expires_on');
            $table->string('character_owner_hash'); $table->timestamps(); $table->softDeletes();
        });
        foreach (['global_settings', 'user_settings'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id(); $table->string('name'); $table->text('value');
                if ($name === 'user_settings') { $table->bigInteger('user_id'); }
                $table->timestamps();
            });
        }
        Schema::create('seat_connector_users', function (Blueprint $table): void {
            $table->id(); $table->string('connector_type'); $table->string('connector_id');
            $table->string('connector_name'); $table->string('name_override')->nullable();
            $table->bigInteger('user_id'); $table->string('unique_id'); $table->timestamps();
        });
        Schema::create('seat_connector_sets', function (Blueprint $table): void {
            $table->id(); $table->string('connector_type'); $table->string('connector_id'); $table->string('name');
            $table->boolean('is_public')->default(false); $table->boolean('is_excluded')->default(false);
        });
        Schema::create('seat_connector_set_entity', function (Blueprint $table): void {
            $table->bigInteger('set_id'); $table->bigInteger('entity_id'); $table->string('entity_type');
        });
        Schema::create('roles', function (Blueprint $table): void { $table->id(); $table->string('title')->nullable(); });
        Schema::create('role_user', function (Blueprint $table): void { $table->bigInteger('role_id'); $table->bigInteger('user_id'); });
        Schema::create('squads', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('type');
            $table->text('filters'); $table->text('description')->nullable(); $table->timestamps();
        });
        foreach (['squad_member', 'squad_moderator'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->bigInteger('squad_id'); $table->bigInteger('user_id'); $table->timestamps();
            });
        }
        Schema::create('corporation_infos', function (Blueprint $table): void {
            $table->bigInteger('corporation_id')->primary(); $table->string('name')->nullable();
        });
        Schema::create('alliances', function (Blueprint $table): void {
            $table->bigInteger('alliance_id')->primary(); $table->string('name')->nullable();
        });
        Schema::create('corporation_titles', function (Blueprint $table): void {
            $table->id(); $table->bigInteger('corporation_id')->nullable(); $table->string('name')->nullable();
        });
        Schema::create('character_info_corporation_title', function (Blueprint $table): void {
            $table->bigInteger('character_info_character_id'); $table->bigInteger('corporation_title_id'); $table->timestamps();
        });
        Schema::create('security_logs', function (Blueprint $table): void {
            $table->id(); $table->bigInteger('user_id')->nullable(); $table->text('message');
            $table->string('category')->nullable(); $table->timestamps();
        });
        $this->schemaMigration()->up();
    }
}

class PolicyHarness
{
    use ConnectorPolicyManagement {
        getDroppableSets as public drops;
        getGrantableSets as public adds;
    }

    public bool $terminator = false;
}
