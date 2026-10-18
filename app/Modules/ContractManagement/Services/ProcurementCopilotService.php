<?php

namespace App\Modules\ContractManagement\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractObligation;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierDocument;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Modules\SupplierManagement\Models\SupplierScreening;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-module procurement copilot: answers natural-language questions that span
 * contracts AND suppliers AND compliance in one place — something point-solutions
 * can't do because they silo CLM from SRM. It assembles a compact, current
 * snapshot of the procurement data graph and lets the AI reason over it.
 */
class ProcurementCopilotService
{
    public function __construct(private readonly AgentRegistry $agents) {}

    private const SYSTEM_PROMPT = <<<'TXT'
You are the Procurement Copilot for an ERP. Answer the user's question using ONLY
the JSON CONTEXT provided (contracts, suppliers, obligations, risk & compliance).
Be concise and specific; cite contract numbers / supplier names where relevant.
If the answer isn't in the context, say what data would be needed. Format the
answer as short markdown (bullets/tables where helpful). Never invent records.
TXT;

    /** Answer a question; $history is [{role, content}, ...]. Returns markdown. */
    public function answer(string $question, array $history = []): string
    {
        $context = $this->buildContext();

        $response = $this->agents->for('contracts')->ask(new AgentRequest(
            prompt: "CONTEXT:\n".json_encode($context, JSON_UNESCAPED_SLASHES)."\n\nQUESTION: ".$question,
            module: 'procurement.copilot',
            context: ['scope' => 'contracts+suppliers'],
            systemPrompt: self::SYSTEM_PROMPT,
            temperature: 0.2,
            maxTokens: 1500,
            responseFormat: 'text',
            history: $history,
        ));

        if ($response->failed()) {
            return 'Sorry — the copilot is unavailable right now ('.($response->errorMessage ?: 'error').').';
        }

        return $response->content;
    }

    /** Suggested starter questions shown in the UI. */
    public function suggestions(): array
    {
        return [
            'Which contracts expire in the next 90 days and what are they worth?',
            'List suppliers with a high screening risk or critical fraud flags.',
            'Which suppliers have compliance documents expiring soon?',
            'What obligations are overdue or due this month?',
            'Show me off-contract risk: active suppliers without a contract.',
        ];
    }

    /** Compact snapshot of the procurement data graph (capped for the prompt). */
    private function buildContext(): array
    {
        $today = Carbon::today();
        $soon = $today->copy()->addDays(120);

        $ctx = [
            'generated_at' => $today->toDateString(),
            'contracts' => [],
            'suppliers' => [],
            'obligations' => [],
        ];

        // ── Contracts ────────────────────────────────────────────────────────
        $ctx['contracts']['by_status'] = Contract::query()
            ->selectRaw('contract_status, count(*) as n, coalesce(sum(contract_value),0) as value')
            ->groupBy('contract_status')->get()
            ->map(fn ($r) => ['status' => $r->contract_status, 'count' => (int) $r->n, 'value' => (float) $r->value])
            ->all();

        $ctx['contracts']['expiring_soon'] = Contract::query()
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $today)
            ->whereDate('end_date', '<=', $soon)
            ->whereIn('contract_status', ['published', 'pending', 'active'])
            ->with('ai:id,contract_id,risk_score')
            ->orderBy('end_date')->limit(25)
            ->get(['id', 'contract_number', 'title', 'end_date', 'contract_value', 'currency', 'supplier_id', 'contract_status'])
            ->map(fn ($c) => [
                'number' => $c->contract_number,
                'title' => $c->title,
                'end_date' => $c->end_date?->toDateString(),
                'value' => $c->contract_value,
                'currency' => $c->currency,
                'risk' => $c->ai?->risk_score,
            ])->all();

        // ── Suppliers ────────────────────────────────────────────────────────
        // Guarded: the Supplier module may be absent in a standalone/self_hosted build.
        if (Schema::hasTable('suppliers')) {
            $ctx['suppliers']['by_status'] = Supplier::query()
                ->selectRaw('is_active, count(*) as n')->groupBy('is_active')->get()
                ->map(fn ($r) => ['is_active' => (int) $r->is_active, 'count' => (int) $r->n])->all();
        }

        if (Schema::hasTable('supplier_screenings')) {
            $ctx['suppliers']['high_risk'] = SupplierScreening::query()
                ->where('risk_level', 'high')->with('supplier:id,company_name')->limit(20)->get()
                ->map(fn ($s) => ['supplier' => $s->supplier?->company_name, 'risk' => $s->risk_level])->all();
        }

        if (Schema::hasTable('supplier_risk_flags')) {
            $ctx['suppliers']['critical_flags'] = SupplierRiskFlag::query()
                ->where('status', 'open')->whereIn('severity', ['high', 'critical'])
                ->with('supplier:id,company_name')->limit(25)->get()
                ->map(fn ($f) => ['supplier' => $f->supplier?->company_name, 'severity' => $f->severity, 'type' => $f->type])->all();
        }

        if (Schema::hasTable('supplier_documents')) {
            $ctx['suppliers']['expiring_documents'] = SupplierDocument::query()
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', $today->copy()->addDays(90))
                ->with(['supplier:id,company_name', 'documentType:id,document_type_name'])
                ->orderBy('expiry_date')->limit(25)->get()
                ->map(fn ($d) => [
                    'supplier' => $d->supplier?->company_name,
                    'document' => $d->documentType?->document_type_name ?? $d->file_name,
                    'expiry' => $d->expiry_date?->toDateString(),
                ])->all();
        }

        // ── Obligations ──────────────────────────────────────────────────────
        $ctx['obligations']['open_due_soon'] = ContractObligation::query()
            ->whereNotIn('status', ['met', 'waived'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $today->copy()->addDays(60))
            ->with('contract:id,contract_number')
            ->orderBy('due_date')->limit(25)->get()
            ->map(fn ($o) => [
                'contract' => $o->contract?->contract_number,
                'description' => $o->description,
                'due' => $o->due_date?->toDateString(),
                'status' => $o->status,
            ])->all();

        return $ctx;
    }
}
