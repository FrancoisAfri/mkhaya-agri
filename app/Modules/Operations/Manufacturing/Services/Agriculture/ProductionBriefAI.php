<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\AI\Base\BaseModuleAI;

/**
 * A short written brief of the agents' findings for the Command Centre, metered like every AI call.
 *
 * It is given the deterministic findings only — never raw outside content — and asked to summarise,
 * not to decide. It cannot raise an alarm, send a command or approve anything; when the AI service
 * is down the Command Centre runs on the findings alone.
 */
class ProductionBriefAI extends BaseModuleAI
{
    protected string $module = 'manufacturing';

    public function brief(array $findings): string
    {
        $response = $this->ask(
            'Write a brief (at most five short sentences) for a farm production manager from these findings, most urgent first. '
            .'Only use what is in the findings. Do not recommend equipment commands, products or doses.',
            ['findings' => array_slice($findings, 0, 30)],
            ['task' => 'agri.production_brief', 'humanize' => false],
            'You summarise agricultural production findings for a manager. You never invent figures, and you never give instructions to equipment.',
        );

        return mb_strimwidth(trim((string) $response->content), 0, 1200);
    }

    protected function humanizes(): bool
    {
        return false;
    }
}
