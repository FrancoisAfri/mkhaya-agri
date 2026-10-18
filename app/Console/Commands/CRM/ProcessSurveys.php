<?php

namespace App\Console\Commands\CRM;

use App\Modules\CRM\Models\CrmSurvey;
use App\Modules\CRM\Models\CrmSurveyPlan;
use App\Modules\CRM\Services\CrmAiService;
use App\Modules\CRM\Services\CrmSurveyService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Survey lifecycle automation: sends scheduled surveys when their time arrives,
 * and after a survey's deadline closes it and has AI recommend how to improve
 * the customer experience from the results.
 */
class ProcessSurveys extends Command
{
    use RunsPerTenant;

    protected $signature   = 'crm:process-surveys {--company= : Process only this company} {--sync : Process companies inline instead of fanning out to the queue}';
    protected $description = 'Send scheduled customer surveys and run AI analysis after the deadline';

    public function handle(CrmSurveyService $surveys, CrmAiService $ai): int
    {
        $tenant = app(\App\Modules\Tenancy\Services\TenantContext::class);

        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $this->processCompany($surveys, $ai));
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $this->eachTenant($tenant, fn () => $this->processCompany($surveys, $ai));
            Log::info('crm:process-surveys completed (sync)');
            return self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('crm:process-surveys');
        $this->info("Dispatched survey processing for {$n} company(ies).");
        return self::SUCCESS;
    }

    /** Run the survey lifecycle for the current tenant (context already set). */
    private function processCompany(CrmSurveyService $surveys, CrmAiService $ai): void
    {
        // 1. Run survey plans (planner + recurring) that are now due.
        $plans = CrmSurveyPlan::where('active', true)->where('next_run_at', '<=', now())->get();
        foreach ($plans as $plan) {
            $survey = $surveys->runPlan($plan);
            $this->info("Plan '{$plan->name}' → sent survey '{$survey->name}'.");
        }

        // 2. Dispatch one-off surveys scheduled directly on the survey.
        $due = CrmSurvey::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())->get();
        foreach ($due as $survey) {
            $sent = $surveys->send($survey);
            $this->info("Sent '{$survey->name}' to {$sent} contact(s).");
        }

        // 3. Close + analyse active surveys past their deadline.
        $ended = CrmSurvey::where('status', 'active')
            ->whereNotNull('deadline')->where('deadline', '<', now())->get();
        foreach ($ended as $survey) {
            $survey->update(['status' => 'closed']);

            if ($survey->responses()->exists()) {
                $result = $ai->analyzeSurveys([['survey' => $survey->name, 'results' => $surveys->results($survey)]]);
                if (($result['available'] ?? false) && ! isset($result['error'])) {
                    $survey->update(['ai_recommendations' => json_encode($result), 'ai_analyzed_at' => now()]);
                    $this->info("Closed & analysed '{$survey->name}'.");
                } else {
                    $this->warn("Closed '{$survey->name}' (AI analysis unavailable).");
                }
            }
        }

        Log::info('crm:process-surveys company done', ['plans' => $plans->count(), 'sent' => $due->count(), 'closed' => $ended->count()]);
    }
}
