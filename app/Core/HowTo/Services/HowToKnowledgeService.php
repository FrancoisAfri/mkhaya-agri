<?php

namespace App\Core\HowTo\Services;

use App\Core\User\Models\User;
use App\Modules\Dashboard\Services\QuickAccessService;
use Illuminate\Support\Facades\DB;

/**
 * Answers "how do I …" questions from the curated how-to knowledge base
 * (module_how_tos) instead of calling the LLM — this both keeps answers grounded
 * in the real product and cuts AI spend to ~zero for procedural questions (the
 * bulk of call-centre volume).
 *
 * The how-to content is GLOBAL product documentation (not tenant-owned): the AI bot
 * is available to every tenant/user in SaaS. What IS scoped is what each user is told
 * about — answers are restricted to the exact ribbons the asking user can access
 * (active module + their role's access_level + any per-ribbon grant), reusing the same
 * QuickAccessService logic that builds their menu. So the bot never explains a feature
 * the user's company doesn't have or their role can't reach.
 */
class HowToKnowledgeService
{
    /** Return a descriptive answer from the how-to base, or null if not applicable. */
    public function answerFor(string $message, User $user): ?string
    {
        if (! $this->looksLikeHowTo($message)) {
            return null;
        }

        $ribbonIds = $this->accessibleRibbonIds($user);
        if ($ribbonIds->isEmpty()) {
            return null;
        }

        $words = $this->keywords($message);
        if ($words->isEmpty()) {
            return null;
        }

        $rows = DB::table('module_how_tos')
            ->whereIn('ribbon_id', $ribbonIds)
            ->where('active', 1)
            ->where(function ($q) use ($words) {
                foreach ($words as $w) {
                    $q->orWhere('question', 'ilike', '%' . $w . '%')
                      ->orWhere('answer', 'ilike', '%' . $w . '%');
                }
            })
            // Prioritise rows whose QUESTION matches the most keywords so the best
            // matches survive the row cap (an arbitrary limit could otherwise drop the
            // one perfect entry when a common word like "leave" matches hundreds).
            ->orderByRaw(
                $words->map(fn () => '(CASE WHEN question ILIKE ? THEN 1 ELSE 0 END)')->implode(' + ') . ' DESC',
                $words->map(fn ($w) => '%' . $w . '%')->all()
            )
            ->limit(60)
            ->get(['question', 'answer']);

        if ($rows->isEmpty()) {
            return null;
        }

        // A match must actually be ABOUT the question. Require an entry to cover most of
        // the query's keywords (≥60%, min 1) somewhere in its question+answer, otherwise
        // a query like "apply for leave" wrongly matches any entry that merely contains
        // "apply" (a button) or "leave" (a different feature). If nothing clears the bar
        // we return null so the caller falls through to the AI instead of guessing.
        $required = max(1, (int) ceil($words->count() * 0.6));

        $ranked = $rows->map(function ($r) use ($words) {
            $ql = mb_strtolower((string) $r->question);
            $al = mb_strtolower((string) $r->answer);
            $both = $ql . ' ' . $al;
            $coverage = $words->filter(fn ($w) => str_contains($both, $w))->count();
            // Rank by distinct query words in the QUESTION (strongest signal — an entry
            // titled with more of the asked terms is the best match), then frequency.
            $distinctInQuestion = $words->filter(fn ($w) => str_contains($ql, $w))->count();
            $freq = $words->sum(fn ($w) => (substr_count($ql, $w) * 3) + substr_count($al, $w));
            return ['row' => $r, 'coverage' => $coverage, 'score' => ($distinctInQuestion * 100) + $freq];
        })
            ->filter(fn ($x) => $x['coverage'] >= $required)
            ->sortByDesc('score')
            ->take(3)
            ->pluck('row');

        if ($ranked->isEmpty()) {
            return null;
        }

        $body = $ranked->map(fn ($r) => "**{$r->question}**\n{$r->answer}")->implode("\n\n");

        return "Here's how to do that, from the Mkhaya guide:\n\n{$body}\n\n_If that doesn't fully cover it, tell me and I'll help further._";
    }

    private function looksLikeHowTo(string $message): bool
    {
        return (bool) preg_match(
            '/\b(how (do|can|to|would|does|should)|where (do|is|are|can)|steps? (to|for)|guide|tutorial|explain how|walk me through|what is the process|process (to|for))\b/i',
            $message
        );
    }

    /**
     * The ribbon ids the asking user may be told about. Platform/master admins (and
     * tenant admins impersonating) see everything; everyone else is limited to the
     * ribbons on their own menu — i.e. active module + role access_level + per-ribbon
     * grants — via the same service that renders their navigation.
     */
    private function accessibleRibbonIds(User $user): \Illuminate\Support\Collection
    {
        if ($user->is_platform_admin || ($user->is_master_admin ?? false)) {
            return DB::table('security_module_ribbons')->where('active', 1)->pluck('id');
        }

        return app(QuickAccessService::class)
            ->getAccessibleRibbonsForUser($user->id)
            ->pluck('id');
    }

    private function keywords(string $message): \Illuminate\Support\Collection
    {
        $stop = ['how', 'the', 'and', 'for', 'you', 'can', 'where', 'what', 'this', 'that', 'with', 'have', 'from', 'does', 'should', 'would', 'system', 'about', 'please'];

        return collect(preg_split('/\W+/', mb_strtolower($message)))
            ->filter(fn ($w) => strlen($w) >= 3 && ! in_array($w, $stop, true))
            ->map(fn ($w) => $this->stem($w))
            ->unique()
            ->values();
    }

    /** Light singularisation so "holidays" matches "holiday", "policies" → "policy". */
    private function stem(string $w): string
    {
        if (strlen($w) < 5 || ! str_ends_with($w, 's') || str_ends_with($w, 'ss')) {
            return $w;
        }
        if (str_ends_with($w, 'ies')) {
            return substr($w, 0, -3) . 'y';
        }

        return substr($w, 0, -1);
    }
}
