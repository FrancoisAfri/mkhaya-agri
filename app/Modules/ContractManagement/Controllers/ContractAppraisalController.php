<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractAppraisal;
use App\Modules\ContractManagement\Models\ContractAppraisalResponse;
use App\Modules\ContractManagement\Models\ContractAppraisalTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ContractAppraisalController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Contract::class);

        $templates = ContractAppraisalTemplate::with('questions')
            ->latest()
            ->get()
            ->map(fn (ContractAppraisalTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'status' => $t->status,
                'questions' => $t->questions->map(fn ($q) => [
                    'id' => $q->id,
                    'question' => $q->question,
                    'weight_percentage' => $q->weight_percentage,
                ])->values(),
            ]);

        $appraisals = ContractAppraisal::with(['contract', 'template'])
            ->latest()
            ->paginate(20)
            ->through(fn (ContractAppraisal $a) => [
                'id' => $a->id,
                'contract' => $a->contract?->title,
                'template' => $a->template?->name,
                'status' => $a->status,
                'total_score' => $a->total_score,
                'appraisal_date' => $a->appraisal_date?->toDateString(),
            ]);

        return Inertia::render('Modules/ContractManagement/Appraisals/Index', [
            'templates' => $templates,
            'appraisals' => $appraisals,
            // `uuid` because the picker's value becomes the route key for the appraise page.
            'contracts' => Contract::orderBy('title')->get(['id', 'uuid', 'title']),
        ]);
    }

    public function templatesStore(Request $request): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'questions' => ['nullable', 'array'],
            'questions.*.question' => ['required_with:questions', 'string', 'max:500'],
            'questions.*.weight_percentage' => ['nullable', 'numeric'],
        ]);

        $template = ContractAppraisalTemplate::create([
            'name' => $data['name'],
            'status' => $data['status'] ?? 'active',
        ]);

        foreach ($data['questions'] ?? [] as $q) {
            $template->questions()->create([
                'question' => $q['question'],
                'weight_percentage' => $q['weight_percentage'] ?? null,
            ]);
        }

        return back()->with('success', 'Appraisal template created.');
    }

    public function appraiseForm(Contract $contract): Response
    {
        $this->authorize('update', Contract::class);

        return Inertia::render('Modules/ContractManagement/Appraisals/Appraise', [
            'contract' => ['id' => $contract->id, 'uuid' => $contract->uuid, 'title' => $contract->title, 'contract_number' => $contract->contract_number],
            'templates' => ContractAppraisalTemplate::with('questions')
                ->where('status', 'active')
                ->get()
                ->map(fn (ContractAppraisalTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'questions' => $t->questions->map(fn ($q) => [
                        'id' => $q->id,
                        'question' => $q->question,
                        'weight_percentage' => $q->weight_percentage,
                    ])->values(),
                ]),
        ]);
    }

    public function store(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'template_id' => ['nullable', 'integer'],
            'responses' => ['nullable', 'array'],
            'responses.*.question_id' => ['nullable', 'integer'],
            'responses.*.score' => ['nullable', 'numeric'],
            'responses.*.response' => ['nullable', 'string'],
            'responses.*.comment' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data, $contract) {
            $total = collect($data['responses'] ?? [])->sum(fn ($r) => (float) ($r['score'] ?? 0));

            $appraisal = ContractAppraisal::create([
                'contract_id' => $contract->id,
                'template_id' => $data['template_id'] ?? null,
                'user_id' => Auth::id(),
                'status' => 'completed',
                'total_score' => $total,
                'appraisal_date' => now()->toDateString(),
            ]);

            foreach ($data['responses'] ?? [] as $r) {
                ContractAppraisalResponse::create([
                    'appraisal_id' => $appraisal->id,
                    'question_id' => $r['question_id'] ?? null,
                    'response' => $r['response'] ?? null,
                    'score' => $r['score'] ?? null,
                    'comment' => $r['comment'] ?? null,
                ]);
            }

            // TODO (deferred): export appraisal as PDF.
            return redirect()->route('contracts.appraisals.index')
                ->with('success', 'Appraisal recorded.');
        });
    }
}
