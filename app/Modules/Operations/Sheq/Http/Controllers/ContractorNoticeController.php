<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\ContractorNotifier;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What gets sent to contractors, and what has been (CON-19).
 *
 * A page of its own, for a reason that is specific to notifications: they are the one part of
 * a system whose failure is invisible from the inside. A document that will not save throws;
 * a notice that was never sent looks exactly like a notice that was sent and ignored, and the
 * first anybody hears of it is a contractor saying "nobody told us".
 *
 * So the page answers three questions rather than one. What does this system send at all —
 * because a host who does not know a message exists cannot rely on it. Where does it go — the
 * channel, which was hard-coded to email and is now a choice. And what actually went out,
 * with anything that failed shown rather than buried in a log.
 */
class ContractorNoticeController extends Controller
{
    use ResolvesCompany;

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/ContractorNotices', [
            'noCompany' => $companyId === null,
            /*
             * The nine, from the catalogue rather than a list written here. A screen with its
             * own copy of the list is a screen that goes out of date the first time somebody
             * adds a tenth.
             */
            'events' => collect(ContractorNotifier::EVENTS)
                ->map(fn (array $meta, string $type) => [
                    'type' => $type,
                    'key' => str_replace('sheq.contractor.', '', $type),
                    'severity' => $meta['severity'],
                ])->values()->all(),
            'channels' => ContractorNotifier::CHANNELS,
            'chosen' => $companyId === null ? ['EMAIL'] : $this->chosenChannels($companyId),
            'recent' => $companyId === null ? [] : $this->recent($companyId),
            'counts' => $companyId === null ? [] : $this->counts($companyId),
            /*
             * Contractors with nobody to send to. The quietest failure of the lot: everything
             * works, nothing is queued, and the register still shows the expiry so nobody
             * notices for months.
             */
            'unreachable' => $companyId === null ? [] : $this->unreachable($companyId),
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
        ]);
    }

    /** Choose which channels contractor notices go out on. */
    public function updateChannels(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            // `present` not `required`: an empty list is a decision — a host that wants no
            // contractor notices at all should be able to say so, and silently keeping email
            // on would be the system overruling them.
            'channels' => ['present', 'array'],
            'channels.*' => [Rule::in(ContractorNotifier::CHANNELS)],
        ]);

        $channels = array_values(array_unique($data['channels']));

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $companyId],
            ['contractor_notification_channels' => json_encode($channels), 'updated_at' => now()],
        );

        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId, 'actor_id' => $request->user()?->id,
            'actor_type' => 'USER', 'action' => 'contractor.notification_channels_changed',
            'subject_type' => 'SheqSettings', 'subject_id' => 0,
            'before' => json_encode($this->chosenChannels($companyId)),
            'after' => json_encode($channels),
        ]);

        return back()->with('success', $channels === []
            ? 'Contractor notices are switched off. Nothing will be sent to them.'
            : 'Contractor notices will go out by '.implode(' and ', $channels).'.');
    }

    /** @return list<string> */
    private function chosenChannels(int $companyId): array
    {
        $stored = json_decode((string) DB::table('sheq_settings')
            ->where('company_id', $companyId)->value('contractor_notification_channels'), true);

        return is_array($stored) ? array_values($stored) : ['EMAIL'];
    }

    /**
     * What has gone out, newest first.
     *
     * @return list<array<string,mixed>>
     */
    private function recent(int $companyId, int $limit = 200): array
    {
        return DB::table('sheq_notification_dispatches')
            ->where('company_id', $companyId)
            ->where('event_type', 'like', 'sheq.contractor.%')
            ->orderByDesc('id')->limit($limit)
            ->get(['uuid', 'event_type', 'subject_reference', 'channel', 'recipient_label',
                'recipient_address', 'severity', 'status', 'sent_at', 'attempts',
                'last_error', 'payload', 'created_at'])
            ->map(function ($row) {
                $payload = json_decode((string) $row->payload, true) ?: [];

                return array_merge((array) $row, [
                    'title' => $payload['title'] ?? $row->event_type,
                    'key' => str_replace('sheq.contractor.', '', $row->event_type),
                    // Deliberately not the body: it is long, and a register is for scanning.
                    // The title carries the fact; the body is in the message itself.
                    'payload' => null,
                ]);
            })->all();
    }

    /** @return array<string,int> */
    private function counts(int $companyId): array
    {
        $since = Carbon::today()->subDays(30);

        $rows = DB::table('sheq_notification_dispatches')
            ->where('company_id', $companyId)
            ->where('event_type', 'like', 'sheq.contractor.%')
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as n')->groupBy('status')
            ->pluck('n', 'status');

        return [
            'sent' => (int) ($rows['SENT'] ?? 0),
            'pending' => (int) ($rows['PENDING'] ?? 0),
            'failed' => (int) ($rows['FAILED'] ?? 0),
        ];
    }

    /**
     * Active contractors with no way of being reached on the chosen channels.
     *
     * The quietest failure in the module. Everything works, nothing is queued, the register
     * still shows the expiry, and nobody finds out for months — least of all the contractor,
     * who is the person being told nothing.
     *
     * @return list<array<string,mixed>>
     */
    private function unreachable(int $companyId): array
    {
        $channels = $this->chosenChannels($companyId);

        if ($channels === []) {
            return [];
        }

        $needsEmail = in_array('EMAIL', $channels, true);
        $needsPhone = (bool) array_intersect($channels, ['SMS', 'WHATSAPP']);

        return DB::table('sheq_contractors')
            ->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)
            ->where(function ($q) use ($needsEmail, $needsPhone) {
                /*
                 * Reachable on ANY chosen channel is enough. A host sending by both email and
                 * WhatsApp has not failed to reach a contractor who has an email address and
                 * no mobile number — listing them would turn this into noise and the list
                 * would stop being read.
                 */
                if ($needsEmail) {
                    $q->orWhere(fn ($i) => $i->whereNull('contact_email')->orWhere('contact_email', ''));
                }

                if ($needsPhone) {
                    $q->orWhere(fn ($i) => $i->whereNull('contact_phone')->orWhere('contact_phone', ''));
                }
            })
            ->orderBy('name')->limit(200)
            ->get(['uuid', 'name', 'contact_email', 'contact_phone'])
            ->filter(function ($row) use ($needsEmail, $needsPhone) {
                $hasEmail = $needsEmail && trim((string) $row->contact_email) !== '';
                $hasPhone = $needsPhone && trim((string) $row->contact_phone) !== '';

                return ! $hasEmail && ! $hasPhone;
            })
            ->values()->map(fn ($r) => (array) $r)->all();
    }
}
