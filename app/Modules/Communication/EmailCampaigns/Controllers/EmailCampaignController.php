<?php

namespace App\Modules\Communication\EmailCampaigns\Controllers;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Http\Controllers\Controller;
use App\Modules\Communication\EmailCampaigns\Jobs\SendCampaignJob;
use App\Modules\Communication\EmailCampaigns\Models\EmailCampaign;
use App\Modules\Communication\EmailCampaigns\Models\EmailCampaignTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmailCampaignController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request): InertiaResponse
    {

        $campaigns = EmailCampaign::with('creator.person')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($c) => [
                'id'               => $c->id,
                'uuid'             => $c->uuid,
                'title'            => $c->title,
                'subject'          => $c->subject,
                'status'           => $c->status,
                'audience_type'    => $c->audience_type,
                'scheduled_at'     => $c->scheduled_at?->toDateTimeString(),
                'sent_at'          => $c->sent_at?->toDateTimeString(),
                'total_recipients' => $c->total_recipients,
                'sent_count'       => $c->sent_count,
                'open_count'       => $c->open_count,
                'open_rate'        => $c->openRate(),
                'created_at'       => $c->created_at->toDateTimeString(),
                'created_by'       => $c->creator?->name,
            ]);

        return Inertia::render('Modules/Communication/EmailCampaigns/Index', [
            'campaigns'   => $campaigns,
            'departments' => CompanyUnit::where('type', 'department')
                ->where('active', 1)
                ->orderBy('name')
                ->get(['id', 'name']),
            'templates'   => EmailCampaignTemplate::orderBy('title')
                ->get(['id', 'title', 'subject', 'from_name', 'from_email', 'body']),
        ]);
    }

    // ── Show (detail + recipients) ────────────────────────────────────────────

    public function show(EmailCampaign $campaign): InertiaResponse
    {
        $recipients = $campaign->recipients()
            ->orderByDesc('sent_at')
            ->paginate(50)
            ->through(fn ($r) => [
                'id'         => $r->id,
                'email'      => $r->email,
                'name'       => $r->name,
                'sent_at'    => $r->sent_at?->toDateTimeString(),
                'opened_at'  => $r->opened_at?->toDateTimeString(),
            ]);

        return Inertia::render('Modules/Communication/EmailCampaigns/Show', [
            'campaign'   => array_merge(
                $campaign->only('id', 'uuid', 'title', 'subject', 'from_name', 'from_email', 'body',
                    'status', 'audience_type', 'scheduled_at', 'sent_at', 'total_recipients', 'sent_count', 'open_count'),
                ['open_rate' => $campaign->openRate()],
            ),
            'recipients' => $recipients,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function stores(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $campaign = EmailCampaign::create(array_merge($data, ['created_by' => Auth::id()]));

        return response()->json(['id' => $campaign->id, 'uuid' => $campaign->uuid], 201);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, EmailCampaign $campaign): JsonResponse
    {
        abort_if($campaign->isSent() || $campaign->isSending(), 422, 'Cannot edit a sent or sending campaign.');

        $campaign->update($this->validated($request));

        return response()->json(['ok' => true]);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function destroy(EmailCampaign $campaign): JsonResponse
    {
        abort_if($campaign->isSending(), 422, 'Cannot delete a campaign while it is sending.');

        $campaign->delete();

        return response()->json(['ok' => true]);
    }

    // ── Send now ──────────────────────────────────────────────────────────────

    public function send(EmailCampaign $campaign): JsonResponse
    {
        abort_if(!in_array($campaign->status, ['draft', 'scheduled']), 422, 'Campaign cannot be sent in its current state.');

        SendCampaignJob::dispatch($campaign);

        return response()->json(['ok' => true]);
    }

    // ── Schedule ──────────────────────────────────────────────────────────────

    public function schedule(Request $request, EmailCampaign $campaign): JsonResponse
    {
        abort_if(!$campaign->isDraft(), 422, 'Only draft campaigns can be scheduled.');

        $data = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        $campaign->update([
            'status'       => 'scheduled',
            'scheduled_at' => $data['scheduled_at'],
        ]);

        return response()->json(['ok' => true]);
    }

    // ── Cancel schedule ───────────────────────────────────────────────────────

    public function cancel(EmailCampaign $campaign): JsonResponse
    {
        abort_if(!$campaign->isScheduled(), 422, 'Only scheduled campaigns can be cancelled.');

        $campaign->update(['status' => 'draft', 'scheduled_at' => null]);

        return response()->json(['ok' => true]);
    }

    // ── Open-tracking pixel (no auth) ─────────────────────────────────────────

    public function trackOpen(string $token): Response
    {
        $recipient = \App\Modules\Communication\EmailCampaigns\Models\CampaignRecipient::where('open_token', $token)->first();

        if ($recipient && !$recipient->opened_at) {
            $recipient->update(['opened_at' => now()]);
            $recipient->campaign()->increment('open_count');
        }

        // Return 1×1 transparent GIF
        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, [
            'Content-Type'  => 'image/gif',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    // ── Duplicate ─────────────────────────────────────────────────────────────

    public function duplicate(EmailCampaign $campaign): JsonResponse
    {
        $copy = $campaign->replicate(['uuid', 'status', 'scheduled_at', 'sent_at', 'total_recipients', 'sent_count', 'open_count']);
        $copy->title      = 'Copy of ' . $campaign->title;
        $copy->status     = 'draft';
        $copy->created_by = Auth::id();
        $copy->save();

        return response()->json(['id' => $copy->id, 'uuid' => $copy->uuid], 201);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'                          => ['required', 'string', 'max:255'],
            'subject'                        => ['required', 'string', 'max:255'],
            'from_name'                      => ['required', 'string', 'max:100'],
            'from_email'                     => ['required', 'email', 'max:150'],
            'reply_to'                       => ['nullable', 'email', 'max:150'],
            'body'                           => ['required', 'string'],
            'audience_type'                  => ['required', 'in:all,department,custom'],
            'audience_filter'                => ['nullable', 'array'],
            'audience_filter.department_ids' => ['nullable', 'array'],
            'audience_filter.department_ids.*' => ['integer', 'exists:company_units,id'],
            'audience_filter.emails'         => ['nullable', 'array'],
            'audience_filter.emails.*'       => ['email'],
        ]);
    }
}
