<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Modules\HR\Recruitment\Jobs\ScreenCandidate;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\CandidateScreening;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recruiter-facing view of a candidate's AI screening. Presents decision-support
 * only — scores, risk, AI summary, evidence and neutral interview questions — with
 * an explicit "for human review, not an automated hiring decision" framing.
 */
class CandidateScreeningController
{
    public function show(Candidate $candidate): Response
    {
        $screening = CandidateScreening::query()
            ->where('candidate_id', $candidate->id)
            ->with(['findings', 'questions'])
            ->latest('id')
            ->first();

        return Inertia::render('Modules/Recruitment/Screening/Show', [
            'candidate' => [
                'id'         => $candidate->id,
                'uuid'       => $candidate->uuid,
                'full_name'  => $candidate->full_name,
                'email'      => $candidate->email,
                'has_consent' => $candidate->hasAiConsent(),
            ],
            'screening' => $screening ? [
                'uuid'                       => $screening->uuid,
                'status'                     => [
                    'value' => $screening->status?->value,
                    'label' => $screening->status?->label(),
                    'color' => $screening->status?->color(),
                ],
                'overall_score'              => $screening->overall_score,
                'reputation_score'           => $screening->reputation_score,
                'professionalism_score'      => $screening->professionalism_score,
                'communication_score'        => $screening->communication_score,
                'leadership_score'           => $screening->leadership_score,
                'community_score'            => $screening->community_score,
                'risk_level'                 => $screening->risk_level ? [
                    'value' => $screening->risk_level->value,
                    'label' => $screening->risk_level->label(),
                    'color' => $screening->risk_level->color(),
                ] : null,
                'ai_summary'                 => $screening->ai_summary,
                'recommendation'             => $screening->recommendation,
                'possible_misidentification' => (bool) $screening->possible_misidentification,
                'discovered_profiles'        => $screening->discovered_profiles ?? [],
                'analyzed_at'                => $screening->analyzed_at?->toDateTimeString(),
                'findings'                   => $screening->findings->map(fn ($f) => [
                    'type'           => $f->type,
                    'category'       => $f->category,
                    'severity'       => $f->severity,
                    'confidence'     => $f->confidence,
                    'source'         => $f->source,
                    'title'          => $f->title,
                    'excerpt'        => $f->excerpt,
                    'evidence_url'   => $f->evidence_url,
                    'ai_explanation' => $f->ai_explanation,
                    'detected_at'    => $f->detected_at?->toDateString(),
                ])->values(),
                'questions'                  => $screening->questions->map(fn ($q) => [
                    'question'  => $q->question,
                    'rationale' => $q->rationale,
                    'based_on'  => $q->based_on,
                ])->values(),
            ] : null,
        ]);
    }

    public function rescreen(Candidate $candidate): RedirectResponse
    {
        if (! $candidate->hasAiConsent()) {
            return back()->withErrors(['consent' => 'AI consent is required before screening.']);
        }

        ScreenCandidate::dispatch($candidate)->onQueue('ai-heavy');

        return back()->with('success', 'Candidate screening queued.');
    }
}
