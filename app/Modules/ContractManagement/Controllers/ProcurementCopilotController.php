<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Services\ProcurementCopilotService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProcurementCopilotController extends Controller
{
    public function __construct(private readonly ProcurementCopilotService $copilot) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Contract::class);

        return Inertia::render('Modules/ContractManagement/Copilot/Index', [
            'suggestions' => $this->copilot->suggestions(),
            'answer' => null,
            'question' => null,
        ]);
    }

    /** Answer a question (returns the same page with the answer; preserves client chat state). */
    public function ask(Request $request): Response
    {
        $this->authorize('viewAny', Contract::class);

        $data = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
            'history' => ['nullable', 'array'],
            'history.*.role' => ['required_with:history', 'string'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        $answer = $this->copilot->answer($data['question'], $data['history'] ?? []);

        return Inertia::render('Modules/ContractManagement/Copilot/Index', [
            'suggestions' => $this->copilot->suggestions(),
            'answer' => $answer,
            'question' => $data['question'],
        ]);
    }
}
