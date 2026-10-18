<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Services\CrmAiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My Day" — an AI-prioritised next-best-action worklist for the signed-in rep,
 * built from open tasks, slipping deals and hot leads.
 */
class CrmMyDayController extends Controller
{
    public function __construct(private CrmAiService $ai)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Modules/CRM/MyDay', [
            'aiEnabled' => $this->ai->isConfigured(),
            'worklist'  => Session::get('myday_worklist'),
            'counts'    => $this->candidateCounts(),
        ]);
    }

    public function generate(): RedirectResponse
    {
        $candidates = $this->candidates();

        // Nothing to plan only when every signal is empty.
        $hasAnything = collect(['tasks', 'reminders', 'deals', 'leads', 'notes', 'activities'])
            ->contains(fn ($k) => ! empty($candidates[$k]));
        if (! $hasAnything) {
            return back()->with('myday_worklist', ['items' => []]);
        }

        $result = $this->ai->prioritizeDay($candidates);
        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'Could not generate the worklist.');
        }

        return back()->with('myday_worklist', $result);
    }

    /**
     * Everything about the signed-in rep the planner should weigh: their open
     * tasks & reminders, slipping deals, hot leads, recent notes and recent /
     * upcoming activities (calls, emails, meetings) for context.
     */
    private function candidates(): array
    {
        $uid    = (int) Auth::id();
        $horizon = now()->addDays(7); // overdue + the next week

        $present = fn ($a) => [
            'id'      => $a->id,
            'subject' => $a->subject,
            'type'    => $a->type,
            'due'     => $a->due_at?->toDateTimeString(),
            'overdue' => $a->due_at?->isPast() ?? false,
            'on'      => $a->related_type ? $a->related_type.'#'.$a->related_id : null,
            'notes'   => $a->description ? mb_substr($a->description, 0, 200) : null,
        ];

        // Open tasks — overdue + due within the week.
        $tasks = CrmActivity::where('owner_id', $uid)->where('type', 'task')->where('status', 'pending')
            ->whereNotNull('due_at')->where('due_at', '<=', $horizon)->orderBy('due_at')->limit(25)->get()
            ->map($present)->all();

        // Reminders — overdue + due within the week.
        $reminders = CrmActivity::where('owner_id', $uid)->where('type', 'reminder')->where('status', 'pending')
            ->whereNotNull('due_at')->where('due_at', '<=', $horizon)->orderBy('due_at')->limit(25)->get()
            ->map($present)->all();

        // Recent notes — context the planner can reason over.
        $notes = CrmActivity::where('owner_id', $uid)->where('type', 'note')
            ->latest()->limit(10)->get()->map($present)->all();

        // Recent + upcoming activities (calls/emails/meetings/etc.) for follow-up context.
        $activities = CrmActivity::where('owner_id', $uid)->whereIn('type', ['call', 'email', 'meeting', 'whatsapp', 'sms'])
            ->orderByRaw('COALESCE(due_at, created_at) DESC')->limit(20)->get()->map($present)->all();

        // Slipping deals: open + (high risk OR no recent activity OR close date near).
        $lastActivity = CrmActivity::where('related_type', 'deal')->where('owner_id', $uid)
            ->select('related_id', DB::raw('max(created_at) as last'))->groupBy('related_id')->pluck('last', 'related_id');

        $deals = CrmDeal::with(['account:id,name', 'stage:id,name'])->where('owner_id', $uid)->where('status', 'open')
            ->latest('updated_at')->limit(40)->get()
            ->map(function ($d) use ($lastActivity) {
                $last = $lastActivity[$d->id] ?? null;
                return ['id' => $d->id, 'title' => $d->title, 'account' => $d->account?->name, 'stage' => $d->stage?->name,
                    'amount' => (float) $d->amount, 'risk' => $d->risk_level, 'win_probability' => $d->ai_win_probability,
                    'expected_close' => $d->expected_close_date?->toDateString(),
                    'days_since_activity' => $last ? \Carbon\Carbon::parse($last)->diffInDays(now()) : null];
            })
            ->filter(fn ($d) => $d['risk'] === 'high' || ($d['days_since_activity'] ?? 99) >= 10 || $d['expected_close'])
            ->values()->all();

        $leads = CrmLead::where('owner_id', $uid)->whereIn('status', ['new', 'contacted'])
            ->orderByDesc('score')->limit(15)->get()
            ->map(fn ($l) => ['id' => $l->id, 'name' => trim($l->first_name.' '.$l->last_name), 'company' => $l->company,
                'status' => $l->status, 'score' => $l->score])->all();

        return [
            'today'      => now()->toDateString(),
            'tasks'      => $tasks,
            'reminders'  => $reminders,
            'deals'      => $deals,
            'leads'      => $leads,
            'notes'      => $notes,
            'activities' => $activities,
        ];
    }

    private function candidateCounts(): array
    {
        $c = $this->candidates();

        return [
            'tasks'      => count($c['tasks']),
            'reminders'  => count($c['reminders']),
            'deals'      => count($c['deals']),
            'leads'      => count($c['leads']),
            'notes'      => count($c['notes']),
            'activities' => count($c['activities']),
        ];
    }
}
