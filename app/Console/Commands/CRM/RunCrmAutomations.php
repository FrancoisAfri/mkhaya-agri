<?php

namespace App\Console\Commands\CRM;

use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmStage;
use App\Modules\CRM\Models\CrmWorkflow;
use App\Modules\CRM\Services\CrmWorkflowEngine;
use Illuminate\Console\Command;

/**
 * Drives the time-based CRM automation triggers that no user action fires:
 *  - deal.inactive      open deals with no activity / no stage change for N days
 *  - deal.sla_breached  open deals past their stage's SLA window
 *
 * Also resumes multi-step flow runs (Phase 3) whose wait has elapsed — so it
 * should run frequently for hour/minute waits to resume promptly:
 *   $schedule->command('crm:run-automations')->everyFifteenMinutes();
 */
class RunCrmAutomations extends Command
{
    protected $signature   = 'crm:run-automations {--inactive-days=14}';
    protected $description = 'Fire time-based CRM automations (deal inactivity and stage SLA breaches)';

    public function handle(CrmWorkflowEngine $engine): int
    {
        $inactiveDays = (int) $this->option('inactive-days');
        $cutoff       = now()->subDays($inactiveDays);
        $fired        = 0;

        // ── Inactivity ────────────────────────────────────────────────────────
        CrmDeal::where('status', 'open')
            ->where(function ($q) use ($cutoff) {
                $q->where('stage_changed_at', '<', $cutoff)->orWhereNull('stage_changed_at');
            })
            ->chunkById(100, function ($deals) use ($engine, $cutoff, &$fired) {
                foreach ($deals as $deal) {
                    $lastActivity = CrmActivity::where('related_type', 'deal')->where('related_id', $deal->id)->max('created_at');
                    if ($lastActivity && $lastActivity >= $cutoff) {
                        continue; // had recent activity
                    }
                    $engine->dispatch('deal.inactive', $deal);
                    $fired++;
                }
            });

        // ── Stage SLA breaches ────────────────────────────────────────────────
        $slaStages = CrmStage::whereNotNull('sla_days')->where('sla_days', '>', 0)->get()->keyBy('id');

        if ($slaStages->isNotEmpty()) {
            CrmDeal::where('status', 'open')->whereIn('stage_id', $slaStages->keys())
                ->chunkById(100, function ($deals) use ($engine, $slaStages, &$fired) {
                    foreach ($deals as $deal) {
                        $sla = $slaStages[$deal->stage_id]->sla_days ?? null;
                        if (! $sla || ! $deal->stage_changed_at) continue;
                        if ($deal->stage_changed_at->addDays($sla)->isPast()) {
                            $engine->dispatch('deal.sla_breached', $deal);
                            $fired++;
                        }
                    }
                });
        }

        // ── Date-based (date_reached) ─────────────────────────────────────────
        $dateWorkflows = CrmWorkflow::where('trigger', 'date_reached')->where('is_active', true)->get();
        foreach ($dateWorkflows as $wf) {
            $cfg    = $wf->trigger_config ?? [];
            $entity = $cfg['entity'] ?? null;
            $field  = $cfg['field'] ?? null;
            if (! $entity || ! $field) {
                continue;
            }

            $model = match ($entity) {
                'deal'    => CrmDeal::class,
                'lead'    => CrmLead::class,
                'contact' => CrmContact::class,
                'account' => CrmAccount::class,
                default   => null,
            };
            // Whitelist the date column — it reaches the query as an identifier.
            if (! $model || ! in_array($field, CrmWorkflow::DATE_FIELDS[$entity] ?? [], true)) {
                continue;
            }

            $target = now()->startOfDay()->addDays((int) ($cfg['days_before'] ?? 0));

            $model::whereDate($field, $target->toDateString())
                ->chunkById(100, function ($rows) use ($engine, $wf, &$fired) {
                    foreach ($rows as $row) {
                        $engine->runForWorkflow($wf, $row);
                        $fired++;
                    }
                });
        }

        // ── Multi-step flows: resume runs whose wait has elapsed ──────────────
        $advanced = $engine->processDueFlows();

        $this->info("CRM automations evaluated. Triggers fired: {$fired}. Flow runs advanced: {$advanced}.");

        return self::SUCCESS;
    }
}
