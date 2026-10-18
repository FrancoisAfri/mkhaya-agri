<?php

namespace App\Modules\Finance\Tax\Support;

use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Services\TaxAudit;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A company's tax settings, falling back to config/tax.php. Read through here everywhere, so a
 * company's choice applies consistently and a change is one audited write.
 */
class TaxSettings
{
    /** key => [config default path, label, validation] */
    public const KEYS = [
        'reconciliation_tolerance' => ['tax.reconciliation_tolerance', 'Reconciliation tolerance (R)', 'numeric|min:0|max:100000'],
        'reminder_days' => ['tax.reminder_days', 'Reminder days before a deadline', 'array'],
        'chase_unconfirmed_after_days' => [null, 'Chase unconfirmed submissions after (days)', 'integer|min:1|max:30'],
        'provisional_first_share' => ['tax.provisional_first_share', 'First provisional payment (% of the year\'s tax)', 'numeric|min:0|max:100'],
        'assessed_loss_cap_amount' => ['tax.assessed_loss.cap_amount', 'Assessed-loss set-off: amount (R)', 'numeric|min:0'],
        'assessed_loss_cap_percent' => ['tax.assessed_loss.cap_percent', 'Assessed-loss set-off: % of taxable income', 'numeric|min:0|max:100'],
    ];

    private const FALLBACK = ['chase_unconfirmed_after_days' => 2];

    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function get(string $key, ?int $companyId = null): mixed
    {
        $values = $this->values($companyId);

        return $values[$key] ?? $this->default($key);
    }

    /** @return array<string, mixed> every key with its effective value */
    public function all(?int $companyId = null): array
    {
        return collect(self::KEYS)->mapWithKeys(fn ($def, $key) => [$key => $this->get($key, $companyId)])->all();
    }

    public function default(string $key): mixed
    {
        $path = self::KEYS[$key][0] ?? null;

        return $path ? config($path) : (self::FALLBACK[$key] ?? null);
    }

    /** @param array<string, mixed> $changes */
    public function update(array $changes, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new TaxControlException('Say why the settings are changing — it is kept on the audit trail.');
        }
        $companyId = $this->companyId();
        if (! $companyId) {
            throw new TaxControlException('Settings are kept per company.');
        }
        $changes = array_intersect_key($changes, self::KEYS);
        if (isset($changes['reminder_days'])) {
            $changes['reminder_days'] = array_values(array_unique(array_map('intval', array_filter((array) $changes['reminder_days'], fn ($d) => (int) $d > 0 && (int) $d <= 90))));
            rsort($changes['reminder_days']);
        }

        return DB::transaction(function () use ($companyId, $changes, $reason) {
            $row = DB::table('tax_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $before = $row ? json_decode($row->values, true) : [];
            $after = array_merge($before, $changes);
            DB::table('tax_settings')->updateOrInsert(['company_id' => $companyId],
                ['values' => json_encode($after), 'updated_by' => Auth::id(), 'updated_at' => now(), 'created_at' => $row->created_at ?? now()]);
            unset($this->cache[$companyId]);
            app(TaxAudit::class)->record('settings.updated', 'TaxSettings', ['company_id' => $companyId, 'reason' => $reason,
                'before' => array_intersect_key($before, $changes), 'after' => $changes]);

            return $this->all($companyId);
        });
    }

    private function values(?int $companyId): array
    {
        $companyId ??= $this->companyId();
        if (! $companyId) {
            return [];
        }

        return $this->cache[$companyId] ??= (function () use ($companyId) {
            $raw = DB::table('tax_settings')->where('company_id', $companyId)->value('values');

            return $raw ? (json_decode($raw, true) ?: []) : [];
        })();
    }

    private function companyId(): ?int
    {
        return app(CurrentCompany::class)->id() ?? Auth::user()?->company_id;
    }
}
