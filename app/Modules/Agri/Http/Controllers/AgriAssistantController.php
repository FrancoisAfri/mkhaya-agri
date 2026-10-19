<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Services\AgriAssistant;
use App\Modules\Agri\Services\AgriNarrator;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module H: the farm assistant. Answers questions from the farm's own records; changes nothing.
 */
class AgriAssistantController extends AgriController
{
    public function __construct(private readonly AgriAssistant $assistant, private readonly AgriNarrator $narrator) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::USE_ASSISTANT);

        return Inertia::render('Modules/Agri/Assistant', ['suggestions' => $this->assistant->suggestions($request->user())]);
    }

    public function ask(Request $request): JsonResponse
    {
        $this->allow($request, R::USE_ASSISTANT);
        $data = $request->validate(['question' => ['required', 'string', 'max:500'], 'topic' => ['nullable', 'string', 'max:40']]);
        $answer = $this->assistant->answer($data['question'], $request->user(), $data['topic'] ?? null);
        $words = $this->narrator->narrate($answer);

        // Questions are audited like any other read of farm records.
        activity('agri')->causedBy($request->user())->tap(fn ($a) => $a->company_id = $request->user()->company_id)->withProperties(['question' => $data['question'], 'topic' => $answer['topic'],
            'refused' => $answer['refused'], 'source' => $words['source']])->event('assistant.asked')->log('Farm assistant question');

        return response()->json($answer + ['reply' => $words['text'], 'reply_source' => $words['source']]);
    }
}
