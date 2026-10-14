# Discord Strict character exemptions

This feature builds on DeepwaterHooligans' `exclude-roles` branch at
`74039324a6df54cc3e6b56c9588441a60413d232`. It allows a SeAT superuser to exempt
approved linked characters from Discord Strict token checks without moving
characters, transferring data to another account, or deleting historical data.

## Behaviour

In **Connector > Users**, open the Discord user's **Edit** dialog. Superusers
see a list of linked characters with valid/invalid token status and an exemption
checkbox. Select the approved characters and click **Save exemptions**.
Nickname changes continue to use their separate Save button.

Strict access is permitted only when:

- the SeAT account is enabled;
- at least one linked, non-exempt character has an active token record; and
- every other non-exempt linked character has an active token record.

Exemptions are scoped to the **SeAT account and Discord**, rather than the
Discord mapping row ID. Exemptions do not apply to other accounts or connector drivers.
They are stored in the connector-owned **`seat_connector_strict_exemptions`**
table, with one row per exempt account/character pair:

| Column | Purpose |
| --- | --- |
| `id` | Exemption row ID |
| `user_id` | SeAT account (unsigned INT) |
| `character_id` | Linked character (signed BIGINT) |
| `created_at`, `updated_at` | Row timestamps |

A unique constraint prevents duplicate pairs. Foreign keys reference
`users.id` and `refresh_tokens.character_id`, matching the reported SeAT 5
column types. Hard-deleting a user or token removes related exemption rows;
revoking/soft-deleting a token preserves them. Reads also validate current
token ownership so transferred characters do not inherit the old account's
exemptions. Core SeAT tables are not extended.

An exemption changes the authentication requirement. It does not grant a role
by itself: existing user, role, squad, corporation and alliance mappings still
determine eligibility. It can restore **all otherwise eligible managed Discord
roles** for the account, rather than only a tournament role. Your existing
excluded Discord roles remain outside connector grants and removals.

Token status uses SeAT's existing active/soft-deleted token records. This
feature adds no EVE API calls, private scope requirements or token restoration.
Normal mode still permits revoked alts when another active token exists.
The previous ineffective `empty(Collection)` guard is corrected so an account
without any active token is denied in either mode.

## Permissions and updates

Only `global.superuser` can read or change exemptions. Ordinary users and staff
with only `seat-connector.security` cannot use the new endpoints. Submitted
character IDs must belong to the target account, including revoked token
records. Responses expose character names, IDs and status, never token values.

Replacing the selection and recording the target account, old/new selections
and actor in SeAT's security log happen in one database transaction. After
commit, saving queues the existing account-policy job on the high queue.
If queue submission fails, selections remain saved and the dialog states that
the next scheduled policy sync will apply them. Your configured worker and
scheduler determine when a queued or scheduled update reaches Discord.

Selections can be cleared with **Save exemptions**. An administrator can save
a selection that leaves no valid non-exempt character; that account will remain
blocked. Exemptions have no automatic expiry. They remain until an administrator
changes the list.

## Installing in Docker

The repository keeps the package name `warlof/seat-connector` so the installed
Discord driver can continue to depend on it. The database feature branch has a
`3.1.x-dev` branch alias; an inline alias can retain the existing `^3.0`
dependency contract.

In the persistent SeAT application's Composer configuration, point the existing
VCS repository entry for the connector at:

```text
https://github.com/Sussic/seat-connector.git
```

Use this package constraint for the feature branch:

```text
dev-feature/strict-exemptions-database as 3.1.2
```

Adapt the existing repository entry instead of leaving competing forks for the
same package. Update only this package and its necessary dependencies:

```sh
composer update warlof/seat-connector --with-dependencies
composer show warlof/seat-connector
```

Review and retain the resulting `composer.lock`, including the fork source URL
and approved commit reference. Apply this during your existing Docker image
build or persistent source workflow. Installing only inside a disposable
container will not preserve the change for the next rebuild.

This revision **requires migrations**. Back up the database and pause policy
workers and exemption editing while updating. Run from the updated SeAT code
inside its application container before enabling the new code across services:

```sh
php artisan migrate --force
php artisan migrate:status
```

If your existing Docker startup already runs migrations, confirm both new
package migrations completed before using the feature.

Use your existing Docker deployment procedure to run the same updated code in
the application, queue worker and scheduler containers. Clear compiled views
and route caches after updating the application and restart/recreate workers
with the updated image. Data volumes remain in place. Exact service names and deployment commands
depend on your existing compose configuration.

After installation, inspect the admin Edit dialog, save exemptions for the
approved account, check the security log and confirm the queued policy job
finishes. Verify the expected roles in Discord after the job completes.

## Package migrations and existing selections

The service provider already registers `src/database/migrations` with
`loadMigrationsFrom`, following
[SeAT's package database guidance](https://eveseat.github.io/docs/developer_guides/package_development/#database).

Run both migrations in order:

1. `2026_10_14_000000_create_seat_connector_strict_exemptions_table` creates the table.
2. `2026_10_14_000001_import_legacy_strict_character_exemptions` imports selections saved by the earlier settings-based version.

Schema and import are separate because MariaDB DDL implicitly commits.
Import accepts only positive integer lists, uses the first legacy row per
account, and intersects selections with currently owned token records,
including revoked ones. Malformed lists and foreign/missing ownership are
ignored. Legacy rows remain intact on upgrade, but the new runtime uses
only the dedicated table.

## Verification

The GitHub workflow installs `eveseat/web 5.0.35` and `eveseat/eveapi 5.0.37`,
checks PHP syntax and runs focused integration tests on PHP 8.1 and 8.3.
The same suite also runs against isolated MariaDB 10.11, including the actual
migration import/rollback, uniqueness, foreign keys, 64-bit character IDs,
delete cascades and transactional auditing. A separate Node/jsdom job executes the production modal script to verify safe
name rendering, load/save failures, cleared selections and stale response guards.

For local development in a separate checkout:

```sh
composer update --with eveseat/web:5.0.35 --with eveseat/eveapi:5.0.37
composer test
```

The tests use real Laravel routes, SeAT models and the actual package migrations
with temporary SQL fixtures and fake job dispatch. Test database cleanup is
restricted to in-memory SQLite or the isolated `connector_test` MariaDB database. They do not call EVE or Discord or use
the live SeAT database. They cover revoked tokens, account ownership, revoked
records without character sheets, stale loaded relations, permissions, cached
settings, logs, queued updates, and the existing excluded-role policy.

The isolated harness does not boot the full SeAT installation or prove live
Discord delivery. A staging check is still needed for the complete Docker
application/worker flow.

## Reverting to the earlier settings-based version

Pause policy workers and exemption editing. While the new package and its
migration files are still installed, inspect `migrate:status` and roll back
**both package migrations in reverse order**. The data rollback exports the
current selections into the legacy settings key, including empty lists for
cleared flags, and invalidates its cache. The schema rollback then removes
the dedicated table.

Inspect the migration batch/paths so unrelated application migrations are
not rolled back. Do not roll back or reapply only the data importer against
a retained table.

After rollback, restore the earlier Composer configuration/lockfile,
rebuild/recreate the code containers and clear compiled view/route caches.
The earlier feature version reads the exported current selections. Character
data and token associations remain intact.
