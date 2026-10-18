<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Duplicate detection + merge engine (spec §1 / §12).
 *
 * Clusters records that share a normalised key (email, or name/company) and
 * merges a duplicate into a primary — re-parenting related activities and deals,
 * filling blank fields on the primary, then soft-deleting the duplicate.
 */
class CrmDuplicateService
{
    private const CONFIG = [
        'lead'    => ['model' => CrmLead::class,    'keys' => ['email'],       'label' => ['first_name', 'last_name', 'company']],
        'contact' => ['model' => CrmContact::class, 'keys' => ['email'],       'label' => ['first_name', 'last_name']],
        // Company names are matched on their normalised form, so an accounting import of
        // "Afrigrowth Consulting (Pty) Ltd" finds the existing "Afrigrowth Consulting".
        'account' => ['model' => CrmAccount::class, 'keys' => ['name'],        'label' => ['name'], 'normalise' => true],
    ];

    /** Legal-form suffixes that don't distinguish one company from another. */
    private const LEGAL_FORMS = 'pty|proprietary|ltd|limited|inc|incorporated|cc|npc|soc|plc|llc';

    /**
     * A company name reduced to what actually identifies it: lower-cased, punctuation gone and
     * legal-form suffixes stripped. Deliberately conservative — words like "group", "holdings"
     * and "trust" are kept, because they do separate one entity from another.
     */
    public function normalizedName(string $name): string
    {
        $n = strtolower(trim($name));
        $n = preg_replace('/[^a-z0-9]+/', ' ', $n);                  // "(Pty) Ltd." → " pty ltd "
        $n = preg_replace('/\b(' . self::LEGAL_FORMS . ')\b/', ' ', $n);

        return trim(preg_replace('/\s+/', ' ', $n));
    }

    public function entityTypes(): array
    {
        return array_keys(self::CONFIG);
    }

    /**
     * Find duplicate clusters for an entity type.
     *
     * @return array<int, array{key:string, records:array}>
     */
    public function clusters(string $entityType): array
    {
        $cfg = self::CONFIG[$entityType] ?? null;
        if (! $cfg) return [];

        $model   = $cfg['model'];
        $records = $model::query()->orderBy('id')->get();

        $groups = [];
        foreach ($records as $r) {
            $key = $this->dedupKey($r, $cfg['keys'], $cfg['normalise'] ?? false);
            if ($key === '') continue;
            $groups[$key][] = $r;
        }

        $clusters = [];
        foreach ($groups as $key => $rows) {
            if (count($rows) < 2) continue;
            $clusters[] = [
                'key'     => $key,
                'records' => array_map(fn ($r) => [
                    'id'      => $r->id,
                    'label'   => $this->label($r, $cfg['label']),
                    'email'   => $r->email ?? null,
                    'created' => $r->created_at?->toDateString(),
                ], $rows),
            ];
        }

        return $clusters;
    }

    public function countDuplicates(string $entityType): int
    {
        return array_sum(array_map(fn ($c) => count($c['records']) - 1, $this->clusters($entityType)));
    }

    /**
     * Merge $duplicateId into $primaryId. Re-parents related rows, fills blanks
     * on the primary, soft-deletes the duplicate.
     */
    public function merge(string $entityType, int $primaryId, int $duplicateId): void
    {
        if ($primaryId === $duplicateId) {
            throw new \InvalidArgumentException('Cannot merge a record into itself.');
        }

        $cfg     = self::CONFIG[$entityType] ?? throw new \InvalidArgumentException("Cannot merge {$entityType}.");
        $model   = $cfg['model'];
        $primary = $model::findOrFail($primaryId);
        $dup     = $model::findOrFail($duplicateId);

        DB::transaction(function () use ($entityType, $primary, $dup) {
            // Fill blank fields on the primary from the duplicate.
            foreach ($dup->getAttributes() as $field => $value) {
                if (in_array($field, ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at'])) continue;
                if (($primary->{$field} === null || $primary->{$field} === '') && $value !== null && $value !== '') {
                    $primary->{$field} = $value;
                }
            }
            $primary->save();

            // Re-parent the unified activity timeline.
            CrmActivity::where('related_type', $entityType)->where('related_id', $dup->id)
                ->update(['related_id' => $primary->id]);

            // Re-parent relations specific to the entity.
            if ($entityType === 'account') {
                CrmContact::where('account_id', $dup->id)->update(['account_id' => $primary->id]);
                CrmDeal::where('account_id', $dup->id)->update(['account_id' => $primary->id]);
            }
            if ($entityType === 'contact') {
                CrmDeal::where('contact_id', $dup->id)->update(['contact_id' => $primary->id]);
            }

            $dup->delete(); // soft delete
        });
    }

    private function dedupKey(Model $r, array $keys, bool $normalise = false): string
    {
        foreach ($keys as $key) {
            $val = $r->getAttribute($key);
            if ($val !== null && trim((string) $val) !== '') {
                return $normalise ? $this->normalizedName((string) $val) : strtolower(trim((string) $val));
            }
        }
        return '';
    }

    private function label(Model $r, array $fields): string
    {
        return trim(implode(' ', array_filter(array_map(fn ($f) => $r->getAttribute($f), $fields)))) ?: ('#' . $r->id);
    }
}
