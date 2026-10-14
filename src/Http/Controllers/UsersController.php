<?php

/*
 * This file is part of seat-connector and provides user synchronization between both SeAT and third party platform
 *
 * Copyright (C) 2019 to 2022 Loïc Leuilliot <loic.leuilliot@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Warlof\Seat\Connector\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Illuminate\Support\Facades\DB;
use Seat\Web\Models\User as SeatUser;
use Seat\Web\Http\Controllers\Controller;
use Warlof\Seat\Connector\Jobs\NotifyDriver;
use Warlof\Seat\Connector\Exceptions\DriverException;
use Warlof\Seat\Connector\Exceptions\DriverSettingsException;
use Warlof\Seat\Connector\Exceptions\InvalidDriverIdentityException;
use Warlof\Seat\Connector\Http\DataTables\Scopes\UserDataTableScope;
use Warlof\Seat\Connector\Http\DataTables\UserMappingDataTable;
use Warlof\Seat\Connector\Models\User;
use Warlof\Seat\Connector\Models\StrictExemption;

/**
 * Class UsersController.
 */
class UsersController extends Controller
{
    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(UserMappingDataTable $datatable)
    {
        // retrieve all registered SeAT Connector drivers
        $available_drivers = config('seat-connector.drivers', []);

        // init the driver using either the query parameter or the first available driver
        $driver = request()->query('driver') ?: Arr::get(Arr::last($available_drivers), 'name');

        return $datatable
            ->addScope(new UserDataTableScope($driver))
            ->render('seat-connector::users.list');
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(int $id)
    {
        // attempt to retrieve requested identity
        $identity = User::find($id);

        if (is_null($identity)) {
            return redirect()->back()
                ->with('error', 'An error occurred attempting to delete the user mapping. Identity is not found.');
        }

        // load driver instance
        $config_key = sprintf('seat-connector.drivers.%s.client', $identity->connector_type);
        $client = config($config_key);

        if (is_null($config_key) || ! class_exists($client)) {
            return redirect()->back()
                ->with('error', sprintf('The client for driver %s is missing.', $identity->connector_type));
        }

        try {
            $instance = $client::getInstance();

            // retrieve platform user
            $user = $instance->getUser($identity->connector_id);

            // request platform user sets
            $sets = $user->getSets();

            // drop all sets
            foreach ($sets as $set) {
                $user->removeSet($set);
            }

            // remove user identity
            $identity->delete();
        } catch (InvalidDriverIdentityException|DriverSettingsException) {
            $identity->delete();
        } catch (DriverException $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }

        return redirect()->back()
            ->with('success', 'Identity has been successfully dropped.');
    }

    public function edit(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'name_override' => 'nullable|string',
            'name_override_enable'=>'nullable'
        ]);

        // attempt to retrieve requested identity
        $identity = User::find($request->user_id);

        if (is_null($identity)) {
            return redirect()->back()
                ->with('error', 'An error occurred while attempting to edit a user mapping. User not found.');
        }

        $name_override_enabled = $request->name_override_enable !== null; // checkboxes are annoying
        $name_override = $request->name_override;
        $name_override_valid = $name_override !== null && strlen($name_override)>0;

        if($name_override_enabled && !$name_override_valid) {
            return redirect()->back()
                ->with('error', 'An error occurred while attempting to edit a user mapping: Invalid name override.');
        }

        if($name_override_enabled) {
            $identity->name_override = $name_override;
        } else {
            $identity->name_override = null;
        }

        $identity->save();

        return redirect()->back()
            ->with('success', 'Successfully updated name override!');
    }

    /**
     * Return only public display fields; token values are never serialized.
     */
    public function getStrictExemptions(int $id)
    {
        $identity = User::with('user')->findOrFail($id);
        abort_unless($identity->connector_type === 'discord' && $identity->user, 404);

        $tokens = $identity->user->refresh_tokens()->withTrashed()
            ->get(['character_id', 'deleted_at']);
        $names = CharacterInfo::whereIn('character_id', $tokens->pluck('character_id'))
            ->pluck('name', 'character_id');
        $exemptions = $identity->getStrictExemptCharacterIds();

        $characters = $tokens->map(fn($token): array => [
            'id' => (int) $token->character_id,
            'name' => $names->get($token->character_id) ?? sprintf('Character %d', $token->character_id),
            'has_token' => ! $token->trashed(),
            'exempt' => in_array((int) $token->character_id, $exemptions, true),
        ])->sortBy('name')->values();

        return response()->json([
            'characters' => $characters,
            'update_url' => route('seat-connector.users.strict-exemptions.update', ['id' => $identity->id]),
        ]);
    }

    public function updateStrictExemptions(Request $request, int $id)
    {
        $identity = User::with('user')->findOrFail($id);
        abort_unless($identity->connector_type === 'discord' && $identity->user, 404);

        $validated = $request->validate([
            'characters' => 'present|array',
            'characters.*' => 'required|integer|min:1|distinct',
        ]);

        $ids = array_map('intval', $validated['characters']);
        sort($ids, SORT_NUMERIC);

        DB::transaction(function () use ($identity, $ids): void {
            // Serialize edits to this account; validate against current token ownership.
            SeatUser::whereKey($identity->user_id)->lockForUpdate()->firstOrFail();
            $linked_ids = $identity->user->refresh_tokens()->withTrashed()->lockForUpdate()
                ->pluck('character_id')->map(fn($character_id): int => (int) $character_id)->all();

            if (array_diff($ids, $linked_ids) !== []) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'characters' => 'Every exempt character must belong to this SeAT account.',
                ]);
            }

            $previous = $identity->getStrictExemptCharacterIds();
            StrictExemption::where('user_id', $identity->user_id)->delete();
            foreach ($ids as $character_id) {
                StrictExemption::create(['user_id' => $identity->user_id, 'character_id' => $character_id]);
            }

            event('security.log', [
                sprintf('Discord Strict exemptions for SeAT user %d (%s) changed from [%s] to [%s].',
                    $identity->user_id, $identity->user->name, implode(', ', $previous), implode(', ', $ids)),
                'connector strict exemptions',
            ]);
        });

        $message = 'Exemptions saved. A role update has been queued. At least one valid, non-exempt character is still required.';

        try {
            NotifyDriver::dispatch($identity->user)->onQueue('high');
        } catch (\Exception $e) {
            report($e);
            $message = 'Exemptions saved. The role update could not be queued; the next scheduled connector sync will apply them.';
        }

        return response()->json(['success' => true, 'message' => $message]);
    }

}
