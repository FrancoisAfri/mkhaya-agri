<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Jobs\SendCampaignJob;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmCampaign;
use App\Modules\CRM\Models\CrmCampaignRecipient;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Services\CrmMailService;
use App\Modules\CRM\Services\CrmWhatsAppService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marketing campaigns — email (HTML content, scheduling, open/click tracking,
 * stats) and WhatsApp, with bulk recipient upload.
 */
class CampaignController extends Controller
{
    public function __construct(private CrmWhatsAppService $whatsapp)
    {
    }

    public function index(): Response
    {
        $campaigns = CrmCampaign::withCount('recipients')->latest()->get()
            ->map(fn ($c) => $this->present($c));

        return Inertia::render('Modules/CRM/Campaigns', [
            'campaigns'    => $campaigns,
            'channels'     => CrmCampaign::CHANNELS,
            'statuses'     => CrmCampaign::STATUSES,
            'waConfigured' => $this->whatsapp->isConfigured(),
        ]);
    }

    public function detail(Request $request, CrmCampaign $campaign): Response
    {
        $campaign->loadCount('recipients');

        // Engagement segments — the "who to call" call-list. Hot = opened/clicked.
        $filter = $request->query('filter', 'all');
        $query  = $campaign->recipients();
        match ($filter) {
            'clicked'      => $query->where('click_count', '>', 0),
            'opened'       => $query->whereNotNull('opened_at'),
            'not_opened'   => $query->whereNull('opened_at')->whereIn('status', ['sent', 'pending']),
            'bounced'      => $query->whereIn('status', ['bounced', 'blocked']),
            'complained'   => $query->where('status', 'complained'),
            'unsubscribed' => $query->where('status', 'unsubscribed'),
            default        => $query,
        };

        // Most-engaged first so reps see the best people to call at the top.
        $recipients = $query->orderByDesc('click_count')->orderByDesc('open_count')->orderByDesc('id')
            ->limit(500)->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'email' => $r->email, 'phone' => $r->phone,
                'status' => $r->status, 'opens' => $r->open_count, 'clicks' => $r->click_count,
                'sent_at'         => $r->sent_at?->toDateTimeString(),
                'opened_at'       => $r->opened_at?->toDateTimeString(),
                'last_clicked_at' => $r->last_clicked_at?->toDateTimeString(),
                'bounce_reason'   => $r->bounce_reason,
                'contact_id'      => $r->contact_id, 'lead_id' => $r->lead_id,
            ]);

        return Inertia::render('Modules/CRM/CampaignDetail', [
            'campaign'          => array_merge($this->present($campaign), ['content' => $campaign->content, 'wa_template' => $campaign->wa_template]),
            'recipients'        => $recipients,
            'stats'             => $this->stats($campaign),
            'filter'            => $filter,
            'recipientStatuses' => CrmCampaign::RECIPIENT_STATUSES,
            'targetOptions'     => [
                'industries' => CrmAccount::whereNotNull('industry')->where('industry', '!=', '')
                    ->distinct()->orderBy('industry')->pluck('industry')->values(),
                'accounts'   => CrmAccount::orderBy('name')->get(['id', 'name']),
                'products'   => \App\Modules\CRM\Models\CrmProduct::active()->orderBy('name')->get(['id', 'name', 'type']),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'    => 'required|string|max:160',
            'channel' => 'required|in:email,whatsapp',
        ]);

        $campaign = CrmCampaign::create([
            'uuid'       => Str::uuid(),
            'name'       => $data['name'],
            'channel'    => $data['channel'],
            'status'     => 'draft',
            'created_by' => Auth::id(),
        ]);

        return redirect()->route('crm.campaigns.detail', $campaign)->with('success', 'Campaign created.');
    }

    public function update(Request $request, CrmCampaign $campaign): RedirectResponse
    {
        abort_if(in_array($campaign->status, ['sending', 'sent'], true), 422, 'A sent campaign cannot be edited.');

        $data = $request->validate([
            'name'        => 'required|string|max:160',
            'subject'     => 'nullable|string|max:200',
            'from_name'   => 'nullable|string|max:120',
            'from_email'  => 'nullable|email|max:200',
            'content'     => 'nullable|string',
            'wa_template' => 'nullable|string|max:120',
        ]);

        $campaign->update($data);

        return back()->with('success', 'Campaign saved.');
    }

    /** Upload an HTML file (e.g. designed in Holo AI) as the email content. */
    public function uploadContent(Request $request, CrmCampaign $campaign): RedirectResponse
    {
        $request->validate(['file' => 'required|file|mimetypes:text/html,text/plain|max:2048']);

        $campaign->update(['content' => (string) $request->file('file')->get()]);

        return back()->with('success', 'Email content uploaded.');
    }

    /** Add recipients from CRM contacts or a bulk CSV (name,email[,phone]). */
    public function addRecipients(Request $request, CrmCampaign $campaign): RedirectResponse
    {
        abort_if(in_array($campaign->status, ['sending', 'sent'], true), 422, 'Campaign already sent.');

        $request->validate([
            'source'        => 'required|in:contacts,csv',
            'file'          => 'required_if:source,csv|file|mimes:csv,txt|max:10240',
            'industries'    => 'nullable|array',
            'industries.*'  => 'string',
            'account_ids'   => 'nullable|array',
            'account_ids.*' => 'integer',
            'owner_id'      => 'nullable|integer',
            'product_id'    => 'nullable|integer',
        ]);

        $added = $request->source === 'csv'
            ? $this->addFromCsv($campaign, $request->file('file')->getRealPath())
            : $this->addFromContacts($campaign, [
                'industries'  => $request->input('industries', []),
                'account_ids' => $request->input('account_ids', []),
                'owner_id'    => $request->input('owner_id'),
                'product_id'  => $request->input('product_id'),
            ]);

        $campaign->update(['total_recipients' => $campaign->recipients()->count()]);

        // Nobody matched: say so rather than flash a green "0 recipient(s) added" that reads as done.
        if ($added === 0) {
            return back()->with('error', $request->source === 'csv'
                ? 'No recipients were added: the CSV needs an "email" or "phone" column with values in it.'
                : 'No new recipients matched. Contacts need ' . ($campaign->channel === 'whatsapp' ? 'a phone or mobile number' : 'an email address')
                    . ' and must match every filter chosen (product, industry, client), and anyone already on this campaign is skipped.');
        }

        return back()->with('success', "{$added} recipient(s) added.");
    }

    public function schedule(Request $request, CrmCampaign $campaign): RedirectResponse
    {
        $data = $request->validate([
            'scheduled_at' => 'required|date|after:now',
            'daily_cap'    => 'nullable|integer|min:1|max:100000',
        ]);
        abort_if($campaign->recipients()->count() === 0, 422, 'Add recipients first.');

        $campaign->update([
            'status'       => 'scheduled',
            'scheduled_at' => $data['scheduled_at'],
            'daily_cap'    => $data['daily_cap'] ?? null,
        ]);

        $msg = ! empty($data['daily_cap'])
            ? "Campaign scheduled — up to {$data['daily_cap']} per day."
            : 'Campaign scheduled.';

        return back()->with('success', $msg);
    }

    /**
     * Send to every recipient still waiting.
     *
     * Choosing industries or clients on the screen selects an audience; it adds nobody until
     * "Add recipients" is pressed. This used to abort with a bare 422 — an error page reading
     * "No pending recipients" to someone looking at the audience they had just picked. Now it
     * says which of the two situations it is and what to do.
     */
    public function sendNow(CrmCampaign $campaign): RedirectResponse
    {
        if ($campaign->recipients()->where('status', 'pending')->doesntExist()) {
            $total = $campaign->recipients()->count();

            return back()->with('error', $total === 0
                ? 'This campaign has no recipients yet. Choose your audience (or a CSV) and click "Add recipients" first, then send.'
                : "All {$total} recipient(s) on this campaign have already been sent to or skipped. Add new recipients to send again.");
        }

        $campaign->update(['status' => 'sending', 'scheduled_at' => null]);
        SendCampaignJob::dispatch($campaign->id);

        return back()->with('success', 'Campaign queued for sending.');
    }

    /**
     * Send one copy to one address, and change nothing else.
     *
     * The previous implementation created a throwaway recipient and then called SendCampaignJob,
     * which sends to *every* pending recipient. Clicking "Send a Test" on a campaign with an
     * audience already loaded therefore sent the unfinished draft to the entire list, marked the
     * campaign `sent`, and deleted the test row on the way out — the one button whose whole purpose
     * is to avoid that outcome was the button that caused it.
     *
     * So this path no longer touches the job, the recipient table or the campaign's status. It
     * renders the content and sends it, once.
     *
     * Tracking is deliberately left out. A test must not add opens and clicks to the campaign's
     * statistics, and leaving the links unrewritten is also what a tester actually wants: clicking
     * a link goes to the real destination, so a broken URL is visible before the send rather than
     * after it.
     */
    public function sendTest(Request $request, CrmCampaign $campaign, CrmMailService $mail): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:200', $campaign->channel === 'email' ? 'email' : 'string'],
        ]);

        if (($campaign->content ?? '') === '' && ! $campaign->wa_template) {
            return back()->with('error', 'There is nothing to test yet — add the content first.');
        }

        try {
            $campaign->channel === 'whatsapp'
                ? $this->testWhatsApp($campaign, $data['to'])
                : $this->testEmail($campaign, $data['to'], $mail);
        } catch (\Throwable $e) {
            Log::warning('Campaign test send failed', ['campaign' => $campaign->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Test failed: ' . $e->getMessage());
        }

        return back()->with('success', 'Test sent to ' . $data['to'] . '. Nothing was sent to your recipients.');
    }

    /** The campaign email as recipients will see it, with a banner saying it is a test. */
    private function testEmail(CrmCampaign $campaign, string $to, CrmMailService $mail): void
    {
        $banner = '<div style="background:#fff4e5;border:1px solid #ffb74d;color:#663c00;'
            . 'padding:10px 14px;font:13px/1.5 sans-serif;margin-bottom:12px">'
            . '<strong>TEST</strong> — this is a preview of "' . e($campaign->name) . '". '
            . 'Links are not tracked and opens are not counted.</div>';

        $html = $campaign->content ?? '';
        $html = str_contains($html, '<body')
            ? preg_replace('/(<body[^>]*>)/i', '$1' . $banner, $html, 1)
            : $banner . $html;

        $from = $mail->from();

        $mail->marketingMailer()->html($html, function ($m) use ($campaign, $to, $from) {
            $m->to($to)->subject('[TEST] ' . ($campaign->subject ?: $campaign->name));

            if ($campaign->from_email) {
                $m->from($campaign->from_email, $campaign->from_name ?: null);
            } elseif ($from) {
                $m->from($from['address'], $from['name']);
            }

            // Bulk headers stay off: a single test is not bulk mail, and a List-Unsubscribe
            // pointing at a token that does not exist would 404 the moment it was clicked.
            $m->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
        });
    }

    private function testWhatsApp(CrmCampaign $campaign, string $to): void
    {
        $result = $campaign->wa_template
            ? $this->whatsapp->sendTemplate($to, $campaign->wa_template)
            : $this->whatsapp->sendText($to, "[TEST] \n" . ($campaign->content ?? ''));

        if (! ($result['available'] ?? false)) {
            throw new \RuntimeException($result['error'] ?? 'WhatsApp is not configured.');
        }
        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }
    }

    public function destroy(CrmCampaign $campaign): RedirectResponse
    {
        $campaign->delete();

        return redirect()->route('crm.campaigns')->with('success', 'Campaign deleted.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Add CRM contacts as recipients, optionally narrowed by audience filters:
     * specific industries (via the contact's account), specific clients/accounts,
     * or a sales rep (owner). No filters = everyone reachable on the channel.
     *
     * @param  array{industries?:array,account_ids?:array,owner_id?:int|null}  $filters
     */
    private function addFromContacts(CrmCampaign $campaign, array $filters = []): int
    {
        $existing = $campaign->recipients()->pluck('contact_id')->filter()->all();
        $count = 0;

        CrmContact::when($campaign->channel === 'email', fn ($q) => $q->whereNotNull('email'))
            ->when($campaign->channel === 'whatsapp', fn ($q) => $q->where(fn ($w) => $w->whereNotNull('phone')->orWhereNotNull('mobile')))
            ->when(! empty($filters['account_ids']), fn ($q) => $q->whereIn('account_id', $filters['account_ids']))
            ->when(! empty($filters['industries']), fn ($q) => $q->whereHas('account', fn ($a) => $a->whereIn('industry', $filters['industries'])))
            ->when(! empty($filters['product_id']), fn ($q) => $q->whereHas('account', fn ($a) => $a->where('product_id', $filters['product_id'])))
            ->when(! empty($filters['owner_id']), fn ($q) => $q->where('owner_id', $filters['owner_id']))
            ->whereNotIn('id', $existing ?: [0])
            ->chunkById(200, function ($contacts) use ($campaign, &$count) {
                foreach ($contacts as $c) {
                    CrmCampaignRecipient::create([
                        'campaign_id' => $campaign->id, 'contact_id' => $c->id,
                        'name'  => trim($c->first_name . ' ' . $c->last_name),
                        'email' => $c->email, 'phone' => $c->phone ?: $c->mobile,
                        'token' => Str::random(40), 'status' => 'pending',
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function addFromCsv(CrmCampaign $campaign, string $path): int
    {
        $handle = fopen($path, 'r');
        if (! $handle) return 0;

        $headers = array_map(fn ($h) => Str::of($h)->lower()->trim()->__toString(), fgetcsv($handle) ?: []);
        $iName  = array_search('name', $headers, true);
        $iEmail = array_search('email', $headers, true);
        $iPhone = array_search('phone', $headers, true);
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $email = $iEmail !== false ? trim((string) ($row[$iEmail] ?? '')) : '';
            $phone = $iPhone !== false ? trim((string) ($row[$iPhone] ?? '')) : '';
            if ($email === '' && $phone === '') continue;

            CrmCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'name'  => $iName !== false ? trim((string) ($row[$iName] ?? '')) : null,
                'email' => $email ?: null, 'phone' => $phone ?: null,
                'token' => Str::random(40), 'status' => 'pending',
            ]);
            $count++;
        }
        fclose($handle);

        return $count;
    }

    private function stats(CrmCampaign $campaign): array
    {
        $total = max(1, $campaign->total_recipients);
        return [
            'total'        => $campaign->total_recipients,
            'sent'         => $campaign->sent_count,
            'opened'       => $campaign->opened_count,
            'clicked'      => $campaign->clicked_count,
            'failed'       => $campaign->failed_count,
            'bounced'      => $campaign->bounced_count,
            'complained'   => $campaign->complained_count,
            'unsubscribed' => $campaign->unsubscribed_count,
            'open_rate'    => round($campaign->opened_count / $total * 100, 1),
            'click_rate'   => round($campaign->clicked_count / $total * 100, 1),
        ];
    }

    private function present(CrmCampaign $c): array
    {
        return [
            'id'           => $c->id,
            'uuid'         => $c->uuid,
            'name'         => $c->name,
            'channel'      => $c->channel,
            'subject'      => $c->subject,
            'from_name'    => $c->from_name,
            'from_email'   => $c->from_email,
            'status'       => $c->status,
            'status_label' => CrmCampaign::STATUSES[$c->status]['label'] ?? $c->status,
            'status_color' => CrmCampaign::STATUSES[$c->status]['color'] ?? 'gray',
            'recipients'   => $c->recipients_count ?? $c->total_recipients,
            'opened'       => $c->opened_count,
            'clicked'      => $c->clicked_count,
            'scheduled_at' => $c->scheduled_at?->toDateTimeString(),
            'daily_cap'    => $c->daily_cap,
            'sent_at'      => $c->sent_at?->toDateTimeString(),
        ];
    }
}
