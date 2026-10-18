<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyMonthlyCredit;
use App\Modules\Tenancy\Models\CompanyStorageCredit;
use App\Modules\Tenancy\Models\Payment;
use App\Modules\Tenancy\Models\PaymentGateway;
use App\Modules\Tenancy\Models\TokenRequest;
use App\Modules\Tenancy\Payments\GatewayFactory;
use App\Modules\Tenancy\Services\AiQuota;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\SmsQuota;
use App\Modules\Tenancy\Services\StorageQuota;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Company self-service "Usage & Add-ons": a tenant sees its monthly AI-token and
 * SMS usage, and can request more (admin-approved / billed to subscription) or buy
 * an instant top-up. Scoped to the signed-in user's own company.
 */
class CompanyUsageController extends Controller
{
    public function __construct(
        private readonly AiQuota $ai,
        private readonly SmsQuota $sms,
        private readonly StorageQuota $storage,
    ) {}

    public function index()
    {
        $company = $this->company();
        abort_unless($company, 404);

        return Inertia::render('Tenancy/Usage', [
            'company' => ['id' => $company->id, 'name' => $company->name, 'plan' => $company->plan],
            'ai' => $this->ai->snapshot($company),
            'sms' => $this->sms->snapshot($company),
            'storage' => $this->storage->snapshot($company),
            'requests' => TokenRequest::where('company_id', $company->id)->latest('id')->limit(30)->get()
                ->map(fn (TokenRequest $r) => [
                    'id' => $r->id, 'type' => $r->type, 'amount' => $r->amount, 'mode' => $r->mode,
                    'total_price' => $r->total_price !== null ? (float) $r->total_price : null,
                    'status' => $r->status, 'note' => $r->note, 'created_at' => $r->created_at?->format('Y-m-d H:i'),
                ]),
            'pricing' => [
                'ai' => ['pack' => (int) config('ai.quota.pack_tokens', 100000), 'price' => (float) config('ai.quota.pack_price', 25)],
                'sms' => ['pack' => (int) config('sms.quota.pack_sms', 100), 'price' => (float) config('sms.quota.pack_price', 30)],
                'storage' => ['pack' => (int) config('storage.quota.pack_gb', 5), 'price' => (float) config('storage.quota.pack_price', 49)],
            ],
        ]);
    }

    /** Ask for more (mode: request = free ask, subscription = added to next invoice). */
    public function request(Request $request)
    {
        $company = $this->company();
        abort_unless($company, 404);

        $data = $request->validate([
            'type' => ['required', 'in:ai,sms,storage'],
            'amount' => ['required', 'integer', 'min:1'], // storage: amount is in GB
            'mode' => ['required', 'in:request,subscription'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        TokenRequest::create([
            'company_id' => $company->id,
            'type' => $data['type'],
            'amount' => $data['amount'],
            'mode' => $data['mode'],
            'status' => 'pending',
            'note' => $data['note'] ?? null,
            'requested_by' => Auth::id(),
        ]);

        return back()->with('success', 'Your request has been submitted for review.');
    }

    /** Instant self-service purchase — credit granted immediately, billed to account. */
    public function buy(Request $request)
    {
        $company = $this->company();
        abort_unless($company, 404);

        $data = $request->validate([
            'type' => ['required', 'in:ai,sms,storage'],
            'packs' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $cfg = match ($data['type']) {
            'ai' => ['pack' => (int) config('ai.quota.pack_tokens', 100000), 'price' => (float) config('ai.quota.pack_price', 25)],
            'storage' => ['pack' => (int) config('storage.quota.pack_gb', 5), 'price' => (float) config('storage.quota.pack_price', 49)],
            default => ['pack' => (int) config('sms.quota.pack_sms', 100), 'price' => (float) config('sms.quota.pack_price', 30)],
        };
        $amount = $data['packs'] * $cfg['pack']; // storage: amount is in GB
        $total = $data['packs'] * $cfg['price'];

        // If a payment gateway is active, pay for the top-up online; the credit is
        // granted by the gateway callback once paid. Otherwise (no gateway yet)
        // fall back to granting immediately so the feature still works.
        if (PaymentGateway::active()) {
            $payment = Payment::create([
                'company_id' => $company->id,
                'gateway' => PaymentGateway::active()->code,
                'purpose' => 'topup_'.$data['type'], // topup_ai | topup_sms | topup_storage
                'amount' => $total,
                'status' => 'pending',
                'meta' => ['type' => $data['type'], 'amount' => $amount, 'packs' => $data['packs']],
            ]);
            $gw = PaymentGateway::active();
            $redirect = GatewayFactory::make($gw)->redirect(
                $payment->load('company'),
                route('tenancy.pay.return', $payment->uuid),
                route('tenancy.pay.cancel', $payment->uuid),
                route('tenancy.pay.notify', $gw->code),
            );

            return response()->view('payments.redirect', ['redirect' => $redirect]);
        }

        $tr = TokenRequest::create([
            'company_id' => $company->id,
            'type' => $data['type'],
            'amount' => $amount,
            'mode' => 'instant',
            'unit_price' => $cfg['price'],
            'total_price' => $total,
            'status' => 'granted',
            'requested_by' => Auth::id(),
            'decided_at' => now(),
        ]);

        if ($data['type'] === 'storage') {
            // Storage is a permanent, cumulative credit (GB), not a monthly one.
            CompanyStorageCredit::grantGb($company->id, $amount, 'purchase', "Instant purchase — {$data['packs']} pack(s)", Auth::id());
            $label = "{$amount} GB storage";
            $where = 'added to your storage allowance';
        } else {
            CompanyMonthlyCredit::grant($company->id, $data['type'], $amount, 'purchase', $tr->id, "Instant purchase — {$data['packs']} pack(s)", Auth::id());
            $label = number_format($amount).($data['type'] === 'ai' ? ' AI tokens' : ' SMS');
            $where = "added to this month's allowance";
        }

        return back()->with('success', "Purchased {$label} for R ".number_format($total, 2)." — {$where}.");
    }

    private function company(): ?Company
    {
        // Active company: a real tenant user, or a platform admin viewing-as one.
        $company = app(CurrentCompany::class)->get();
        if ($company) {
            return $company;
        }
        $id = Auth::user()->company_id ?? null;

        return $id ? Company::find($id) : null;
    }
}
