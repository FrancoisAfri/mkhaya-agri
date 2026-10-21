<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\FarmWorkOrder;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\HR\Hr\Attendance\Models as T;
use Illuminate\Support\Facades\DB;

/**
 * Every area of the farm on one screen: for each part the person may open — the work, people and
 * time, the packhouse, sales, water and weather, compliance, outgrowers, insights and integrations —
 * a few live figures and the way in. Each card reads the module that owns the area (Time &
 * Attendance, Warehouse, Manufacturing, Accounting, SHEQ), seen through the person's farms.
 */
class FarmOverview
{
    /** @return list<array{key: string, title: string, icon: string, link: string, figures: list<array{label: string, value: int|float|string, tone?: string}>}> */
    public function areas(User $user): array
    {
        $today = today();
        $areas = [
            'work' => [R::VIEW_WORK_ORDERS, 'Work orders', 'clipboard-list', '/agri/work-orders', fn () => [
                ['label' => 'Open', 'value' => FarmWorkOrder::query()->whereIn('status', FarmWorkOrder::OPEN)->count()],
                ['label' => 'Past planned finish', 'value' => $n = FarmWorkOrder::query()->whereIn('status', FarmWorkOrder::OPEN)->whereNotNull('planned_end')->where('planned_end', '<', now())->count(), 'tone' => $n ? 'error' : null],
                ['label' => 'Awaiting verification', 'value' => FarmWorkOrder::query()->where('status', FarmWorkOrder::VERIFICATION)->count()],
            ]],
            'people' => [R::VIEW_WORKFORCE, 'People & time', 'clock', '/attendance/timesheets', fn () => [
                ['label' => 'Active people', 'value' => T\Worker::query()->whereNotNull('farm_id')->where('status', T\Worker::ACTIVE)->count()],
                ['label' => 'On site now', 'value' => T\Timesheet::query()->whereNotNull('farm_id')->whereNull('clock_out_at')->where('clock_in_at', '>=', now()->subHours(16))->count()],
                ['label' => 'Timesheets to approve', 'value' => $n = T\Timesheet::query()->whereNotNull('farm_id')->where('status', T\Timesheet::COMPLETE)->count(), 'tone' => $n ? 'warning' : null],
                ['label' => 'Shifts this week', 'value' => T\RosteredShift::query()->whereNotNull('farm_id')->where('status', '!=', 'cancelled')->whereBetween('starts_at', [now()->startOfDay(), now()->addDays(7)])->count()],
            ]],
            'piecework' => [R::VIEW_WORKFORCE, 'Piecework & payroll', 'scale', '/attendance/piecework', fn () => [
                ['label' => 'Waiting for approval', 'value' => $n = T\PieceworkEntry::query()->whereNotNull('farm_id')->where('status', 'submitted')->count(), 'tone' => $n ? 'warning' : null],
                ['label' => 'Approved this month', 'value' => 'R'.number_format((float) T\PieceworkEntry::query()->whereNotNull('farm_id')->where('status', 'approved')
                    ->where('work_date', '>=', $today->copy()->startOfMonth())->sum('amount'), 0, '.', ' ')],
                ['label' => 'Handoffs not sent', 'value' => T\PayrollHandoff::query()->where('status', 'prepared')->count()],
            ]],
            'packhouse' => [R::VIEW_STOCK, 'Packhouse & cold stores', 'package', '/agri/packhouse', fn () => [
                ['label' => 'Lots in store', 'value' => ProduceLot::query()->where('status', 'available')->where('remaining', '>', 0)->count()],
                ['label' => 'Held in quarantine', 'value' => $n = ProduceLot::query()->where('status', 'quarantined')->where('remaining', '>', 0)->count(), 'tone' => $n ? 'warning' : null],
                ['label' => 'Runs this month', 'value' => \App\Modules\Agri\Models\PackhouseOperation::query()->where('performed_on', '>=', $today->copy()->startOfMonth())->count()],
                ['label' => 'Cold-store readings out of range', 'value' => $n = \App\Modules\Agri\Models\StorageReading::query()->where('out_of_range', true)->whereNull('corrective_action')->count(), 'tone' => $n ? 'error' : null],
            ]],
            'sales' => [R::VIEW_SALES, 'Sales & deliveries', 'building-store', '/agri/sales', fn () => $this->sales($user)],
            'water' => [R::VIEW_FARMS, 'Irrigation & weather', 'cloud', '/agri/weather', fn () => [
                ['label' => 'Irrigation planned', 'value' => \App\Modules\Agri\Models\IrrigationEvent::query()->where('status', 'planned')->count()],
                ['label' => 'Irrigation problems open', 'value' => $n = \App\Modules\Agri\Models\IrrigationException::query()->whereNull('resolved_at')->count(), 'tone' => $n ? 'warning' : null],
                ['label' => 'Weather warnings', 'value' => $n = \App\Modules\Agri\Models\WeatherWarning::query()->where('ends_at', '>=', now())->count(), 'tone' => $n ? 'warning' : null],
            ]],
            'compliance' => [R::VIEW_FARMS, 'Compliance & documents', 'shield-check', '/sheq/legal-register', fn () => $this->compliance()],
            'outgrowers' => [R::VIEW_OUTGROWERS, 'Outgrowers', 'users', '/agri/outgrowers', fn () => [
                ['label' => 'Active growers', 'value' => \App\Modules\Agri\Models\Grower::query()->where('status', 'active')->count()],
                ['label' => 'Collections this month', 'value' => \App\Modules\Agri\Models\GrowerCollection::query()->where('collected_on', '>=', $today->copy()->startOfMonth())->count()],
                ['label' => 'Advances issued', 'value' => \App\Modules\Agri\Models\GrowerAdvance::query()->count()],
            ]],
            'insights' => [R::VIEW_REPORTS, 'Insights', 'chart-pie', '/insights/farm-operations', fn () => [
                ['label' => 'Farm reports', 'value' => count(\App\Modules\Agri\Http\Controllers\AgriReportController::REPORTS)],
                ['label' => 'Sent on a schedule', 'value' => \App\Shared\Insights\ReportSchedule::query()->where('report_key', 'like', 'agri:%')->where('active', true)->count()],
            ]],
            'integrations' => [R::MANAGE_INTEGRATIONS, 'Integrations', 'plug', '/agri/integrations', fn () => [
                ['label' => 'Messages today', 'value' => \App\Modules\Agri\Models\IntegrationEvent::query()->where('created_at', '>=', $today)->count()],
                ['label' => 'Failed', 'value' => $n = \App\Modules\Agri\Models\IntegrationEvent::query()->whereIn('status', ['failed', 'dead'])->count(), 'tone' => $n ? 'error' : null],
            ]],
        ];

        $out = [];
        foreach ($areas as $key => [$ability, $title, $icon, $link, $figures]) {
            if (! $user->can($ability)) {
                continue;
            }
            try {
                $out[] = ['key' => $key, 'title' => $title, 'icon' => $icon, 'link' => $link, 'figures' => array_values(array_filter($figures()))];
            } catch (\Throwable $e) {
                report($e);   // an area that cannot be read is left out, not the dashboard
            }
        }

        return $out;
    }

    private function sales(User $user): array
    {
        $farms = app(FarmAccess::class)->farmIdsFor($user);
        $orders = \App\Modules\Finance\Accounting\Models\FinSalesOrder::query()->whereNotNull(DB::raw("dimensions->>'agri_farm'"))
            ->when($farms !== null, fn ($q) => $q->whereIn(DB::raw("(dimensions->>'agri_farm')::bigint"), $farms ?: [0]));

        return [
            ['label' => 'Open orders', 'value' => (clone $orders)->whereIn('status', ['open', 'part_delivered'])->count()],
            ['label' => 'Due in 7 days', 'value' => (clone $orders)->whereIn('status', ['open', 'part_delivered'])->whereDate('deliver_on', '<=', today()->addDays(7))->count()],
            ['label' => 'Active contracts', 'value' => \App\Modules\Agri\Models\SalesContract::query()->whereIn('status', \App\Modules\Agri\Models\SalesContract::LIVE)->count()],
        ];
    }

    private function compliance(): array
    {
        $sites = \App\Modules\Agri\Models\Farm::query()->whereNotNull('sheq_site_id')->pluck('sheq_site_id');
        $lines = $sites->isEmpty() ? collect() : DB::table('sheq_legal_register_lines')->whereIn('site_id', $sites)->whereNull('deleted_at')
            ->where('is_applicable', true)->get(['status']);

        return [
            ['label' => 'Obligations met', 'value' => $lines->where('status', 'COMPLIANT')->count().' of '.$lines->count()],
            ['label' => 'Not met or to review', 'value' => $n = $lines->whereIn('status', ['NON_COMPLIANT', 'PARTIAL', 'REVIEW_REQUIRED'])->count(), 'tone' => $n ? 'error' : null],
            ['label' => 'Documents expiring', 'value' => $n = \App\Modules\Agri\Models\FarmAlert::query()->where('kind', 'document_expiry')->whereIn('status', ['open', 'acknowledged'])->count(), 'tone' => $n ? 'warning' : null],
        ];
    }
}
