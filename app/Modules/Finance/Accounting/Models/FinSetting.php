<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\Edition;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Company details printed on finance documents (letterhead, banking, message).
 * Single row per company (company_id null for a standalone install).
 */
class FinSetting extends Model
{
    protected $table = 'fin_settings';

    protected $fillable = [
        'company_id', 'entity_id', 'company_name', 'trading_name', 'address', 'email', 'phone', 'website',
        'vat_number', 'registration_number', 'vendor_number', 'logo', 'logo_path', 'bank_name', 'bank_account_name',
        'bank_account_number', 'branch_code', 'swift', 'default_message', 'default_terms',
        'reminders_enabled', 'reminder_days', 'show_payment_qr', 'snapscan_merchant',
        // Dunning ladder + omnichannel collections config:
        'dunning_steps', 'reminder_channels', 'payment_link_enabled', 'reminder_grace_days',
        // Fiscal calendar:
        'fiscal_year_start_month',
    ];

    /**
     * A settings row always says which business's letterhead it is.
     *
     * This model deliberately has no BelongsToEntity: reads go through `current()`, which has its
     * own resolution order — the exact row, then the legacy row a default business adopts, then
     * the shared one it copies on write — and a global scope sitting underneath that would fight
     * it. What it does need is the other half of the trait, the stamping: since Phase 4 a row that
     * names a tenant and no business is refused outright, so a settings row created without one
     * would fail at the insert.
     *
     * The business in context first, then the one the row's own company defaults to — the same
     * order the trait uses, and for the same reason: a console command or an import names the
     * company and has no request to ask.
     */
    protected static function booted(): void
    {
        static::creating(function (FinSetting $settings) {
            if ($settings->entity_id !== null || $settings->company_id === null) {
                return;
            }

            $entities = app(CurrentEntity::class);

            $settings->entity_id = $entities->id()
                ?? $entities->defaultFor((int) $settings->company_id)?->id;
        });
    }

    protected $casts = [
        'reminders_enabled'    => 'boolean',
        'reminder_days'        => 'array',
        'show_payment_qr'      => 'boolean',
        'dunning_steps'        => 'array',
        'reminder_channels'    => 'array',
        'payment_link_enabled'    => 'boolean',
        'reminder_grace_days'     => 'integer',
        'fiscal_year_start_month' => 'integer',
    ];

    /**
     * This company's finance settings.
     *
     * ── Why this is not simply firstOrNew(company_id) ────────────────────────
     *
     * It used to be, and with no argument that means `company_id IS NULL` — one row shared by
     * everybody. Roughly half the callers pass a company and half do not, so on a multi-tenant
     * install every tenant's settings screen read and wrote the same row, and the letterhead,
     * banking details, VAT number and vendor number of whichever tenant saved last were printed on
     * every other tenant's invoices. `fin_settings` carries no row-level security to catch it —
     * the table is nullable, unforced and has no session-variable default — so nothing did.
     *
     * The company is therefore resolved when it is not given, exactly as a request-scoped page
     * would resolve it, and self-hosted resolves to its sole company rather than dead-ending.
     *
     * ── Why the null row is still read ───────────────────────────────────────
     *
     * Every existing install has its settings in that shared row. Scoping the lookup without a
     * fallback would open the settings screen blank and print an unbranded invoice on the next
     * document run — a silent regression on live data. So the shared row is still the fallback,
     * and the first save copies it to a row of this company's own, leaving the original for
     * anybody else still reading it. Copy-on-write, as the shared-library tables do it.
     */
    public static function current(?int $companyId = null, ?int $entityId = null): self
    {
        $companyId ??= self::activeCompanyId();

        if ($companyId === null) {
            // No tenant to resolve — console work with no context, or a standalone install that
            // has no company row at all. The shared row is the only sensible answer.
            return static::firstOrNew(['company_id' => null]);
        }

        /*
         * ── Which trading entity's letterhead ────────────────────────────────
         *
         * A tenant may trade as several businesses, each with its own name, logo, VAT number and
         * banking details. The entity is resolved the same way the company above it is: taken from
         * the caller when given, otherwise from the active request, otherwise the tenant's default.
         *
         * Every caller of this method reaches it without knowing entities exist — 21 of them, from
         * the settings screen to the PDF renderer — so resolving here is what makes a document
         * print under the right business without any of them changing.
         */
        $entityId ??= app(\App\Modules\Finance\Accounting\Services\CurrentEntity::class)->id();

        if ($entityId !== null) {
            if ($own = static::query()->where('company_id', $companyId)->where('entity_id', $entityId)->first()) {
                return $own;
            }

            $entity = \App\Modules\Finance\Accounting\Models\FinEntity::withoutGlobalScopes()->find($entityId);

            /*
             * The default entity is the tenant as it was before entities existed.
             *
             * A settings row written before this feature — or by any code that still knows nothing
             * about entities — carries no entity_id, and it is the tenant's real letterhead. The
             * default entity adopts it, and stamps itself on it so the next save files it properly.
             * Without this every existing install would open a blank settings screen and print an
             * unbranded invoice on the next document run.
             */
            if ($entity?->is_default) {
                $legacy = static::query()->where('company_id', $companyId)->whereNull('entity_id')->first();

                if ($legacy) {
                    $legacy->entity_id = $entityId;

                    return $legacy;
                }
            }

            /*
             * A second entity with no settings of its own starts blank.
             *
             * Copying the default entity's row would be friendlier to set up and would print
             * Mkhaya MK's VAT number and bank account on Mkhaya Move's invoices until somebody
             * noticed — a compliance problem rather than a cosmetic one. Blank fields are visible;
             * wrong ones are not.
             */
            if ($entity && ! $entity->is_default) {
                return static::newModelInstance([
                    'company_id' => $companyId,
                    'entity_id'  => $entityId,
                ]);
            }

            // The default entity falls through to the copy-on-write below: an install whose
            // settings still live on the shared row must keep reading them.
        }

        /*
         * The row this tenant had before businesses existed, which names no business.
         *
         * `whereNull('entity_id')` is the whole of the fix for a letterhead that edited two
         * businesses at once. Without it this asked for "any settings row of this company" and
         * took the first one — and the default business reaches here precisely when it has no row
         * of its own, so the first one it found belonged to a *different* business. Mkhaya then
         * opened Mkhaya Move's letterhead, saved over it, and both screens showed one address
         * under two names.
         *
         * A row that names a business is that business's, and is never the answer for another.
         */
        if ($own = static::query()->where('company_id', $companyId)->whereNull('entity_id')->first()) {
            return $own;
        }

        $shared = static::query()->whereNull('company_id')->first();

        // Unsaved: reads exactly like the shared row, and a save makes it this company's own —
        // filed under the entity that read it, so the copy lands where it will be looked for.
        return $shared
            ? tap($shared->replicate(['id']), function (self $copy) use ($companyId, $entityId) {
                $copy->company_id = $companyId;
                $copy->entity_id = $entityId;
            })
            : static::newModelInstance(['company_id' => $companyId, 'entity_id' => $entityId]);
    }

    /**
     * The company these settings belong to when the caller did not say.
     *
     * Deliberately tolerant of having no answer: a queue worker, a console pass outside
     * `eachTenant`, or a platform admin who has not entered a tenant all legitimately have none,
     * and null sends the caller to the shared row rather than to company 0.
     */
    private static function activeCompanyId(): ?int
    {
        // id() is an instance method on the bound singleton — calling it statically fatals.
        $companyId = app(CurrentCompany::class)->id() ?? auth()->user()?->company_id;

        if (! $companyId && Edition::isSelfHosted()) {
            // One customer, one company: there is no tenant to choose between, and refusing to
            // choose strands a self-hosted administrator on a settings screen that never saves.
            $companies = DB::table('companies')->limit(2)->pluck('id');
            $companyId = $companies->count() === 1 ? $companies->first() : null;
        }

        return $companyId ? (int) $companyId : null;
    }

    /** Enabled reminder channels, always including email as the fallback. */
    public function channels(): array
    {
        $c = $this->reminder_channels ?: ['email'];

        return array_values(array_unique(array_merge(['email'], array_filter($c, fn ($x) => in_array($x, ['email', 'sms', 'whatsapp'], true)))));
    }

    /**
     * The dunning ladder: an ordered list of steps keyed by days-overdue. Falls back to a sensible
     * default derived from reminder_days (or 7/14/30/60) so a company gets a working cadence out of
     * the box. Each step: ['days'=>int, 'channel'=>email|sms|whatsapp, 'tone'=>string].
     */
    public function ladder(): array
    {
        if (! empty($this->dunning_steps) && is_array($this->dunning_steps)) {
            return collect($this->dunning_steps)
                ->map(fn ($s) => [
                    'days'    => (int) ($s['days'] ?? 0),
                    'channel' => in_array($s['channel'] ?? 'email', ['email', 'sms', 'whatsapp'], true) ? $s['channel'] : 'email',
                    'tone'    => $s['tone'] ?? 'friendly',
                    'subject' => $s['subject'] ?? null,
                    'body'    => $s['body'] ?? null,
                ])
                ->sortBy('days')->values()->all();
        }

        $days = ! empty($this->reminder_days) ? array_map('intval', $this->reminder_days) : [7, 14, 30, 60];
        sort($days);
        $tones = ['friendly', 'friendly', 'firm', 'urgent', 'final'];

        return collect($days)->values()->map(fn ($d, $i) => [
            'days'    => (int) $d,
            'channel' => 'email',
            'tone'    => $tones[min($i, count($tones) - 1)],
            'subject' => null,
            'body'    => null,
        ])->all();
    }
}
