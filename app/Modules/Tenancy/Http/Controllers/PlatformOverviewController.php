<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Core\Module\Models\Modules;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\SubscriptionInvoice;
use App\Modules\Tenancy\Models\TokenRequest;
use App\Modules\Tenancy\Services\Edition;
use App\Modules\Tenancy\Services\StorageQuota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * Platform Overview — the global roll-up a platform admin (the vendor) lands on
 * after a SaaS install. This is NOT the "Platform Admins" management page; it is
 * the owner's control tower across EVERY company: subscription revenue
 * (MRR/ARR/outstanding/new/churn), company status counts, per-company breakdown,
 * plus quick links to the platform tools.
 *
 * All aggregates run with no active tenant, so the CompanyScope is inactive and
 * these queries legitimately see every row.
 */
class PlatformOverviewController extends Controller
{
    public function index()
    {
        return Inertia::render('Tenancy/Overview', [
            'edition' => Edition::current(),
            'revenue' => $this->revenue(),
            'companies' => $this->companyStats(),
            'usage' => $this->usage(),
            'breakdown' => $this->breakdown(),
            'tools' => $this->tools(),
            'generated_at' => now()->format('Y-m-d H:i'),
        ]);
    }

    /** Price per plan code, e.g. ['starter' => 499, ...]. */
    private function planPrices(): array
    {
        return Plan::pluck('price_monthly', 'code')->map(fn ($p) => (float) $p)->toArray();
    }

    /** MRR/ARR, outstanding, new & churned customers, churn rate. */
    private function revenue(): array
    {
        $prices = $this->planPrices();

        // MRR = sum of plan price for every paying (active) company.
        $mrr = (float) Company::where('status', 'active')->get()
            ->sum(fn ($c) => $prices[$c->plan] ?? 0.0);

        $outstanding = (float) SubscriptionInvoice::whereIn('status', ['open', 'overdue'])->sum('amount');

        $new30 = (int) Company::where('created_at', '>=', now()->subDays(30))->count();
        $churned30 = (int) Company::whereIn('status', ['suspended', 'cancelled'])
            ->where('suspended_at', '>=', now()->subDays(30))->count();
        $activeNow = (int) Company::where('status', 'active')->count();
        $payingBase = $activeNow + $churned30;
        $churnRate = $payingBase > 0 ? round($churned30 / $payingBase * 100, 1) : 0.0;

        return [
            'mrr' => $mrr,
            'arr' => $mrr * 12,
            'outstanding' => $outstanding,
            'new_customers_30d' => $new30,
            'churned_30d' => $churned30,
            'churn_rate' => $churnRate,
        ];
    }

    /** Company counts by status + user totals. */
    private function companyStats(): array
    {
        $byStatus = Company::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'total' => (int) Company::count(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'trial' => (int) ($byStatus['trial'] ?? 0),
            'suspended' => (int) (($byStatus['suspended'] ?? 0) + ($byStatus['cancelled'] ?? 0)),
            'users' => (int) User::count(),
            'active_users' => (int) User::where('status', 1)->count(),
            'modules' => (int) Modules::count(),
        ];
    }

    /** Per-company breakdown row: plan, status, users, MRR, outstanding. */
    private function breakdown(): array
    {
        $prices = $this->planPrices();

        $userCounts = User::selectRaw('company_id, count(*) as n')
            ->groupBy('company_id')->pluck('n', 'company_id');

        $outstanding = SubscriptionInvoice::whereIn('status', ['open', 'overdue'])
            ->selectRaw('company_id, sum(amount) as due')
            ->groupBy('company_id')->pluck('due', 'company_id');

        // Real storage used per company = uploaded files + relational data footprint
        // (platform admin has no RLS scope, so this aggregates every tenant).
        $storageFiles = DB::table('tenant_files')
            ->selectRaw('company_id, sum(size) as bytes')
            ->groupBy('company_id')->pluck('bytes', 'company_id');
        $storageData = DB::table('company_storage_stats')->pluck('data_bytes', 'company_id');
        $storageQuota = app(StorageQuota::class);

        return Company::orderBy('name')->get()->map(function ($c) use ($prices, $userCounts, $outstanding, $storageFiles, $storageData, $storageQuota) {
            $quota = $storageQuota->quotaBytes($c);

            return [
                'id' => $c->id,
                'name' => $c->name,
                'plan' => $c->plan,
                'status' => $c->status,
                'users' => (int) ($userCounts[$c->id] ?? 0),
                'mrr' => $c->status === 'active' ? (float) ($prices[$c->plan] ?? 0) : 0.0,
                'outstanding' => (float) ($outstanding[$c->id] ?? 0),
                'storage_used' => (int) ($storageFiles[$c->id] ?? 0) + (int) ($storageData[$c->id] ?? 0),
                'storage_quota' => (int) $quota, // 0 = unlimited
            ];
        })->values()->all();
    }

    /** Platform-wide AI & SMS usage this month + pending top-up requests. */
    private function usage(): array
    {
        $aiTokens = (int) DB::table('ai_usage_log')
            ->where('created_at', '>=', now()->startOfMonth())->sum('total_tokens');
        $smsUsed = Schema::hasTable('sms_log')
            ? (int) DB::table('sms_log')->where('status', 'sent')
                ->where('created_at', '>=', now()->startOfMonth())->sum('segments')
            : 0;

        return [
            'ai_tokens_month' => $aiTokens,
            'ai_rand_month' => round($aiTokens / 1000 * (float) config('ai.quota.rand_per_1k_tokens', 0.25), 2),
            'sms_month' => $smsUsed,
            'pending_requests' => (int) TokenRequest::where('status', 'pending')->count(),
        ];
    }

    /** Links to the platform tools, only those whose route exists. */
    private function tools(): array
    {
        $links = [
            ['label' => 'Companies',          'route' => 'tenancy.companies.index',        'icon' => 'tabler-building'],
            ['label' => 'Modules & Pricing',  'route' => 'tenancy.modules.index',          'icon' => 'tabler-adjustments-dollar'],
            ['label' => 'Payment Settings',   'route' => 'tenancy.payment-settings.index', 'icon' => 'tabler-credit-card'],
            ['label' => 'Yodlee Settings',    'route' => 'tenancy.yodlee.index',           'icon' => 'tabler-building-bank'],
            ['label' => 'Token Requests',     'route' => 'tenancy.token-requests.index',   'icon' => 'tabler-coins'],
            ['label' => 'Platform Admins',    'route' => 'settings.platform-admins.index', 'icon' => 'tabler-shield-lock'],
            ['label' => 'System Restoration', 'route' => 'settings.restoration.index',      'icon' => 'tabler-restore'],
        ];

        return collect($links)
            ->filter(fn ($l) => Route::has($l['route']))
            ->map(fn ($l) => ['label' => $l['label'], 'url' => route($l['route']), 'icon' => $l['icon']])
            ->values()->all();
    }
}
