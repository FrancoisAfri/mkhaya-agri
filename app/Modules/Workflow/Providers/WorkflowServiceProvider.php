<?php

namespace App\Modules\Workflow\Providers;

use App\Core\User\Models\User;
use App\Modules\Workflow\Listeners\ReassignOnDeactivation;
use App\Modules\Workflow\Services\WorkflowAudit;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Support\ServiceProvider;

class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One per request/job: the audit correlation id and the settings cache must not leak between them.
        $this->app->scoped(WorkflowAudit::class);
        $this->app->scoped(WorkflowSettings::class);
        $this->app->scoped(\App\Modules\Workflow\Services\Presenter::class);
    }

    public function boot(): void
    {
        User::updated(function (User $user) {
            if ($user->wasChanged('status') && (int) $user->status === 0) {
                app(ReassignOnDeactivation::class)->handle($user);
            }
        });
        User::deleted(fn (User $user) => app(ReassignOnDeactivation::class)->handle($user));
    }
}
