<?php

namespace App\Modules\Operations\Sheq\Support;

use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who a controlled document is for, asked in both directions.
 *
 * The library asks "does this document apply to me". The register asks the inverse — "who is
 * expected to have acknowledged this, and who has not". They are the same rule read from opposite
 * ends, and two implementations of it would drift: one would eventually show a procedure to a
 * reader the other did not count as owing an acknowledgement, and the acknowledgement percentage
 * on the register would stop meaning anything.
 *
 * So the rule lives here once:
 *
 * - **Nothing recorded means everybody.** Every document written before `applies_to` was honoured
 *   has a null audience, and the safe reading of "nobody said" for a safety procedure is "everyone",
 *   not "no one".
 * - **ROLES** means the named roles, matched on role name.
 * - **SITE** means the people at the document's site — and a site document with no site set
 *   reaches everybody, because restricting to a site nobody is at hides it from all.
 *
 * The site side rests on a schema trap worth stating: `company_unit_user_pivots.company_id` holds
 * a *unit* id, not a company id. Read as a company it matches every site in the tenant, which for
 * a restricted document means showing it to everybody — a failure that looks exactly like the
 * feature working.
 */
class DocumentAudience
{
    /**
     * Does this document apply to a reader with these roles, at these sites?
     *
     * @param  list<string>  $roles
     * @param  list<int>  $siteIds
     */
    public static function applies(?string $appliesTo, ?int $documentSiteId, array $roles, array $siteIds): bool
    {
        $audience = json_decode((string) $appliesTo, true);

        if (! is_array($audience) || ($audience['mode'] ?? 'EVERYONE') === 'EVERYONE') {
            return true;
        }

        return match ($audience['mode']) {
            'ROLES' => array_intersect($roles, array_map('strval', $audience['roles'] ?? [])) !== [],
            'SITE' => $documentSiteId === null || in_array((int) $documentSiteId, $siteIds, true),
            default => true,
        };
    }

    /**
     * The hr_person ids expected to acknowledge this document.
     *
     * Only people with a user account: acknowledging is an act a person performs while signed in,
     * so counting somebody who cannot sign in as outstanding would make the percentage
     * permanently short of 100 and the figure useless.
     *
     * @return list<int>
     */
    public static function expected(int $companyId, ?string $appliesTo, ?int $documentSiteId): array
    {
        $audience = json_decode((string) $appliesTo, true);
        $mode = is_array($audience) ? ($audience['mode'] ?? 'EVERYONE') : 'EVERYONE';

        $people = DB::table('hr_people')
            ->where('company_id', $companyId)
            ->whereNotNull('user_id')
            // Only an explicit 0 is "not an employee": the column is nullable and most creation
            // paths leave it unset.
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 0));

        if ($mode === 'ROLES') {
            $roles = array_map('strval', $audience['roles'] ?? []);

            if ($roles === []) {
                // A ROLES audience naming no role expects nobody. Returning the whole company
                // instead would report every reader as outstanding on a document addressed to no
                // one, and the register would show a backlog that does not exist.
                return [];
            }

            /*
             * Through the model's own morph class, not a hardcoded class string.
             *
             * `model_has_roles.model_type` holds whatever the morph map says a User is. Spelling
             * the namespace out here would match nothing the day an alias is registered, and the
             * failure is silent — a ROLES document would report every reader as outstanding.
             */
            $userIds = DB::table('model_has_roles as mhr')
                ->join('roles as r', 'r.id', '=', 'mhr.role_id')
                ->whereIn('r.name', $roles)
                ->where('mhr.model_type', (new User)->getMorphClass())
                ->pluck('mhr.model_id');

            $people->whereIn('user_id', $userIds);
        } elseif ($mode === 'SITE' && $documentSiteId !== null) {
            $unitId = DB::table('sheq_sites')
                ->where('company_id', $companyId)->where('id', $documentSiteId)
                ->value('company_unit_id');

            if ($unitId === null) {
                // The site was never provisioned from a company unit, so there is no membership to
                // read. Nobody is expected rather than everybody: a site document is narrower than
                // the company by definition.
                return [];
            }

            $userIds = DB::table('company_unit_user_pivots')
                ->where('company_id', $unitId)   // the unit id, despite the column's name
                ->pluck('user_id');

            $people->whereIn('user_id', $userIds);
        }

        return $people->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
