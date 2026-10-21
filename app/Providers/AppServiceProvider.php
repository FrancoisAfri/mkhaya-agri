<?php

namespace App\Providers;

use App\Core\AI\Contracts\AIAgentInterface;
use App\Core\AI\Registry\AgentRegistry;
use App\Core\Compliance\Events\DataBreachReported;
use App\Core\Compliance\Listeners\NotifyOnDataBreach;
use App\Core\Permission\Repositories\EloquentPermissionRepository;
use App\Core\Permission\Repositories\PermissionRepositoryInterface;
use App\Core\Role\Repositories\EloquentRoleRepository;
use App\Core\Role\Repositories\RoleRepositoryInterface;
use App\Core\User\Repositories\EloquentUserRepository;
use App\Core\User\Repositories\UserRepositoryInterface;
use App\Modules\HR\Hr\Repositories\EloquentHRPersonRepository;
use App\Modules\HR\Hr\Repositories\HRPersonRepositoryInterface;
use App\Modules\HR\Recruitment\Events\ApprovalRequested;
use App\Modules\HR\Recruitment\Events\CandidateApplied;
use App\Modules\HR\Recruitment\Events\CandidateConverted;
use App\Modules\HR\Recruitment\Events\InterviewScheduled;
use App\Modules\HR\Recruitment\Events\OfferAccepted;
use App\Modules\HR\Recruitment\Events\ShortlistGenerated;
use App\Modules\HR\Recruitment\Listeners\NotifyApproverOnPendingDecision;
use App\Modules\HR\Recruitment\Listeners\NotifyHrOnShortlistGenerated;
use App\Modules\HR\Recruitment\Listeners\ProcessOfferAccepted;
use App\Modules\HR\Recruitment\Listeners\SendApplicationConfirmation;
use App\Modules\HR\Recruitment\Listeners\SendInterviewScheduledNotification;
use App\Modules\HR\Recruitment\Listeners\TriggerOnboarding;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Observers\LmsEnrollmentObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Every date-time bound to a query is stored and compared in the application's zone (UTC),
        // whatever zone it was held in — see UtcPostgresConnection.
        // Mkhaya Agri: stock received into a farm store (a purchase order's delivery) carries the farm's
        // dimensions, and a chemical is only received where its storage class is allowed.
        $this->app->bind('stock.warehouse_dimensions', fn () => fn ($warehouse) => \App\Modules\Agri\Services\FarmInventory::dimensionsFor($warehouse));
        $this->app->bind('stock.receipt_guard', fn () => function ($item, $warehouse) {
            if (\App\Modules\Agri\Services\FarmInventory::dimensionsFor($warehouse) !== []) {
                \App\Modules\Agri\Services\FarmInventory::ensureUnit($item);
                app(\App\Modules\Agri\Services\ChemicalControls::class)->assertStorable($item, (int) $warehouse->id);
            }
        });

        \Illuminate\Database\Connection::resolverFor('pgsql', fn ($pdo, $database, $prefix, $config) => new \App\Support\Database\UtcPostgresConnection($pdo, $database, $prefix, $config));

        // Memoises who is confined to which factories for the request; a global scope on a dozen
        // models reads it, so a fresh instance each time would be a query per query.
        $this->app->singleton(\App\Modules\Operations\Manufacturing\Services\FactoryAccess::class);

        $this->app->register(\App\Modules\HR\Recruitment\Providers\RecruitmentServiceProvider::class);

        // Multi-tenancy: one active-company holder per request.
        $this->app->singleton(\App\Modules\Tenancy\Services\CurrentCompany::class);

        /*
         * One per request, as the company it sits beside is.
         *
         * CurrentEntity holds the trading entity this request is working in, including the
         * override `run()` pins for a console pass or a queued job. Resolved fresh each time, that
         * override would be set on one instance and read from another — the pin would appear to
         * work and change nothing.
         */
        $this->app->singleton(\App\Modules\Finance\Accounting\Services\CurrentEntity::class);

        // Project Management: one correlation id per request (stamped on every audit row), and one
        // permission evaluator so its per-request memoisation is actually shared.
        $this->app->singleton(\App\Modules\Operations\ProjectManagement\Services\RequestContext::class);
        $this->app->singleton(\App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator::class);

        $this->app->singleton(AgentRegistry::class);

        // License verification is memoised per request — bind as a singleton.
        $this->app->singleton(\App\Core\License\LicenseService::class);

        // Supplier due diligence: one provider registry per request, so a provider resolved once (or
        // faked in a test) is the same instance every later check sees.
        $this->app->singleton(\App\Modules\SupplierManagement\DueDiligence\Services\ProviderRegistry::class);


        $this->app->bind(
            UserRepositoryInterface::class,
            EloquentUserRepository::class
        );

        $this->app->bind(
            HRPersonRepositoryInterface::class,
            EloquentHRPersonRepository::class
        );

        $this->app->bind(
            AIAgentInterface::class,
            fn() => app(AgentRegistry::class)->for('default')
        );

        $this->app->bind(
            RoleRepositoryInterface::class,
            EloquentRoleRepository::class
        );
        $this->app->bind(
            PermissionRepositoryInterface::class,
            EloquentPermissionRepository::class
        );

        // Make notification emails best-effort: a mail failure (SMTP down, over
        // limit, mis-configured) is logged and skipped instead of throwing, so
        // the database notification still saves and the request completes.
        // ChannelManager::createMailDriver() resolves MailChannel from the
        // container, so this swap applies to every `mail` notification app-wide.
        $this->app->bind(
            \Illuminate\Notifications\Channels\MailChannel::class,
            fn ($app) => new \App\Shared\Notifications\Channels\FaultTolerantMailChannel(
                $app->make(\Illuminate\Contracts\Mail\Factory::class),
                $app->make(\Illuminate\Mail\Markdown::class),
            )
        );

        // In-app notifications "must always go": deliver the database/broadcast
        // channels synchronously even for ShouldQueue notifications and with no
        // queue worker running. Mail and other channels are still queued.
        $this->app->singleton(
            \Illuminate\Notifications\ChannelManager::class,
            fn ($app) => new \App\Shared\Notifications\SyncInAppChannelManager($app)
        );
        $this->app->alias(
            \Illuminate\Notifications\ChannelManager::class,
            \Illuminate\Contracts\Notifications\Dispatcher::class
        );
        $this->app->alias(
            \Illuminate\Notifications\ChannelManager::class,
            \Illuminate\Contracts\Notifications\Factory::class
        );

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LmsEnrollment::observe(LmsEnrollmentObserver::class);

        // The farm's crop production cycle (process 1) runs on the workflow engine.
        \App\Modules\Agri\Processes\CropSeasonProcess::register();

        // Database-backed file storage: the 'database' filesystem driver stores
        // uploads in the tenant_files table (tenant-scoped by RLS) instead of disk.
        \Illuminate\Support\Facades\Storage::extend('database', function ($app, $config) {
            $adapter = new \App\Core\Storage\DatabaseFilesystemAdapter($config['disk_key'] ?? 'local');
            $flysystem = new \League\Flysystem\Filesystem($adapter, $config);

            return new \Illuminate\Filesystem\FilesystemAdapter($flysystem, $adapter, $config);
        });

        // Propagate the tenant to queued jobs: capture the active company on the
        // job payload at dispatch, and re-apply it (app scope + Postgres RLS) while
        // the job runs — so a job writes rows tagged to the right company instead
        // of NULL (invisible under RLS) and reads scoped to that tenant.
        \Illuminate\Support\Facades\Queue::createPayloadUsing(function () {
            return ['tenant_company_id' => app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id()];
        });
        //
        // What was active before the job is remembered and put back afterwards, rather than the
        // tenant simply being blanked. On a worker there is nothing to put back and the two are the
        // same — but on the sync connection the "job" runs inside somebody's request, and clearing
        // the tenant leaves the rest of that request writing rows with a null company, which row
        // level security then makes invisible.
        $tenantBeforeJob = [];

        Event::listen(\Illuminate\Queue\Events\JobProcessing::class, function ($event) use (&$tenantBeforeJob) {
            $tenantBeforeJob[spl_object_id($event->job)] = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();

            $cid = $event->job->payload()['tenant_company_id'] ?? null;
            app(\App\Modules\Tenancy\Services\TenantContext::class)->apply($cid !== null ? (int) $cid : null);
        });

        $restore = function ($event) use (&$tenantBeforeJob) {
            $key = spl_object_id($event->job);
            $before = $tenantBeforeJob[$key] ?? null;
            unset($tenantBeforeJob[$key]);

            app(\App\Modules\Tenancy\Services\TenantContext::class)->apply($before);
        };
        Event::listen(\Illuminate\Queue\Events\JobProcessed::class, $restore);
        Event::listen(\Illuminate\Queue\Events\JobFailed::class, $restore);

        // Delivery log for outbound notifications → powers the System monitor's
        // notification panel and per-company delivery reporting.
        $logNotification = function (string $channel, string $status, $notification, $notifiable, ?string $error) {
            if (! \Illuminate\Support\Facades\Schema::hasTable('notification_logs')) {
                return;
            }
            try {
                $cid = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();
                if (! $cid && is_object($notifiable) && isset($notifiable->company_id)) {
                    $cid = $notifiable->company_id;
                }
                \Illuminate\Support\Facades\DB::table('notification_logs')->insert([
                    'company_id'      => $cid,
                    'channel'         => \Illuminate\Support\Str::limit((string) $channel, 40, ''),
                    'status'          => $status,
                    'notification'    => $notification ? class_basename($notification) : null,
                    'notifiable_type' => is_object($notifiable) ? get_class($notifiable) : null,
                    'notifiable_id'   => is_object($notifiable) && isset($notifiable->id) ? $notifiable->id : null,
                    'error'           => $error ? \Illuminate\Support\Str::limit($error, 500, '') : null,
                    'created_at'      => now(),
                ]);
            } catch (\Throwable) {
                // Logging must never break the actual notification flow.
            }
        };
        Event::listen(\Illuminate\Notifications\Events\NotificationSent::class, function ($e) use ($logNotification) {
            $logNotification($e->channel, 'sent', $e->notification, $e->notifiable, null);
        });
        Event::listen(\Illuminate\Notifications\Events\NotificationFailed::class, function ($e) use ($logNotification) {
            $err = is_array($e->data ?? null) ? json_encode($e->data) : (string) ($e->data ?? 'failed');
            $logNotification($e->channel, 'failed', $e->notification, $e->notifiable, $err);
        });

        // Stamp every audit entry with the company it belongs to (active company,
        // else the causer's company) so the trail can be scoped per company.
        \Spatie\Activitylog\Models\Activity::creating(function ($activity) {
            // Guard: older migrations/seeders can log activity before the tenancy
            // migration adds activity_log.company_id — don't blow up in that window.
            if (! \Illuminate\Support\Facades\Schema::hasColumn('activity_log', 'company_id')) {
                return;
            }
            if (empty($activity->company_id)) {
                $cid = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();
                if (! $cid && $activity->causer_id) {
                    $cid = \App\Core\User\Models\User::whereKey($activity->causer_id)->value('company_id');
                }
                $activity->company_id = $cid;
            }
        });

        // Supply Chain & Procurement — policy guards
        Gate::policy(\App\Modules\ContractManagement\Models\Contract::class, \App\Modules\ContractManagement\Policies\ContractPolicy::class);
        Gate::policy(\App\Modules\SupplierManagement\Models\Supplier::class, \App\Modules\SupplierManagement\Policies\SupplierPolicy::class);

        // Supplier due diligence is a paid add-on, and the hub cards ask a Gate rather than an
        // entitlement service. Defined here so one answer serves the navigation, the routes and the
        // panels — a gate checked in three places is one that eventually differs in one of them.
        Gate::define('supplier-due-diligence', fn ($user = null) => \App\Modules\SupplierManagement\DueDiligence\Support\DueDiligenceAddon::active());
        Gate::policy(\App\Modules\Budget\Models\Budget::class, \App\Modules\Budget\Policies\BudgetPolicy::class);
        Gate::policy(\App\Modules\Procurement\Models\PurchaseRequest::class, \App\Modules\Procurement\Policies\PurchaseRequestPolicy::class);

        /*
         * Assets — every one of these was written and never registered.
         *
         * Laravel's discovery looks for App\Policies\{Model}Policy, and these live beside their
         * module, so nothing connected them: `$user->can('update', $asset)` never consulted
         * AssetPolicy at all. It answered false for anyone without a bare `update` permission —
         * and true for anyone with one, because Spatie's Gate::before grants any ability whose
         * name matches a held permission. Either way the policy's actual rules never ran, so a
         * disposed asset was editable and the module's authorisation was decorative.
         */
        Gate::policy(\App\Modules\Assets\Models\Assets::class, \App\Modules\Assets\Policies\AssetPolicy::class);
        Gate::policy(\App\Modules\Assets\Models\AssetAssignment::class, \App\Modules\Assets\Policies\AssetAssignmentPolicy::class);
        Gate::policy(\App\Modules\Assets\Models\AssetMaintenanceSchedule::class, \App\Modules\Assets\Policies\AssetMaintenancePolicy::class);
        Gate::policy(\App\Modules\Assets\Models\AssetIncident::class, \App\Modules\Assets\Policies\AssetIncidentPolicy::class);
        Gate::policy(\App\Modules\Assets\Models\AssetMovement::class, \App\Modules\Assets\Policies\AssetMovementPolicy::class);
        Gate::policy(\App\Modules\Assets\Models\AssetDocument::class, \App\Modules\Assets\Policies\AssetDocumentPolicy::class);
        Gate::policy(\App\Modules\Assets\Models\AssetExitClearance::class, \App\Modules\Assets\Policies\AssetExitClearancePolicy::class);
        Gate::policy(\App\Modules\Assets\Models\AssetPurchaseRequest::class, \App\Modules\Assets\Policies\AssetProcurementPolicy::class);
        Gate::policy(\App\Modules\Assets\Models\AssetPolicyException::class, \App\Modules\Assets\Policies\AssetPolicyViolationPolicy::class);
        // NOTE: AssetApproval is claimed by both AssetApprovalPolicy and AssetDisposalPolicy, and
        // a model can have only one. Left unregistered until somebody decides which governs it.

        // Operations & Support — Project Management
        Gate::policy(\App\Modules\Operations\ProjectManagement\Models\Project::class, \App\Modules\Operations\ProjectManagement\Policies\ProjectPolicy::class);

        // Manufacturing & Production (MFG-001/002). FactoryPolicyRules rather than FactoryPolicy,
        // because FactoryPolicy is a model here -- the tolerances a factory is configured with.
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\Factory::class, \App\Modules\Operations\Manufacturing\Policies\FactoryPolicyRules::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\Item::class, \App\Modules\Operations\Manufacturing\Policies\ItemPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\Bom::class, \App\Modules\Operations\Manufacturing\Policies\BomPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\Routing::class, \App\Modules\Operations\Manufacturing\Policies\RoutingPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\ProductionOrder::class, \App\Modules\Operations\Manufacturing\Policies\ProductionOrderPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\Inspection::class, \App\Modules\Operations\Manufacturing\Policies\InspectionPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\InspectionPlan::class, \App\Modules\Operations\Manufacturing\Policies\InspectionPlanPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\Nonconformance::class, \App\Modules\Operations\Manufacturing\Policies\NonconformancePolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\DowntimeEvent::class, \App\Modules\Operations\Manufacturing\Policies\DowntimeEventPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\FloorEscalation::class, \App\Modules\Operations\Manufacturing\Policies\FloorEscalationPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\Recall::class, \App\Modules\Operations\Manufacturing\Policies\RecallPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle::class, \App\Modules\Operations\Manufacturing\Policies\AgriculturePolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\Agriculture\Incident::class, \App\Modules\Operations\Manufacturing\Policies\CommandCentrePolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation::class, \App\Modules\Operations\Manufacturing\Policies\IntelligencePolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\StandardCost::class, \App\Modules\Operations\Manufacturing\Policies\StandardCostPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\MachineProfile::class, \App\Modules\Operations\Manufacturing\Policies\MachineProfilePolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\PlanRun::class, \App\Modules\Operations\Manufacturing\Policies\PlanRunPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\RosterEntry::class, \App\Modules\Operations\Manufacturing\Policies\RosterEntryPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\CustomerCommitment::class, \App\Modules\Operations\Manufacturing\Policies\CustomerCommitmentPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\SubcontractOrder::class, \App\Modules\Operations\Manufacturing\Policies\SubcontractOrderPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\LossFinding::class, \App\Modules\Operations\Manufacturing\Policies\LossFindingPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Support\ManufacturingReport::class, \App\Modules\Operations\Manufacturing\Policies\ManufacturingReportPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\NotificationRule::class, \App\Modules\Operations\Manufacturing\Policies\NotificationRulePolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\AssistantQuery::class, \App\Modules\Operations\Manufacturing\Policies\AssistantQueryPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\AccountingRule::class, \App\Modules\Operations\Manufacturing\Policies\AccountingRulePolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\Transfer::class, \App\Modules\Operations\Manufacturing\Policies\TransferPolicy::class);
        Gate::policy(\App\Modules\Operations\Manufacturing\Models\Integration::class, \App\Modules\Operations\Manufacturing\Policies\IntegrationPolicy::class);
        // MFG-017: finished stock held for a sales line is let go as Finance delivers, invoices or
        // cancels the order, and when warehouse fulfilment takes the line over for picking.
        \App\Modules\Finance\Accounting\Models\FinSalesOrder::updated(fn ($o) => app(\App\Modules\Operations\Manufacturing\Services\CustomerOrders::class)->reconcile((int) $o->id));
        \App\Modules\Finance\Accounting\Models\FinSalesOrderLine::updated(fn ($l) => app(\App\Modules\Operations\Manufacturing\Services\CustomerOrders::class)->reconcile((int) $l->order_id));
        if (class_exists(\App\Modules\Operations\WarehouseManagement\Models\FulfilmentLine::class)) {
            \App\Modules\Operations\WarehouseManagement\Models\FulfilmentLine::created(function ($line) {
                if ($line->source_line_id && \Illuminate\Support\Facades\DB::table('wms_orders')->where('id', $line->order_id)->value('source') === 'SALES_ORDER') {
                    app(\App\Modules\Operations\Manufacturing\Services\CustomerOrders::class)->handOver((int) $line->source_line_id);
                }
            });
        }
        // Farm compliance is SHEQ's legal register: the farm adds evidence only its records can give.
        // Time and attendance runs on farms as sites: their time zones, who may reach them, and the
        // field, job and farm records an attendance session points at.
        \App\Modules\HR\Hr\Attendance\Support\Sites::register(
            fn (?int $farmId) => \App\Modules\Agri\Support\FarmTime::zone($farmId),
            fn () => app(\App\Modules\Agri\Services\FarmAccess::class)->farmIdsFor(),
            ['site' => \App\Modules\Agri\Models\Farm::class, 'field' => \App\Modules\Agri\Models\FarmField::class, 'work_order' => \App\Modules\Agri\Models\FarmWorkOrder::class],
            fn ($farm) => app(\App\Modules\Agri\Services\FarmRegistry::class)->assertOperational($farm),
        );
        \App\Modules\HR\Hr\Attendance\Support\Sites::hooks([
            'can_write' => fn ($user, int $farmId) => app(\App\Modules\Agri\Services\FarmAccess::class)->canWrite($user, $farmId),
            'options' => fn (bool $operationalOnly) => \App\Modules\Agri\Models\Farm::query()->when($operationalOnly, fn ($q) => $q->operational())->orderBy('name')
                ->with(['fields' => fn ($q) => $q->where('status', '!=', 'archived')->orderBy('code')])->get()
                ->map(fn ($f) => ['id' => $f->uuid, 'code' => $f->code, 'name' => $f->name, 'status' => $f->status, 'warehouse_id' => $f->warehouse_id,
                    'fields' => $f->fields->map(fn ($x) => ['id' => $x->uuid, 'code' => $x->code, 'name' => $x->name, 'hectares' => $x->hectares()])->values()])->values()->all(),
        ]);
        \App\Modules\Agri\Services\FarmCompliance::registerEvidence();
        // Insights: the farm's reports can be sent on a schedule from Farm Operations › Insights.
        foreach (\App\Modules\Agri\Http\Controllers\AgriReportController::REPORTS as $key => [$title, $description, $ability]) {
            \App\Shared\Insights\ReportCatalog::register("agri:{$key}", $title, $description, 'agri', [\App\Modules\Agri\Support\RoleCatalog::VIEW_REPORTS, $ability],
                fn ($as, string $from, string $to, array $params) => app(\App\Modules\Agri\Http\Controllers\AgriReportController::class)->build($key,
                    ! empty($params['farm']) ? \App\Modules\Agri\Models\Farm::query()->where('uuid', $params['farm'])->value('id') : null, $from, $to),
                ['farm' => 'farm']);
        }
        // A produce lot in a store follows its stock lot, whichever module moves the stock (a
        // warehouse pick or shipment, a production run, a recall hold).
        \App\Modules\Finance\Accounting\Models\FinStockMovement::created(function ($m) {
            static $ready = null;
            $lots = array_filter([$m->lot_id, $m->to_lot_id]);
            if ($lots && ($ready ??= \Illuminate\Support\Facades\Schema::hasColumn('produce_lots', 'stock_lot_id'))) {
                foreach (\App\Modules\Agri\Models\ProduceLot::withoutGlobalScopes()->whereIn('stock_lot_id', $lots)->get() as $lot) {
                    app(\App\Modules\Agri\Services\PostHarvest::class)->syncFromStock($lot);
                }
            }
        });
        // Farm jobs can be gated by a SHEQ inspection (spraying, work at height, …).
        \App\Modules\Operations\Sheq\Services\InspectionGates::offer(\App\Modules\Operations\Sheq\Services\InspectionGates::SUBJECT_WORK_ORDER, 'Farm jobs',
            collect(\App\Modules\Agri\Models\FarmWorkOrder::CATEGORIES)->mapWithKeys(fn ($c) => [$c => ucfirst(str_replace('_', ' ', $c))])->all());
        // Farm sales are Accounting quotes, orders and invoices: a person raising one in Accounting
        // charges it to a farm they can reach, and its income lands on that farm's dimensions.
        \App\Modules\Finance\Accounting\Support\ChargeTo::register('agri_farm',
            fn () => \App\Modules\Agri\Models\Farm::query()->operational()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn ($f) => ['value' => "agri_farm:{$f->uuid}", 'title' => "Farm: {$f->name}"])->all(),
            fn (string $uuid) => \Illuminate\Support\Str::isUuid($uuid) && ($farm = \App\Modules\Agri\Models\Farm::query()->where('uuid', $uuid)->first())
                ? \App\Modules\Agri\Services\AgriLedger::dimensions($farm, null, null, 'produce') : null);
        Gate::policy(\App\Modules\Operations\ProjectManagement\Models\Task::class, \App\Modules\Operations\ProjectManagement\Policies\TaskPolicy::class);
        Gate::policy(\App\Modules\Operations\ProjectManagement\Models\EvidenceEvent::class, \App\Modules\Operations\ProjectManagement\Policies\EvidencePolicy::class);
        Gate::policy(\App\Modules\Operations\DocumentManagement\Models\Document::class, \App\Modules\Operations\DocumentManagement\Policies\DocumentPolicy::class);

        // Auto-generate baseline how-to content when a new ribbon (feature) ships,
        // so the Mkhaya AI bot / how-to page can answer about it immediately.
        \App\Core\Ribbon\Models\Ribbon::observe(\App\Core\Ribbon\Observers\RibbonObserver::class);

        // Fleet spending reaches the budget as it is recorded. An observer rather than four calls
        // across two controllers, because the write path that gets missed is the one that leaves a
        // budget quietly short.
        \App\Modules\Fleet\Models\FleetVehicleGeneralCost::observe(\App\Modules\Fleet\Observers\FleetBudgetObserver::class);
        \App\Modules\Fleet\Models\FleetFuelLog::observe(\App\Modules\Fleet\Observers\FleetBudgetObserver::class);

        // A contract commits the budget the moment both parties have signed. Hung off the envelope
        // because signature completion happens inside ESign, but owned by the contract module so
        // ESign keeps knowing nothing about contracts or budgets.
        \App\Modules\ESign\Models\Envelope::observe(\App\Modules\ContractManagement\Observers\ContractSignatureBudgetObserver::class);

        // CRM — fire "{entity}.updated" automation events carrying the list of
        // changed fields, so workflows can trigger on a record/field update. The
        // automation engine writes via saveQuietly(), so workflow-driven updates
        // never re-trigger (no loops).
        $crmUpdatable = [
            \App\Modules\CRM\Models\CrmLead::class    => 'lead',
            \App\Modules\CRM\Models\CrmDeal::class    => 'deal',
            \App\Modules\CRM\Models\CrmContact::class => 'contact',
            \App\Modules\CRM\Models\CrmAccount::class => 'account',
        ];
        foreach ($crmUpdatable as $model => $type) {
            $model::updated(function ($entity) use ($type) {
                $changed = array_values(array_diff(array_keys($entity->getChanges()), ['updated_at']));
                if (empty($changed)) {
                    return;
                }
                app(\App\Modules\CRM\Services\CrmEventBus::class)->fire("{$type}.updated", $entity, ['changed' => $changed]);
            });
        }

        // Register Azure AD Socialite provider
        Event::listen(SocialiteWasCalled::class, \SocialiteProviders\Azure\AzureExtendSocialite::class . '@handle');

        // Default limiter for the `api` middleware group (referenced by
        // bootstrap/app.php as throttle:api). Keyed by authenticated user, else IP.
        RateLimiter::for('api', fn (Request $r) =>
            Limit::perMinute(120)->by($r->user()?->id ?: $r->ip())
        );

        RateLimiter::for('recruitment.cv-upload', fn (Request $r) =>
            Limit::perMinute(5)->by($r->user()?->id ?: $r->ip())
        );

        RateLimiter::for('recruitment.ai-scoring', fn (Request $r) =>
            Limit::perMinute(10)->by($r->user()?->id ?: $r->ip())
        );

        RateLimiter::for('esign.otp-request', fn (Request $r) =>
            Limit::perMinute(5)->by($r->ip())
        );

        RateLimiter::for('esign.otp-verify', fn (Request $r) =>
            Limit::perMinute(10)->by($r->ip())
        );

        /*
         * The candidate offer and contract-signing portals.
         *
         * Both were named on their routes and defined nowhere, and Laravel throws
         * MissingRateLimiterException rather than waving an unknown limiter through — so requesting
         * an OTP, accepting an offer and signing a contract all answered 500. The candidate side of
         * hiring could not be completed at all.
         *
         * Keyed by IP and the offer or signature request in the URL, not by IP alone: a recruitment
         * agency sending ten offers from one office would otherwise lock out its own candidates.
         * The allowances match the e-sign portal above, which is the same shape of flow.
         */
        RateLimiter::for('recruitment.otp-request', fn (Request $r) =>
            Limit::perHour(3)->by($r->ip() . '|' . $r->route('offerUuid', $r->route('signatureRequestUuid', '')))
        );

        RateLimiter::for('recruitment.otp-verify', fn (Request $r) =>
            Limit::perMinute(10)->by($r->ip() . '|' . $r->route('offerUuid', $r->route('signatureRequestUuid', '')))
        );

        Event::listen('auth.inactivity.logout', function ($user) {
            Log::info("User {$user->id} ({$user->name}) logged out due to inactivity.");
        });

        Event::listen(OfferAccepted::class, ProcessOfferAccepted::class);
        Event::listen(CandidateConverted::class, TriggerOnboarding::class);
        Event::listen(InterviewScheduled::class, SendInterviewScheduledNotification::class);
        Event::listen(CandidateApplied::class, SendApplicationConfirmation::class);
        Event::listen(ShortlistGenerated::class, NotifyHrOnShortlistGenerated::class);
        Event::listen(
            \App\Modules\HR\Recruitment\Events\RequisitionApproved::class,
            \App\Modules\HR\Recruitment\Listeners\NotifyHrOnRequisitionApproval::class,
        );
        Event::listen(ApprovalRequested::class, NotifyApproverOnPendingDecision::class);
        Event::listen(DataBreachReported::class, NotifyOnDataBreach::class);

        // Mirror every outgoing email (raw Mailables / Mail::send) into an in-app
        // notification for recipients who are users. Notification-originated mail
        // is skipped — those already create their own in-app row.
        Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            \App\Shared\Listeners\MirrorEmailToNotification::class
        );

        /*
         * Every outgoing email, written down: what it was, who it was for, and whether the
         * transport took it. Logged on the way in and completed on the way out, because a transport
         * that throws produces MessageSending and never MessageSent — so a failed send leaves a
         * record of itself without anything having to catch it.
         */
        Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            [\App\Shared\Listeners\RecordEmailDelivery::class, 'sending']
        );
        Event::listen(
            \Illuminate\Mail\Events\MessageSent::class,
            [\App\Shared\Listeners\RecordEmailDelivery::class, 'sent']
        );

        $this->loadMigrationsFrom([
            database_path('migrations'),

            // Core tables first: users, roles, companies, base setup
            database_path('migrations/core'),

            // Compliance infrastructure (depends on users)
            database_path('migrations/compliance'),

            // HR depends on users/core
            database_path('migrations/hr'),

            // Security depends on users/roles/permissions
            database_path('migrations/security'),

            // Settings/configuration after core/security
            database_path('migrations/settings'),

            // Remaining modules
            database_path('migrations/ai'),
            database_path('migrations/assets'),
            database_path('migrations/audit'),
            database_path('migrations/documents'),
            database_path('migrations/leaves'),
            database_path('migrations/policies'),
            database_path('migrations/resources'),
            database_path('migrations/contentManagement'),
            database_path('migrations/recruitment'),
            database_path('migrations/recruitment_screening'),
            database_path('migrations/attendance'),
            database_path('migrations/fleet'),
            database_path('migrations/performance'),
            database_path('migrations/payroll'),
            database_path('migrations/disciplinary'),
            database_path('migrations/lms'),
            database_path('migrations/offboarding'),

            // Finance modules
            database_path('migrations/finance'),
            database_path('migrations/claims'),
            database_path('migrations/petty_cash'),
            database_path('migrations/staff_loan'),

            // E-Sign — standalone document signing module
            database_path('migrations/esign'),

            // Supply Chain & Procurement
            database_path('migrations/supplier_management'),
            database_path('migrations/contract_management'),
            database_path('migrations/budget_management'),
            database_path('migrations/procurement'),
            // Procurement Governance sits on top of the four above (it links to their records
            // without constraining to them), so it loads after them.
            database_path('migrations/procurement_governance'),

            // Operations & Support — Project Management (depends on core + tenancy)
            database_path('migrations/project_management'),

            // Operations & Support — SHEQ (depends on core, tenancy, HR, LMS, Fleet, Assets)
            database_path('migrations/sheq'),

            // Operations & Support — Manufacturing & Production. Last because it extends the
            // finance item master (fin_stock_items, fin_warehouses) and the asset register.
            database_path('migrations/manufacturing'),

            // Workflow & Automation — the central engine every module can route work through.
            // Last: it references users, company units and HR, and modules call into it.
            database_path('migrations/workflow'),

            // Mkhaya Agri — the farm hierarchy and farm operations. Last: farms link to company
            // units, asset locations and warehouses created above.
            database_path('migrations/agri'),
        ]);


    }
}
