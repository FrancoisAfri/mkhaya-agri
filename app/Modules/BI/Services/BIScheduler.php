<?php

namespace App\Modules\BI\Services;

use App\Core\User\Models\User;
use App\Modules\BI\Mail\BIDashboardMail;
use App\Modules\BI\Support\BIAccess;
use App\Modules\BI\Support\BIDashboards;
use App\Modules\Tenancy\Services\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Email schedules for BI dashboards (copied from Mkhaya Sales' ReportScheduler). Each schedule is
 * built as its owner, inside its company, so a schedule can never send more than its owner may see
 * today — if they lose access or the company drops BI, the schedule fails and says why.
 */
class BIScheduler
{
    public const PERIODS = ['yesterday' => 'Yesterday', 'last_7_days' => 'Last 7 days', 'mtd' => 'Month to date', 'last_month' => 'Last month',
        'qtd' => 'Quarter to date', 'ytd' => 'Year to date', 'own' => "Each visual's own period"];
    public const FREQUENCIES = ['daily' => 'Every day', 'weekdays' => 'Weekdays', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

    public function __construct(private BIExporter $exporter, private BIEngine $engine) {}

    /** Is this schedule due at this minute (Africa/Johannesburg)? */
    public static function due(object $s, CarbonImmutable $now): bool
    {
        if (! $s->is_active || $s->send_time !== $now->format('H:i')) {
            return false;
        }
        if ($s->last_sent_at && CarbonImmutable::parse($s->last_sent_at)->gt($now->subMinutes(50))) {
            return false;
        }

        return match ($s->frequency) {
            'daily' => true,
            'weekdays' => $now->isWeekday(),
            'weekly' => (int) $s->weekday === $now->dayOfWeekIso,
            'monthly' => min((int) $s->month_day, $now->daysInMonth) === $now->day,
            default => false,
        };
    }

    /** Dashboard filters for a schedule's period ("own" = each visual keeps its own period). */
    public static function filters(string $period, CarbonImmutable $today): array
    {
        if ($period === 'own') {
            return [];
        }
        $y = $today->subDay();
        [$from, $to] = match ($period) {
            'yesterday' => [$y, $y],
            'last_7_days' => [$today->subDays(7), $y],
            'mtd' => [$today->startOfMonth(), $today],
            'qtd' => [$today->firstOfQuarter(), $today],
            'ytd' => [$today->startOfYear(), $today],
            default => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
        };

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'override' => true];
    }

    /** Build and send one schedule now. Call inside the schedule's company (TenantContext::run). */
    public function send(object $s, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('Africa/Johannesburg');
        $path = null;
        try {
            $user = User::find($s->user_id) ?? throw new \RuntimeException('The person who set up this schedule no longer exists.');
            if (! BIAccess::canView($user)) {
                throw new \RuntimeException('Business Intelligence is no longer switched on for the owner of this schedule.');
            }
            $dash = BIDashboards::find($s->dashboard, $user) ?? throw new \RuntimeException('The dashboard was deleted or is no longer shared with the owner.');
            $filters = self::filters($s->period, $now);
            $sheets = $this->exporter->dashboardSheets($dash['widgets'], $filters, $user);
            $slug = trim(preg_replace('/[^a-z0-9]+/i', '-', $dash['name']), '-') . '-' . $now->toDateString();
            if ($s->format === 'csv') {
                [$h, $rows] = reset($sheets) ?: [['Note'], [['No data']]];
                $path = tempnam(sys_get_temp_dir(), 'bi') . '.csv';
                file_put_contents($path, $this->exporter->csv($h, $rows));
                $file = ['path' => $path, 'name' => "{$slug}.csv", 'mime' => 'text/csv'];
            } else {
                $path = $this->exporter->workbook($sheets, $dash['name']);
                $file = ['path' => $path, 'name' => "{$slug}.xlsx", 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
            }
            $recipients = array_values(array_filter((array) json_decode($s->recipients, true), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
            if (! $recipients) {
                throw new \RuntimeException('No valid email address to send to.');
            }
            $period = $filters ? CarbonImmutable::parse($filters['from'])->format('j M Y') . ' to ' . CarbonImmutable::parse($filters['to'])->format('j M Y') : $now->format('j M Y');
            $company = app(CurrentCompany::class)->get()?->name;
            $link = url(str_starts_with($dash['key'], 't:') ? '/bi/t/' . substr($dash['key'], 2) : "/bi/{$dash['key']}");
            Mail::to($recipients)->send(new BIDashboardMail($s->name ?: $dash['name'], $period, $company, $this->headlines($dash['widgets'], $filters, $user), $file, $link));
            DB::table('bi_schedules')->where('id', $s->id)->update(['last_sent_at' => now(), 'last_status' => 'sent', 'last_error' => null, 'updated_at' => now()]);

            return ['ok' => true, 'recipients' => count($recipients), 'file' => $file['name']];
        } catch (\Throwable $e) {
            report($e);
            DB::table('bi_schedules')->where('id', $s->id)->update(['last_sent_at' => now(), 'last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** The dashboard's KPI cards as [label, formatted value] — up to eight, for the email body. */
    public function headlines(array $widgets, array $filters, $user): array
    {
        $out = [];
        foreach (collect($widgets)->sortBy(fn ($w) => [$w['y'] ?? 0, $w['x'] ?? 0]) as $w) {
            if ((BIEngine::TYPE_KIND[$w['type'] ?? ''] ?? null) !== 'kpi' || count($out) >= 8) {
                continue;
            }
            $r = $this->engine->run(['kind' => 'kpi'] + (array) ($w['config'] ?? []), $filters, $user);
            if (! empty($r['locked'])) {
                continue;
            }
            $m = array_key_first($r['formats'] ?? []);
            if ($m !== null && isset($r['values'][$m])) {
                $out[] = [$w['title'] ?: ($r['labels'][$m] ?? $m), self::format((float) $r['values'][$m], $r['formats'][$m])];
            }
        }

        return $out;
    }

    public static function format(float $v, string $format): string
    {
        return match ($format) {
            'currency' => 'R ' . number_format($v, 0, '.', ' '),
            'percent' => number_format($v, 1) . '%',
            default => number_format($v, abs($v - round($v)) > 0.001 ? 2 : 0, '.', ' '),
        };
    }
}
