<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Setup\Models\CompanyIdentity;
use App\Modules\CRM\Models\CrmSurveyRecipient;
use App\Modules\CRM\Services\CrmSurveyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public survey response page (no auth) — a contact opens their unique token
 * link, answers the survey and submits.
 */
class SurveyResponseController extends Controller
{
    public function take(string $token): Response
    {
        $recipient = CrmSurveyRecipient::with('survey')->where('token', $token)->firstOrFail();
        $survey    = $recipient->survey;

        $identity = CompanyIdentity::systemSettings();

        return Inertia::render('Modules/CRM/Portal/Survey', [
            'company'   => $identity['full_company_name'] ?: $identity['company_name'],
            'logo'      => $identity['company_logo_url'] ?: null,
            'token'     => $token,
            'completed' => $recipient->completed_at !== null,
            'open'      => $survey && $survey->isOpen(),
            'survey'    => $survey ? [
                'name'        => $survey->name,
                'description' => $survey->description,
                'questions'   => $survey->questions ?? [],
                'thank_you'   => $survey->thank_you,
            ] : null,
            'recipient_name' => $recipient->name,
        ]);
    }

    public function submit(Request $request, string $token, CrmSurveyService $surveys): RedirectResponse
    {
        $recipient = CrmSurveyRecipient::with('survey')->where('token', $token)->firstOrFail();
        $survey    = $recipient->survey;

        abort_unless($survey && $survey->isOpen(), 403, 'This survey is no longer available.');

        if ($recipient->completed_at) {
            return back()->with('success', 'You have already completed this survey — thank you!');
        }

        $data = $request->validate([
            'answers' => 'required|array',
        ]);

        $surveys->recordResponse($recipient, $data['answers']);

        return back()->with('success', $survey->thank_you ?: 'Thank you — your response has been recorded.');
    }
}
