<?php

namespace App\Modules\Finance\Claims\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimAttachment;
use App\Modules\Finance\Claims\Services\ClaimsService;
use App\Modules\Finance\Claims\Traits\ClaimsAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ClaimsPaymentController extends Controller
{
    use ClaimsAuthTrait;

    public function __construct(private readonly ClaimsService $service) {}

    public function index(): Response
    {
        abort_unless($this->isPaymentProcessor(), 403);

        $approved = Claim::with(['employee.person', 'category', 'paidBy', 'items.attachments'])
            ->where('status', 'approved')
            ->latest('approved_at')
            ->get()
            ->map(fn ($c) => array_merge([
                'id'               => $c->id,
                'uuid'             => $c->uuid,
                'reference_number' => $c->reference_number,
                'employee'         => $c->employeeName(),
                'title'            => $c->title,
                'category'         => $c->category?->name ?? '—',
                'is_travel'        => (bool) ($c->category?->is_travel),
                'total_amount'     => (float) $c->total_amount,
                'currency'         => $c->currency,
                'approved_at'      => $c->approved_at?->format('d M Y'),
                'age_days'         => $c->age_in_days,
                'notes'            => $c->notes,
            ], $this->receiptSummary($c)));

        $recentlyPaid = Claim::with(['employee.person', 'category', 'paidBy'])
            ->where('status', 'paid')
            ->latest('paid_at')
            ->limit(20)
            ->get()
            ->map(fn ($c) => [
                'id'               => $c->id,
                'uuid'             => $c->uuid,
                'reference_number' => $c->reference_number,
                'employee'         => $c->employeeName(),
                'title'            => $c->title,
                'category'         => $c->category?->name ?? '—',
                'total_amount'     => (float) $c->total_amount,
                'currency'         => $c->currency,
                'paid_at'          => $c->paid_at?->format('d M Y'),
                'paid_by'          => $this->resolvePersonName($c->paid_by),
                'has_proof'        => $c->attachments()->where('attachment_type', 'payment_proof')->exists(),
            ]);

        return Inertia::render('Modules/Finance/Claims/Payments/Index', [
            'approvedClaims' => $approved,
            'recentlyPaid'   => $recentlyPaid,
            'isAdmin'        => $this->isClaimsAdmin(),
        ]);
    }

    public function process(Request $request, Claim $claim): RedirectResponse
    {
        abort_unless($this->isPaymentProcessor(), 403);
        abort_unless($claim->status === 'approved', 422, 'Only approved claims can be processed for payment.');

        $data = $request->validate([
            'notes'      => 'nullable|string|max:1000',
            'proof_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if ($request->hasFile('proof_file')) {
            $file   = $request->file('proof_file');
            $stored = (new ClaimAttachment())->saveFileToDatabase($file);

            $attachment = new ClaimAttachment([
                'claim_id'        => $claim->id,
                'uuid'            => $stored['uuid'],
                'original_name'   => $stored['original_name'],
                'mime'            => $stored['mime'],
                'type'            => $stored['type'],
                'size'            => $stored['size'],
                'attachment_type' => 'payment_proof',
                'uploaded_by'     => Auth::id(),
            ]);

            $attachment->writeBytes(base64_decode($stored['data']), pathinfo((string) $stored['original_name'], PATHINFO_EXTENSION));
            $attachment->save();
        }

        $this->service->payClaim($claim, [
            'notes'   => $data['notes'] ?? null,
            'paid_by' => Auth::id(),
        ]);

        activity('Claims')
            ->causedBy(Auth::user())
            ->performedOn($claim)
            ->event('paid')
            ->withProperties(['reference_number' => $claim->reference_number, 'total_amount' => $claim->total_amount])
            ->log("Claim {$claim->reference_number} payment processed.");

        return back()->with('success', "Claim {$claim->reference_number} has been processed and marked as paid.");
    }

    private function resolvePersonName(?int $userId): string
    {
        if (! $userId) return '—';
        $name = DB::table('hr_people as p')
            ->where('p.user_id', $userId)
            ->selectRaw("TRIM(p.first_name || ' ' || p.surname) as name")
            ->value('name');
        return $name ?: (DB::table('users')->where('id', $userId)->value('email') ?? "User #{$userId}");
    }

    private function receiptSummary(Claim $c): array
    {
        $requiresReceipt = (bool) ($c->category?->requires_receipt) && ! ($c->category?->is_travel);

        $lineItems = $c->items->map(fn($i) => [
            'id'               => $i->id,
            'description'      => $i->description,
            'amount'           => (float) $i->amount,
            'receipt_required' => $i->receipt_required,
            'receipt_uploaded' => $i->receipt_uploaded,
            'receipts'         => $i->attachments
                ->where('attachment_type', 'receipt')
                ->map(fn($a) => [
                    'id'           => $a->id,
                    'name'         => $a->original_name,
                    'mime'         => $a->mime,
                    'download_url' => $a->uuid ? route('claims.attachments.download', $a->uuid) : null,
                ])->values(),
        ]);

        $missingCount = $lineItems->where('receipt_required', true)->where('receipt_uploaded', false)->count();

        return [
            'receipts_required'     => $requiresReceipt,
            'all_receipts_uploaded' => $missingCount === 0,
            'missing_receipts'      => $missingCount,
            'line_items'            => $lineItems,
        ];
    }
}
