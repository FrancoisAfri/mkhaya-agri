<?php

namespace App\Modules\Communication\EmailCampaigns\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Communication\EmailCampaigns\Models\EmailCampaignTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmailCampaignTemplateController extends Controller
{
    public function index(): InertiaResponse
    {
        $templates = EmailCampaignTemplate::with('creator.person')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => [
                'id'         => $t->id,
                'uuid'       => $t->uuid,
                'title'      => $t->title,
                'subject'    => $t->subject,
                'from_name'  => $t->from_name,
                'from_email' => $t->from_email,
                'body'       => $t->body,
                'created_by' => $t->creator?->name,
                'created_at' => $t->created_at->toDateTimeString(),
            ]);

        return Inertia::render('Modules/Communication/EmailCampaigns/Templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $template = EmailCampaignTemplate::create(array_merge($data, ['created_by' => Auth::id()]));

        return response()->json(['id' => $template->id, 'uuid' => $template->uuid], 201);
    }

    public function update(Request $request, EmailCampaignTemplate $template): JsonResponse
    {
        $template->update($this->validated($request));

        return response()->json(['ok' => true]);
    }

    public function destroy(EmailCampaignTemplate $template): JsonResponse
    {
        $template->delete();

        return response()->json(['ok' => true]);
    }

    public function list(): JsonResponse
    {
        $templates = EmailCampaignTemplate::orderBy('title')
            ->get(['id', 'title', 'subject', 'from_name', 'from_email', 'body']);

        return response()->json($templates);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'      => ['required', 'string', 'max:255'],
            'subject'    => ['required', 'string', 'max:255'],
            'from_name'  => ['nullable', 'string', 'max:100'],
            'from_email' => ['nullable', 'email', 'max:150'],
            'body'       => ['required', 'string'],
        ]);
    }
}
