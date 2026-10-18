<?php

namespace App\Core\Auth\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SCIM 2.0 user provisioning (RFC 7644, §9.1).
 *
 * This is the half of SSO that actually matters for safety. SSO decides who can sign in *today*;
 * SCIM is how the employer's directory tells us someone has left. Without it, an account persists
 * until a human remembers to remove it — and the accounts that get forgotten are the ones held by
 * people who left abruptly, which is exactly the population you most want removed.
 *
 * Written to the shape Entra ID and Okta actually send, which is a subset of the specification:
 * list with an `eq` filter on `userName`, create, replace, patch `active`, and delete. Providers
 * probe with a filtered list before creating, so that path has to work or provisioning duplicates
 * every user on every sync.
 *
 * **Deactivation, never deletion.** A DELETE from a directory removes the person's access and
 * nothing else. Their name is on incident reports, permit approvals and investigation sign-offs, and
 * those records must continue to say who did the work — an audit trail that loses its actors when
 * somebody resigns is not an audit trail. So DELETE marks the account deprovisioned, which stops
 * every login path, and leaves the history intact.
 */
class ScimController extends Controller
{
    private const USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';

    private const LIST_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    private const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';

    /** `GET /scim/v2/Users?filter=userName eq "a@b.com"` */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $query = DB::table('users')->where('company_id', $companyId)->whereNull('deleted_at');

        // Providers probe with this before every create. Without it they would never find the
        // existing account and would duplicate the whole directory on each sync.
        if ($filter = $request->query('filter')) {
            if (preg_match('/userName\s+eq\s+"([^"]+)"/i', (string) $filter, $matches)) {
                $query->whereRaw('lower(email) = ?', [Str::lower($matches[1])]);
            } elseif (preg_match('/externalId\s+eq\s+"([^"]+)"/i', (string) $filter, $matches)) {
                $query->where('external_id', $matches[1]);
            } else {
                return $this->error(400, 'Only eq filters on userName or externalId are supported.',
                    'invalidFilter');
            }
        }

        $total = (clone $query)->count();

        // SCIM pages are 1-indexed, unlike everything else.
        $startIndex = max(1, (int) $request->query('startIndex', 1));
        $count = min(200, max(1, (int) $request->query('count', 100)));

        $users = $query->orderBy('id')->skip($startIndex - 1)->take($count)
            ->get(['id', 'uuid', 'email', 'external_id', 'status', 'deprovisioned_at',
                'created_at', 'updated_at']);

        return $this->scim([
            'schemas' => [self::LIST_SCHEMA],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => $users->count(),
            'Resources' => $users->map(fn ($u) => $this->represent($u, $companyId))->all(),
        ]);
    }

    /** `GET /scim/v2/Users/{id}` */
    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $user = $this->find($companyId, $id);

        return $user === null
            ? $this->error(404, "No user with id {$id}.", 'notFound')
            : $this->scim($this->represent($user, $companyId));
    }

    /** `POST /scim/v2/Users` */
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $email = Str::lower((string) ($request->input('userName') ?? ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error(400, 'userName must be an email address.', 'invalidValue');
        }

        $existing = DB::table('users')->where('company_id', $companyId)
            ->whereRaw('lower(email) = ?', [$email])->first(['id']);

        if ($existing !== null) {
            // 409 is what the specification requires, and providers rely on it to fall back to a
            // PATCH rather than retrying the create forever.
            return $this->error(409, 'A user with this userName already exists.', 'uniqueness');
        }

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => $email,
            // Unusable by design: this account exists because a directory says so, and it should
            // not become a password login if SSO is ever switched off.
            'password' => bcrypt(Str::random(64)),
            'company_id' => $companyId,
            'status' => $request->boolean('active', true) ? 1 : 0,
            'external_id' => $request->input('externalId'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->syncPerson($companyId, $userId, $request, $email);

        return $this->scim($this->represent($this->find($companyId, (string) $userId), $companyId), 201);
    }

    /**
     * `PATCH /scim/v2/Users/{id}`
     *
     * The operation that matters: `active: false` is how a directory says somebody has left.
     */
    public function patch(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $user = $this->find($companyId, $id);

        if ($user === null) {
            return $this->error(404, "No user with id {$id}.", 'notFound');
        }

        $updates = [];

        foreach ((array) $request->input('Operations', []) as $operation) {
            $path = Str::lower((string) ($operation['path'] ?? ''));
            $value = $operation['value'] ?? null;

            // Entra sends {path: "active", value: false}; Okta sends {value: {active: false}}.
            // Handling only one of them means provisioning works for half the customers.
            if ($path === 'active') {
                $updates['active'] = filter_var(
                    is_array($value) ? ($value['active'] ?? false) : $value,
                    FILTER_VALIDATE_BOOLEAN
                );
            } elseif ($path === '' && is_array($value) && array_key_exists('active', $value)) {
                $updates['active'] = filter_var($value['active'], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (array_key_exists('active', $updates)) {
            $this->setActive($user->id, $updates['active']);
        }

        return $this->scim($this->represent($this->find($companyId, $id), $companyId));
    }

    /** `PUT /scim/v2/Users/{id}` */
    public function replace(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $user = $this->find($companyId, $id);

        if ($user === null) {
            return $this->error(404, "No user with id {$id}.", 'notFound');
        }

        $this->setActive($user->id, $request->boolean('active', true));

        if ($externalId = $request->input('externalId')) {
            DB::table('users')->where('id', $user->id)
                ->update(['external_id' => $externalId, 'updated_at' => now()]);
        }

        $this->syncPerson($companyId, (int) $user->id, $request, $user->email);

        return $this->scim($this->represent($this->find($companyId, $id), $companyId));
    }

    /**
     * `DELETE /scim/v2/Users/{id}`
     *
     * Deactivates. See the class note: the person's name is on permit approvals and investigation
     * sign-offs, and an audit trail that loses its actors is not one.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $user = $this->find($companyId, $id);

        if ($user === null) {
            return $this->error(404, "No user with id {$id}.", 'notFound');
        }

        $this->setActive($user->id, false);

        return response()->json(null, 204);
    }

    /** Both directions, so a rehire is a directory operation rather than a support ticket. */
    private function setActive(int $userId, bool $active): void
    {
        DB::table('users')->where('id', $userId)->update([
            'status' => $active ? 1 : 0,
            'deprovisioned_at' => $active ? null : now(),
            'updated_at' => now(),
        ]);
    }

    private function syncPerson(int $companyId, int $userId, Request $request, string $email): void
    {
        if (! Schema::hasTable('hr_people')) {
            return;
        }

        $given = $request->input('name.givenName') ?: Str::before($email, '@');
        $family = $request->input('name.familyName') ?: '';

        $exists = DB::table('hr_people')->where('user_id', $userId)->exists();

        if ($exists) {
            DB::table('hr_people')->where('user_id', $userId)->update([
                'first_name' => $given, 'surname' => $family, 'updated_at' => now(),
            ]);

            return;
        }

        DB::table('hr_people')->insert([
            'company_id' => $companyId, 'user_id' => $userId,
            'first_name' => $given, 'surname' => $family,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function find(int $companyId, string $id): ?object
    {
        // Matched on exactly one column. Postgres refuses to compare a uuid column against a
        // numeric string at all, so an `orWhere` across both types fails the query outright rather
        // than simply not matching.
        return DB::table('users')->where('company_id', $companyId)->whereNull('deleted_at')
            ->when(Str::isUuid($id),
                fn ($q) => $q->where('uuid', $id),
                fn ($q) => $q->where('id', is_numeric($id) ? (int) $id : 0))
            ->first(['id', 'uuid', 'email', 'external_id', 'status', 'deprovisioned_at',
                'created_at', 'updated_at']);
    }

    /** @return array<string,mixed> */
    private function represent(object $user, int $companyId): array
    {
        $person = Schema::hasTable('hr_people')
            ? DB::table('hr_people')->where('user_id', $user->id)
                ->first(['first_name', 'surname'])
            : null;

        return [
            'schemas' => [self::USER_SCHEMA],
            // The uuid, not the autoincrement id: the directory stores this and it should not
            // expose how many users the platform has.
            'id' => $user->uuid,
            'externalId' => $user->external_id,
            'userName' => $user->email,
            'name' => [
                'givenName' => $person->first_name ?? null,
                'familyName' => $person->surname ?? null,
            ],
            'emails' => [['value' => $user->email, 'primary' => true, 'type' => 'work']],
            'active' => $user->deprovisioned_at === null && (int) $user->status === 1,
            'meta' => [
                'resourceType' => 'User',
                'created' => $user->created_at,
                'lastModified' => $user->updated_at,
                'location' => url("/scim/v2/Users/{$user->uuid}"),
            ],
        ];
    }

    private function companyId(Request $request): int
    {
        // Set by the SCIM token middleware, which is the only way into these routes.
        return (int) $request->attributes->get('scim_company_id');
    }

    private function scim(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Content-Type' => 'application/scim+json']);
    }

    private function error(int $status, string $detail, string $type): JsonResponse
    {
        return response()->json([
            'schemas' => [self::ERROR_SCHEMA],
            'status' => (string) $status,
            'scimType' => $type,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/scim+json']);
    }
}
