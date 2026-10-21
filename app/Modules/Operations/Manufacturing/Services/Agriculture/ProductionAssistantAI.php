<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\AI\Base\BaseModuleAI;

/** Words the production assistant's answer from the facts it was given — and only those. Metered. */
class ProductionAssistantAI extends BaseModuleAI
{
    protected string $module = 'agri';

    public function answer(string $question, array $facts, bool $wantsAction): string
    {
        $r = $this->ask(
            "Question from a farm production manager: \"{$question}\"\n\nAnswer in at most eight short lines, using only the facts given. Name the cycle, house or incident each point is about. "
            .'If the facts do not answer the question, say so plainly.'.($wantsAction ? ' The person asked you to operate equipment: say you cannot, and that a command is requested in Command Centre → Devices & commands and approved by a second person.' : ''),
            ['facts' => $facts],
            ['task' => 'agri.production_assistant', 'humanize' => false],
            'You answer questions about agricultural production from the organisation\'s own records. You never invent figures, never diagnose disease from distance, never say a farm qualifies for funding, and never claim to have done anything.',
        );

        return trim((string) $r->content);
    }

    protected function humanizes(): bool
    {
        return false;
    }
}
