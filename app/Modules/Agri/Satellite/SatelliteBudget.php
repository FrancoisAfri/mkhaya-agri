<?php

namespace App\Modules\Agri\Satellite;

use App\Modules\Agri\Models\SatelliteUsage;

/**
 * The daily request budget of one organisation. spend() is called before every request to the
 * provider: it refuses once the day's allowance is used, and otherwise counts the request at once
 * (committed as it happens, so a fetch that fails later still shows what it cost).
 */
class SatelliteBudget
{
    private int $spent = 0;

    public function __construct(public readonly int $companyId, public readonly int $limit) {}

    public function used(): int
    {
        return (int) SatelliteUsage::withoutGlobalScopes()->where('company_id', $this->companyId)->whereDate('used_on', today())->value('requests');
    }

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used());
    }

    /** Requests this budget object has counted (this fetch). */
    public function spentNow(): int
    {
        return $this->spent;
    }

    public function spend(int $n = 1): void
    {
        $row = SatelliteUsage::withoutGlobalScopes()->firstOrCreate(['company_id' => $this->companyId, 'used_on' => today()->toDateString()], ['requests' => 0]);
        // Checked and counted in one statement, so two fetches running at once cannot both take the last request.
        $ok = SatelliteUsage::withoutGlobalScopes()->whereKey($row->id)->where('requests', '<=', $this->limit - $n)
            ->update(['requests' => \Illuminate\Support\Facades\DB::raw('requests + '.(int) $n), 'updated_at' => now()]);
        if (! $ok) {
            throw new SatelliteBudgetExhausted("Today's satellite request budget ({$this->limit}) is used up; the fetch continues tomorrow.");
        }
        $this->spent += $n;
    }
}
