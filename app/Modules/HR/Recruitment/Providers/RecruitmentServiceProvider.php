<?php

namespace App\Modules\HR\Recruitment\Providers;

use App\Modules\HR\Recruitment\Models\Requisition;
use App\Modules\HR\Recruitment\Policies\RequisitionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class RecruitmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nothing to bind in Sprint 1
    }

    public function boot(): void
    {
        // Routes are loaded via routes/modules/recruitment.php (included in routes/web.php).
        // The Routes/web.php file in this module is kept as a reference / for future use.

        Gate::policy(Requisition::class, RequisitionPolicy::class);
    }
}
