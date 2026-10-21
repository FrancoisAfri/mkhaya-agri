<?php

namespace App\Modules\HR\Hr\Attendance\Services;

use App\Modules\HR\Leave\Services\WorkingDaysCalculator;
use App\Modules\Operations\Sheq\Contracts\CompetencyLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the farm asks HR, in one place: is somebody on approved leave, is a day a public holiday,
 * does a person hold a competency on a date. HR, SHEQ and LMS stay the owners of those answers;
 * nothing here keeps a copy.
 */
class HrSources
{
    private array $holidayMemo = [];

    public function onLeave(int $hrPersonId, Carbon $from, Carbon $to): bool
    {
        if (! Schema::hasTable('leave_requests')) {
            return false;
        }

        return DB::table('leave_requests')->where('employee_id', $hrPersonId)->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())
            ->when(Schema::hasColumn('leave_requests', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->exists();
    }

    public function isPublicHoliday(Carbon $date): bool
    {
        $key = $date->toDateString();

        return $this->holidayMemo[$key] ??= app(WorkingDaysCalculator::class)->isPublicHoliday($date->copy()->startOfDay());
    }

    /**
     * Whether a person holds a competency (SHEQ's catalogue; LMS certificates count) that is still
     * valid on the date. Returns the reason when not.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function holds(int $hrPersonId, string $code, Carbon $on): array
    {
        $ledger = app(CompetencyLedger::class);
        if (! $ledger->isAvailable()) {
            return ['ok' => false, 'reason' => "No competency records are available to confirm {$code}."];
        }
        $s = $ledger->statusForCompetency($hrPersonId, $code);
        if (! $s || in_array($s['status'], [CompetencyLedger::NOT_STARTED, CompetencyLedger::UNKNOWN], true)) {
            return ['ok' => false, 'reason' => "No {$code} competency on record."];
        }
        if ($s['expires_at'] && Carbon::parse($s['expires_at'])->endOfDay()->lt($on)) {
            return ['ok' => false, 'reason' => "{$code} expired on ".Carbon::parse($s['expires_at'])->toDateString().'.'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /** The organisation's competency catalogue, for pick-lists. */
    public function competencies(int $companyId): array
    {
        if (! Schema::hasTable('sheq_competencies')) {
            return [];
        }

        return DB::table('sheq_competencies')->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('title')->get(['code', 'title'])->map(fn ($c) => ['value' => $c->code, 'title' => "{$c->title} ({$c->code})"])->all();
    }
}
