<?php

namespace App\Modules\Audit\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Models\EmailDeliveryLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What this system tried to email, and what came of it.
 *
 * The question that produced this page was "did that invitation go out?", and until now nothing
 * could answer it. The only evidence was the recipient saying it never arrived, by which point
 * nobody could tell whether it had been sent and lost somewhere downstream, refused by the mail
 * server, or never attempted at all — three different problems with three different fixes.
 */
class EmailDeliveryController extends Controller
{
    /** A page of history, not an archive. Older than this and you want the export, not a table. */
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'search', 'mailable', 'days']);
        $days = max(1, min(365, (int) ($filters['days'] ?? 30)));

        $base = EmailDeliveryLog::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['mailable'] ?? null, fn ($q, $m) => $q->where('mailable', $m))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(
                fn ($w) => $w->where('to', 'ilike', "%{$term}%")->orWhere('subject', 'ilike', "%{$term}%")
            ));

        return Inertia::render('Modules/Audit/EmailDelivery', [
            'rows' => (clone $base)->latest('id')->paginate(self::PER_PAGE)->withQueryString()
                ->through(fn (EmailDeliveryLog $r) => [
                    'id'       => $r->id,
                    'to'       => $r->to,
                    'subject'  => $r->subject,
                    // The class name is noise on screen; the last segment is the thing people read.
                    'mailable' => $r->mailable ? class_basename($r->mailable) : null,
                    'status'   => $r->status,
                    'error'    => $r->error,
                    'mailer'   => $r->mailer,
                    'at'       => $r->created_at?->toDayDateTimeString(),
                ]),

            // Counted over the same window as the table, so the totals and the rows agree.
            'counts' => [
                'sent'    => (clone $base)->where('status', EmailDeliveryLog::SENT)->count(),
                'failed'  => (clone $base)->where('status', EmailDeliveryLog::FAILED)->count(),
                'pending' => (clone $base)->where('status', EmailDeliveryLog::PENDING)->count(),
            ],

            'kinds' => EmailDeliveryLog::query()
                ->where('created_at', '>=', now()->subDays($days))
                ->whereNotNull('mailable')->distinct()->orderBy('mailable')
                ->pluck('mailable')
                ->map(fn ($m) => ['value' => $m, 'label' => class_basename($m)])
                ->values(),

            'filters'  => $filters + ['days' => $days],
            'statuses' => EmailDeliveryLog::STATUSES,
        ]);
    }
}
