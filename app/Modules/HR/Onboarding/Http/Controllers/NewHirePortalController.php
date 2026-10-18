<?php

namespace App\Modules\HR\Onboarding\Http\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Modules\HR\Onboarding\Models\OnboardingDocument;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The new hire's own page, before they have a login.
 *
 * Unauthenticated by design — the token in the URL is the credential, the same arrangement esign,
 * helpdesk and the speaker portal already use. That puts the whole burden on the token, so it is
 * long, unique, expiring, and revocable by re-issuing.
 *
 * Everything here is scoped to the person the token resolves to. There is no id in any of these
 * routes precisely so that there is nothing to tamper with: a hire cannot ask for somebody else's
 * document by changing a number, because no number is ever accepted.
 */
class NewHirePortalController
{
    use FileStorage;

    /**
     * Resolve the token, or 404.
     *
     * The lookup runs untenanted because there is no session and no user to derive a company from —
     * the token is what identifies the tenant, not the other way round. Having found the person,
     * their company is entered for the rest of the request so everything read afterwards is
     * theirs and row-level security is doing its normal job.
     */
    private function resolve(string $token): HrPerson
    {
        $person = app(TenantContext::class)->run(null, fn () => HrPerson::query()
            ->where('portal_token', $token)
            ->first());

        abort_if($person === null || ! $person->portalTokenIsValid(), 404);

        return $person;
    }

    /** Run the rest of the request inside the person's own tenant. */
    private function inTenant(HrPerson $person, callable $work)
    {
        return app(TenantContext::class)->run($person->company_id, $work);
    }

    public function show(string $token): Response
    {
        $person = $this->resolve($token);

        return $this->inTenant($person, function () use ($person, $token) {
            $person->forceFill(['portal_last_seen_at' => now()])->save();

            $plan = $person->onboardingPlans()
                ->whereNotIn('status', ['cancelled'])
                ->with('tasks')
                ->latest()
                ->first();

            return Inertia::render('Modules/Onboarding/NewHirePortal', [
                'token' => $token,
                'person' => [
                    'name' => $person->fullName(),
                    'job_title' => $person->job_title,
                    'department' => $person->department,
                    'starts_on' => $person->started_on?->toDateString(),
                ],
                // Only what the hire themselves is meant to do. The IT and HR tasks on the same
                // plan are not theirs to see, let alone tick off.
                'tasks' => $plan
                    ? $plan->tasks->map(fn ($task) => [
                        'id' => $task->id,
                        'title' => $task->title,
                        'description' => $task->description,
                        'due_date' => $task->due_date?->toDateString(),
                        'status' => $task->status?->value ?? (string) $task->status,
                    ])->values()
                    : [],
                'documents' => $person->onboardingDocuments->map(fn (OnboardingDocument $doc) => [
                    'id' => $doc->id,
                    'label' => $doc->label,
                    'required' => (bool) $doc->is_required,
                    'status' => $doc->status?->value ?? (string) $doc->status,
                    // Whether something has been uploaded, never the file itself: the portal is for
                    // submitting documents, not for retrieving them again later.
                    // Either place the bytes may be, plus the older filesystem path — a
                    // requirement reported as unsubmitted is one the new hire is asked to do again.
                    'submitted' => $doc->hasBytes() || $doc->file_path !== null,
                    'rejection_reason' => $doc->rejection_reason,
                ])->values(),
            ]);
        });
    }

    /** Upload against one of this person's own document requirements. */
    public function upload(Request $request, string $token)
    {
        $person = $this->resolve($token);

        $request->validate([
            'document_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
        ]);

        return $this->inTenant($person, function () use ($request, $person) {
            // Found through the person's own relation rather than by id: an id from the request is
            // a number the sender chose, and looking it up globally would let one hire upload
            // against another's requirement.
            $document = $person->onboardingDocuments()
                ->whereKey($request->integer('document_id'))
                ->firstOrFail();

            $blob = $this->saveFileToDatabase($request->file('file'));

            // Bytes to the shared disk; the row keeps the metadata and a path.
            $document->writeBytes(base64_decode($blob['data']), pathinfo((string) $blob['original_name'], PATHINFO_EXTENSION));

            $document->update([
                'file_data' => null,
                'file_mime' => $blob['mime'],
                'file_type' => $blob['type'],
                'file_size' => $blob['size'],
                'file_name' => $blob['original_name'],
                'file_path' => null,
                'status' => 'submitted',
                // A resubmission answers whatever the last rejection said, so the old reason must
                // not linger next to a new file.
                'rejection_reason' => null,
            ]);

            return back();
        });
    }
}
