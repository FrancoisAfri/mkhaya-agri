<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Services\Ledger;
use Illuminate\Database\Eloquent\Model;

/**
 * Mkhaya Agri's only way into the books: the shared ledger, with farm dimensions on every line.
 *
 * There is no farm ledger. A farm's costs and income are the shared journal's lines carrying
 * `agri_farm`; a crop's or herd's are those also carrying `agri_enterprise`. The profitability
 * report reads exactly those lines, so what the accountant sees in the trial balance and what the
 * farmer sees per field can never disagree. `tracking` carries the farm code too, so the platform's
 * existing "income and expense by tracking" report splits by farm without knowing about farms.
 */
class AgriLedger
{
    public function __construct(private readonly Ledger $ledger) {}

    /** The dimensions a farm posting carries. */
    public static function dimensions(Farm $farm, ?FarmField $field = null, ?Model $enterprise = null, ?string $category = null): array
    {
        $season = $enterprise instanceof CropPlan ? $enterprise->season_id : null;

        return array_filter([
            'tracking' => $farm->code,
            'agri_farm' => (string) $farm->id,
            'agri_field' => $field ? (string) $field->id : null,
            'agri_enterprise' => $enterprise ? self::enterpriseKey($enterprise) : null,
            'agri_season' => $season ? (string) $season : null,
            'agri_category' => $category,
        ], fn ($v) => $v !== null);
    }

    public static function enterpriseKey(Model $enterprise): string
    {
        return match (true) {
            $enterprise instanceof CropPlan => 'crop_plan:'.$enterprise->id,
            $enterprise instanceof LivestockGroup => 'livestock_group:'.$enterprise->id,
            default => class_basename($enterprise).':'.$enterprise->getKey(),
        };
    }

    /**
     * A farm expense paid from the bank or owed to a supplier: Dr expense (with dimensions) /
     * Cr bank or accounts payable.
     */
    public function expense(Farm $farm, float $amount, string $date, string $description, array $dimensions,
        string $settledBy = 'bank', ?string $account = null, ?string $idempotencyKey = null): FinJournal
    {
        $credit = $settledBy === 'payable' ? config('finance.ap_control') : config('finance.default_bank');

        return $this->ledger->post(new FinancialEvent(
            sourceModule: 'agri', eventType: 'agri.expense',
            debits: [['account' => $account ?? config('agri.accounts.direct_expense'), 'amount' => $amount, 'narration' => $description, 'dimensions' => $dimensions]],
            credits: [['account' => $credit, 'amount' => $amount, 'narration' => $description]],
            eventDate: $date, companyId: $farm->company_id, currency: $farm->currency ?: 'ZAR',
            sourceRef: "farm:{$farm->id}", description: $description, idempotencyKey: $idempotencyKey,
        ));
    }

    /**
     * Produce or livestock sold: Dr bank or accounts receivable / Cr sales (with dimensions).
     */
    public function sale(Farm $farm, float $amount, string $date, string $description, array $dimensions,
        string $kind = 'produce', string $settledBy = 'bank', ?string $idempotencyKey = null): FinJournal
    {
        $debit = $settledBy === 'receivable' ? config('finance.ar_control') : config('finance.default_bank');
        $account = $kind === 'livestock' ? config('agri.accounts.livestock_sales') : config('agri.accounts.produce_sales');

        return $this->ledger->post(new FinancialEvent(
            sourceModule: 'agri', eventType: 'agri.sale',
            debits: [['account' => $debit, 'amount' => $amount, 'narration' => $description]],
            credits: [['account' => $account, 'amount' => $amount, 'narration' => $description, 'dimensions' => $dimensions]],
            eventDate: $date, companyId: $farm->company_id, currency: $farm->currency ?: 'ZAR',
            sourceRef: "farm:{$farm->id}", description: $description, idempotencyKey: $idempotencyKey,
        ));
    }
}
