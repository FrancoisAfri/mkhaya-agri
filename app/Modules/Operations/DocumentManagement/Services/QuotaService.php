<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentQuota;
use Illuminate\Support\Facades\DB;

/**
 * Storage limits below the level of the whole company.
 *
 * The company already has one, from its plan, and it is the wrong instrument for the problem it is
 * usually asked to solve. One department putting video into the repository fills the company's
 * allowance, and the first anybody hears about it is that nobody anywhere can upload anything. The
 * limit that would have helped is the one on the department.
 *
 * Three decisions worth stating.
 *
 * Every applicable limit is checked, not the nearest one. An upload into Finance's folder by
 * somebody in HR is covered by the folder's limit and by HR's and by their own, and a limit that
 * only sometimes applies is one nobody can reason about.
 *
 * A folder's limit covers what is beneath it. Otherwise making a subfolder is how somebody escapes
 * the limit, and the person who does it has no idea that is what they did.
 *
 * And the warning matters as much as the refusal. A quota that refuses an upload with no warning
 * beforehand is indistinguishable from the system being broken — and the person it stops is rarely
 * the person who filled it.
 */
class QuotaService
{
    /**
     * Whether this upload would breach anything, and what.
     *
     * @return array{ok: bool, breached: ?array<string, mixed>}
     */
    public function check(?User $user, ?DocumentFolder $folder, int $incomingBytes): array
    {
        foreach ($this->applicableTo($user, $folder) as $quota) {
            $used = $this->usedFor($quota);

            if ($used + $incomingBytes > (int) $quota->bytes_limit) {
                return ['ok' => false, 'breached' => [
                    'scope' => $quota->scope,
                    'name' => $this->nameFor($quota),
                    'limit' => (int) $quota->bytes_limit,
                    'used' => $used,
                ]];
            }
        }

        return ['ok' => true, 'breached' => null];
    }

    /**
     * The limits that are close enough to be worth saying something about.
     *
     * Read before an upload rather than after it: telling somebody they have filled a quota once
     * the upload is refused is the wrong end of the conversation.
     *
     * @return list<array<string, mixed>>
     */
    public function warningsFor(?User $user, ?DocumentFolder $folder = null): array
    {
        $warnings = [];

        foreach ($this->applicableTo($user, $folder) as $quota) {
            $used = $this->usedFor($quota);
            $limit = max(1, (int) $quota->bytes_limit);
            $percent = (int) round($used / $limit * 100);

            if ($percent < (int) $quota->warn_percent) {
                continue;
            }

            $warnings[] = [
                'scope' => $quota->scope,
                'name' => $this->nameFor($quota),
                'used' => $used,
                'limit' => $limit,
                'percent' => min(100, $percent),
                'full' => $used >= $limit,
            ];
        }

        return $warnings;
    }

    /**
     * Every limit bearing on this upload.
     *
     * @return list<DocumentQuota>
     */
    public function applicableTo(?User $user, ?DocumentFolder $folder): array
    {
        $quotas = [];

        if ($user) {
            $own = DocumentQuota::query()
                ->where('scope', DocumentQuota::SCOPE_USER)
                ->where('scope_id', $user->id)
                ->first();

            if ($own) {
                $quotas[] = $own;
            }

            $departmentId = $this->departmentOf($user);

            if ($departmentId) {
                $department = DocumentQuota::query()
                    ->where('scope', DocumentQuota::SCOPE_DEPARTMENT)
                    ->where('scope_id', $departmentId)
                    ->first();

                if ($department) {
                    $quotas[] = $department;
                }
            }
        }

        if ($folder) {
            /*
             * The folder and everything above it. A limit on Marketing has to cover Marketing /
             * Campaigns / 2027, or making a subfolder is how somebody escapes it — and the person
             * who does it has no idea that is what they did.
             */
            $chain = array_merge($folder->ancestors(), [$folder]);
            $ids = array_map(fn (DocumentFolder $f) => $f->id, $chain);

            foreach (DocumentQuota::query()
                ->where('scope', DocumentQuota::SCOPE_FOLDER)
                ->whereIn('scope_id', $ids)
                ->get() as $quota) {
                $quotas[] = $quota;
            }
        }

        return $quotas;
    }

    /**
     * How much this limit's subject is currently holding.
     *
     * Measured from the versions rather than from a running total kept somewhere. A counter drifts
     * — every deletion, purge and restore has to remember to adjust it, and the one that forgets is
     * discovered when a department cannot upload and nobody can say why.
     */
    public function usedFor(DocumentQuota $quota): int
    {
        $query = DB::table('document_versions as v')
            ->join('documents as d', 'd.id', '=', 'v.document_id')
            ->whereNull('d.deleted_at');

        switch ($quota->scope) {
            case DocumentQuota::SCOPE_USER:
                $query->where('d.created_by', $quota->scope_id);
                break;

            case DocumentQuota::SCOPE_DEPARTMENT:
                $query->where('d.department_unit_id', $quota->scope_id);
                break;

            case DocumentQuota::SCOPE_FOLDER:
                $folder = DocumentFolder::find($quota->scope_id);

                if (! $folder) {
                    return 0;
                }

                // The folder and its descendants, matched on the materialised path so a deep tree
                // costs one query rather than one per level.
                $ids = DocumentFolder::query()
                    ->where('id', $folder->id)
                    ->orWhere('path', 'like', $folder->path . '/%')
                    ->pluck('id');

                $query->whereIn('d.folder_id', $ids);
                break;

            default:
                return 0;
        }

        return (int) $query->sum('v.size');
    }

    /** @return array<string, mixed> */
    public function describe(DocumentQuota $quota): array
    {
        $used = $this->usedFor($quota);
        $limit = max(1, (int) $quota->bytes_limit);

        return [
            'uuid' => $quota->uuid,
            'scope' => $quota->scope,
            'scope_id' => (int) $quota->scope_id,
            'name' => $this->nameFor($quota),
            'limit' => (int) $quota->bytes_limit,
            'used' => $used,
            'percent' => min(100, (int) round($used / $limit * 100)),
            'warn_percent' => (int) $quota->warn_percent,
            'note' => $quota->note,
        ];
    }

    /** What the limit is on, said in a way somebody recognises. */
    private function nameFor(DocumentQuota $quota): ?string
    {
        return match ($quota->scope) {
            DocumentQuota::SCOPE_USER => $this->userName((int) $quota->scope_id),
            DocumentQuota::SCOPE_DEPARTMENT => DB::table('company_units')->where('id', $quota->scope_id)->value('name'),
            DocumentQuota::SCOPE_FOLDER => DocumentFolder::where('id', $quota->scope_id)->value('name'),
            default => null,
        };
    }

    private function userName(int $userId): ?string
    {
        $user = User::query()->with('person')->find($userId);

        if (! $user) {
            return null;
        }

        $person = $user->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $user->email;
    }

    /**
     * The user's department.
     *
     * The same join FolderAccess uses, and the same caveat: company_unit_user_pivots.company_id
     * holds a company *unit* id despite its name.
     */
    private function departmentOf(User $user): ?int
    {
        $unitId = DB::table('company_unit_user_pivots as p')
            ->join('company_units as u', 'u.id', '=', 'p.company_id')
            ->where('p.user_id', $user->id)
            ->where('u.type', 'department')
            ->value('u.id');

        return $unitId === null ? null : (int) $unitId;
    }
}
