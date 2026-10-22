<?php

namespace App\Modules\Agri\Api;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\ApiClient;
use App\Modules\Agri\Models\ApiCall;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMembership;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Issuing and revoking farm API tokens.
 *
 * Each token acts as its own service user in the organisation: a user that cannot sign in (no known
 * password, nothing to reset), holding exactly the farm permissions its abilities need and reaching
 * exactly the farms it was given. So the API runs through the same tenant scope, row-level
 * security, farm access and audit as a person at a screen — and the audit names the integration,
 * not the administrator who made it. Nobody can issue more than they hold: the abilities must be
 * backed by the issuer's own permissions and the farms must be farms the issuer reaches.
 */
class ApiTokens
{
    public function __construct(private readonly FarmAccess $access) {}

    /**
     * @param  array{name: string, description?: ?string, abilities: list<string>, farm_ids?: ?list<string>, expires_in_days?: ?int, rate_per_minute?: ?int}  $data  farm_ids are farm uuids; null or empty is every farm
     * @return array{client: ApiClient, token: string}
     */
    public function issue(int $companyId, array $data, User $actor): array
    {
        $abilities = array_values(array_unique($data['abilities'] ?? []));
        if (! $abilities || array_diff($abilities, ApiAbilities::all())) {
            throw ValidationException::withMessages(['abilities' => 'Choose one or more of: '.implode(', ', ApiAbilities::all()).'.']);
        }
        $missing = array_filter(ApiAbilities::permissionsFor($abilities), fn ($p) => ! $actor->can($p));
        if ($missing) {
            throw ValidationException::withMessages(['abilities' => 'You cannot give a token more than you may do yourself (you lack '.implode(', ', $missing).').']);
        }

        $farmIds = null;
        if (! empty($data['farm_ids'])) {
            $farmIds = Farm::query()->whereIn('uuid', $data['farm_ids'])->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (count($farmIds) !== count(array_unique($data['farm_ids']))) {
                throw ValidationException::withMessages(['farm_ids' => 'One of those farms is not one you can reach.']);
            }
        } elseif (! $this->access->isUnrestricted($actor)) {
            throw ValidationException::withMessages(['farm_ids' => 'You reach only some farms, so the token must name the farms it is for.']);
        }

        $days = (int) ($data['expires_in_days'] ?? 90);
        if ($days < 1 || $days > (int) config('agri.api.token_days', 365)) {
            throw ValidationException::withMessages(['expires_in_days' => 'A token lives between 1 and '.config('agri.api.token_days', 365).' days.']);
        }
        $expires = now()->addDays($days);
        $rate = max(10, min(1000, (int) ($data['rate_per_minute'] ?? config('agri.api.per_minute', 120))));

        return DB::transaction(function () use ($companyId, $data, $actor, $abilities, $farmIds, $expires, $rate) {
            $user = $this->serviceUser($companyId, (string) $data['name'], $abilities, $farmIds, $actor);
            $token = $user->createToken('agri-api:'.mb_strimwidth((string) $data['name'], 0, 80), $abilities, $expires);
            $client = ApiClient::create(['company_id' => $companyId, 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'service_user_id' => $user->id, 'token_id' => $token->accessToken->id, 'token_hint' => mb_substr($token->plainTextToken, 0, 8),
                'abilities' => $abilities, 'farm_ids' => $farmIds, 'rate_per_minute' => $rate, 'expires_at' => $expires, 'created_by' => $actor->id]);

            return ['client' => $client, 'token' => $token->plainTextToken];
        });
    }

    /** The token stops working at once; the client, its service user and its call log stay for the audit. */
    public function revoke(ApiClient $client, User $actor): ApiClient
    {
        return DB::transaction(function () use ($client, $actor) {
            if ($client->token_id) {
                DB::table('personal_access_tokens')->where('id', $client->token_id)->delete();
            }
            User::withoutGlobalScopes()->whereKey($client->service_user_id)->update(['status' => 0]);
            $client->update(['token_id' => null, 'revoked_at' => now(), 'revoked_by' => $actor->id]);

            return $client->fresh();
        });
    }

    /** Calls in the log, newest first, for the administrator's screen. */
    public function calls(int $companyId, ?int $clientId = null, int $limit = 200)
    {
        return ApiCall::withoutGlobalScopes()->where('company_id', $companyId)->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderByDesc('id')->limit($limit)->get();
    }

    public function prune(int $companyId): int
    {
        return ApiCall::withoutGlobalScopes()->where('company_id', $companyId)->where('created_at', '<', now()->subDays((int) config('agri.api.log_days', 90)))->delete()
            + DB::table('agri_api_idempotency')->where('company_id', $companyId)->where('created_at', '<', now()->subHours((int) config('agri.api.idempotency_hours', 24)))->delete();
    }

    /** A user that is the integration: no usable password, the abilities' permissions, the token's farms. */
    private function serviceUser(int $companyId, string $name, array $abilities, ?array $farmIds, User $actor): User
    {
        $slug = Str::slug(mb_strimwidth($name, 0, 40, '')) ?: 'client';
        $user = new User(['uuid' => (string) Str::uuid(), 'email' => "api.{$slug}.".Str::lower(Str::random(8)).'@agri-api.invalid',
            'password' => Hash::make(Str::random(64)), 'type' => 1, 'status' => 1]);
        $user->company_id = $companyId;
        $user->save();

        $permissions = ApiAbilities::permissionsFor($abilities);
        if ($farmIds === null) {
            $permissions[] = R::ALL_FARMS;
        }
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        $role = array_intersect($abilities, ApiAbilities::WRITES) ? 'manager' : 'viewer';
        foreach ($farmIds ?? [] as $farmId) {
            FarmMembership::withoutGlobalScopes()->create(['company_id' => $companyId, 'farm_id' => $farmId, 'user_id' => $user->id,
                'role' => $role, 'status' => FarmMembership::ACTIVE, 'granted_by' => $actor->id]);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->access->flush();

        return $user->fresh();
    }
}
