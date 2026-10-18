<?php

namespace App\Modules\SupplierManagement\Services;

use App\Modules\SupplierManagement\Jobs\VerifyNewSupplierJob;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierAppraisal;
use App\Modules\SupplierManagement\Models\SupplierKycApproval;
use App\Modules\SupplierManagement\Models\SupplierSetting;
use App\Modules\SupplierManagement\Notifications\KycReviewRequested;
use App\Modules\SupplierManagement\Notifications\SupplierDecisionNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class SupplierService
{
    /**
     * Paginated, filtered supplier listing.
     */
    public function getSuppliers(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return Supplier::query()
            ->with(['industries', 'categories'])
            ->withCount(['contacts', 'products'])
            /*
             * The newest score each supplier was given, as one correlated subquery rather than a
             * relation loaded per row. The directory is where somebody decides who to place work
             * with, and it carried no indication of how the last work went.
             */
            ->addSelect(['last_appraisal_score' => SupplierAppraisal::query()
                ->selectRaw('total_score')
                ->whereColumn('supplier_id', 'suppliers.id')
                ->whereNull('deleted_at')
                ->latest('appraisal_date')
                ->latest('id')
                ->limit(1),
            ])
            ->filter($filters)
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Refuse a registration number or email this tenant already has a supplier for.
     *
     * The onboarding endpoint has always checked this; creating a supplier directly did not, and
     * there was no index behind either. Two records for one company means two sets of banking
     * details, two approval trails, and spend that never aggregates — and it is the second set of
     * banking details that makes this a fraud surface rather than an untidiness.
     *
     * @throws ValidationException
     */
    public function assertNotDuplicate(array $data, ?int $ignoreId = null): void
    {
        $registration = trim((string) ($data['company_registration_number'] ?? ''));
        $email        = trim((string) ($data['email'] ?? ''));

        $existing = Supplier::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function ($q) use ($registration, $email) {
                $q->when($registration !== '', fn ($q) => $q->orWhere('company_registration_number', $registration))
                  ->when($email !== '', fn ($q) => $q->orWhere('email', $email));
            })
            ->when($registration === '' && $email === '', fn ($q) => $q->whereRaw('1 = 0'))
            ->first();

        if (! $existing) {
            return;
        }

        $field = ($registration !== '' && $existing->company_registration_number === $registration)
            ? 'company_registration_number'
            : 'email';

        throw ValidationException::withMessages([
            $field => __('supplier_management.suppliers.errors.duplicate', [
                'supplier' => $existing->company_name,
                'number'   => $existing->supplier_number ?: $existing->id,
            ]),
        ]);
    }

    /**
     * Refuse a party that is on the blacklist.
     *
     * Checked here rather than after the supplier exists, because the point of a bar is to stop the
     * application. A check that only runs against records already in the directory is defeated by the
     * oldest move there is: deregister the company, register a new one next week with the same
     * directors at the same address, and apply again — so the people and the ownership named on the
     * form are checked too.
     *
     * Only enforced where the due-diligence add-on is on. A tenant without it has no blacklist and
     * must not have its onboarding slowed by a check that can never match.
     *
     * The message names what matched and why, because "blocked" with no explanation is the kind of
     * refusal that gets escalated, overridden and then ignored.
     *
     * @throws ValidationException
     */
    public function assertNotBlacklisted(array $data): void
    {
        if (! \App\Modules\SupplierManagement\DueDiligence\Support\DueDiligenceAddon::active()) {
            return;
        }

        $people = collect($data['directors'] ?? [])
            ->map(fn ($director) => trim(($director['first_name'] ?? '').' '.($director['surname'] ?? '')))
            ->filter()
            ->values()
            ->all();

        $result = app(\App\Modules\SupplierManagement\DueDiligence\Services\Blacklist::class)->checkDetails(
            $data['company_name'] ?? null,
            $data['company_registration_number'] ?? null,
            $people,
        );

        if (! $result['blocked']) {
            return;
        }

        $match = $result['matches'][0];

        throw ValidationException::withMessages([
            'company_name' => __('supplier_management.suppliers.errors.blacklisted', [
                'matched' => $match['matched'],
                'reason' => $match['reason'],
            ]),
        ]);
    }

    /**
     * Create a supplier with optional nested contacts / directors / products.
     */
    public function createSupplier(array $data, ?int $userId = null): Supplier
    {
        $this->assertNotDuplicate($data);
        $this->assertNotBlacklisted($data);

        $supplier = DB::transaction(function () use ($data, $userId) {
            $supplier = Supplier::create(array_merge(
                $this->onlySupplierFields($data),
                ['created_by' => $userId]
            ));

            foreach ($data['contacts'] ?? [] as $contact) {
                $supplier->contacts()->create($contact);
            }
            foreach ($data['directors'] ?? [] as $director) {
                $supplier->directors()->create($director);
            }
            foreach ($data['products'] ?? [] as $product) {
                $supplier->products()->create($product);
            }
            if (! empty($data['industry_ids'])) {
                $supplier->industries()->sync($data['industry_ids']);
            }
            if (! empty($data['category_ids'])) {
                $supplier->categories()->sync($data['category_ids']);
            }

            return $supplier;
        });

        /*
         * Check them now, rather than whenever somebody opens the record.
         *
         * Everything needed to verify a supplier existed — the registry, the engine, the risk
         * scoring, the case workflow, the nightly sweep — and nothing called any of it at the one
         * moment it matters most. A new supplier sat in the directory unchecked until a person
         * pressed a button or the overnight sweep reached it, and until then the only evidence the
         * company was real was its own word for it.
         *
         * Hooked here because this is the only road: the registration form and an administrator
         * adding a supplier by hand both arrive at this method, and hooking either caller alone would
         * leave the other unchecked.
         *
         * After the transaction, not inside it, so the row is committed before a worker goes looking
         * for it. Queued, because these are half a dozen calls to other people's servers and an
         * applicant should not wait on SARS to find out their form was accepted.
         */
        VerifyNewSupplierJob::dispatch($supplier->id, $supplier->company_id);

        return $supplier;
    }

    public function updateSupplier(Supplier $supplier, array $data): Supplier
    {
        $supplier->update($this->onlySupplierFields($data));

        return $supplier;
    }

    /**
     * Activate a supplier.
     *
     * If global KYC is enabled the supplier moves to "pending KYC" (is_active = 3)
     * and a KYC approval record is created. Otherwise it becomes active (is_active = 1).
     */
    public function activate(Supplier $supplier): Supplier
    {
        // Compulsory paperwork is checked here rather than described on a checklist and ignored. A
        // supplier activated without it is one the rest of the system will treat as vetted.
        $blockers = app(SupplierComplianceService::class)->blockers($supplier);

        if ($blockers->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documents' => __('supplier_management.suppliers.errors.missing_documents', [
                    'documents' => $blockers->implode(', '),
                ]),
            ]);
        }

        if ($this->kycEnabled()) {
            $supplier->update(['is_active' => 3]);

            $approval = SupplierKycApproval::create([
                'supplier_id'   => $supplier->id,
                'supplier_name' => $supplier->company_name,
                'status'        => 'Pending',
                'submitted_at'  => now(),
                'requested_by'  => Auth::id(),
            ]);

            $this->notifyKycApprovers($approval);
        } else {
            $supplier->update(['is_active' => 1]);

            $this->notifySupplier($supplier, 'approved');
        }

        return $supplier;
    }

    public function refuse(Supplier $supplier, ?string $reason = null): Supplier
    {
        $supplier->update(['is_active' => 2]);

        $this->notifySupplier($supplier, 'refused', $reason);

        return $supplier;
    }

    /**
     * Ask the supplier for what is missing, and say what it is.
     *
     * This used to return the supplier untouched behind a success message — the button reported that
     * something had happened and nothing had. It now puts the supplier back to pending, so the
     * directory shows an application genuinely waiting on them, and tells them which documents.
     */
    public function requestInfo(Supplier $supplier, ?string $note = null): Supplier
    {
        $supplier->update(['is_active' => 0]);

        $outstanding = app(SupplierComplianceService::class)->blockers($supplier);

        $this->notifySupplier($supplier, 'information_requested', $note ?: ($outstanding->isNotEmpty()
            ? __('supplier_management.suppliers.outstanding_documents', ['documents' => $outstanding->implode(', ')])
            : null));

        return $supplier;
    }

    /**
     * Replace the supplier's portal credential and set it a lifetime.
     *
     * The old token stops working the instant this returns — that is the point. The new one carries
     * an expiry the portal resolver actually checks, so a link that leaks has a horizon even if
     * nobody remembers to revoke it.
     */
    public function rotatePortalToken(Supplier $supplier): Supplier
    {
        $supplier->forceFill([
            'portal_token'            => \Illuminate\Support\Str::random(48),
            'portal_token_expires_at' => now()->addDays((int) config('suppliers.portal_token_days', 90)),
            'portal_token_rotated_at' => now(),
        ])->save();

        $this->notifySupplier($supplier, 'portal_reissued');

        return $supplier;
    }

    /**
     * Tell a supplier the outcome of a decision made elsewhere — the KYC queue, for one.
     *
     * Public because the decision and the record of it are made in different places: the queue owns
     * the approval row, this service owns what the supplier is told about it.
     */
    public function notifyDecision(Supplier $supplier, string $decision, ?string $comment = null): void
    {
        $this->notifySupplier($supplier, $decision, $comment);
    }

    /**
     * Mail the supplier's own address, when it has one.
     *
     * Silently skipping a supplier with no email on file is deliberate: a missing address is a data
     * gap, not a reason to fail the decision the buyer just made.
     */
    private function notifySupplier(Supplier $supplier, string $decision, ?string $comment = null): void
    {
        if (blank($supplier->email)) {
            return;
        }

        Notification::route('mail', $supplier->email)
            ->notify(new SupplierDecisionNotification($supplier, $decision, $comment));
    }

    /**
     * Tell this tenant's approvers, and nobody else's.
     *
     * The requester is excluded rather than merely not targeted — they hold the approve permission
     * too, and a mail inviting them to approve their own submission is an instruction to break the
     * control the queue exists to enforce.
     */
    private function notifyKycApprovers(SupplierKycApproval $approval): void
    {
        // Scoped from the record, not from the ambient context.
        //
        // Reading the company from `CurrentCompany` and falling back to the acting user's meant a
        // platform admin — who has neither — skipped the filter entirely, loaded every user on the
        // platform, and sent one tenant's supplier name to approvers at every other company. The
        // supplier itself always knows which tenant it belongs to, so that is the safe source.
        // Without one, nobody is told: silence is recoverable, a leak is not.
        $companyId = $approval->company_id ?? $approval->supplier?->company_id;

        if (! $companyId) {
            return;
        }

        // Asked as a query rather than by loading every user and calling `can()` on each: the
        // permission may also arrive through a role, and a tenant with thousands of staff should not
        // be paged into memory to send one email.
        $approvers = \App\Core\User\Models\User::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when(Auth::id(), fn ($q) => $q->where('id', '!=', Auth::id()))
            ->get()
            ->filter(fn ($user) => $user->can('suppliers.approve'));

        if ($approvers->isEmpty()) {
            return;
        }

        Notification::send($approvers, new KycReviewRequested($approval));
    }

    /**
     * Dashboard statistics.
     */
    public function dashboardStats(): array
    {
        return [
            'total_suppliers'    => Supplier::count(),
            'pending_kyc'        => Supplier::where('is_active', 3)->count(),
            'performance_reviews' => DB::table('supplier_appraisals')->whereNull('deleted_at')->count(),
            // `uuid` because a supplier is addressed by it; selecting only `id` left the dashboard's
            // recent list linking to a route key it did not have.
            'recent'             => Supplier::latest()->limit(5)->get([
                'id', 'uuid', 'company_name', 'supplier_number', 'is_active', 'created_at',
            ]),
        ];
    }

    public function kycEnabled(): bool
    {
        return (bool) (SupplierSetting::query()->value('kyc') ?? false);
    }

    /**
     * Strip nested collection keys, keeping only direct supplier columns.
     */
    private function onlySupplierFields(array $data): array
    {
        return collect($data)
            ->except(['contacts', 'directors', 'products', 'industry_ids', 'category_ids'])
            ->all();
    }
}
