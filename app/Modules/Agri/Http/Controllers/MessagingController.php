<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\MessagingContact;
use App\Modules\Agri\Services\AgriMessaging;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 2 §16C: the messaging webhook (WhatsApp, SMS, voice transcripts), signed with the
 * organisation's shared secret, and linking a person's number from the app.
 */
class MessagingController extends AgriController
{
    public function __construct(private readonly AgriMessaging $messaging) {}

    /** POST /api/agri/messages/{channel} — {"from": "+27…", "text": "…", "id": "provider message id"} → {"reply": "…"} */
    public function inbound(Request $request, string $channel): JsonResponse
    {
        abort_unless(in_array($channel, MessagingContact::CHANNELS, true), 404);
        $secret = (string) config('agri.messaging.secret');
        $sig = (string) $request->header('X-Agri-Signature');
        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $sig)) {
            return response()->json(['error' => 'Bad signature.'], 401);
        }
        $data = $request->validate(['from' => ['required', 'string', 'max:32'], 'text' => ['required', 'string', 'max:2000'], 'id' => ['nullable', 'string', 'max:120']]);

        return response()->json(['reply' => $this->messaging->handle($channel, $data['from'], $data['text'], $data['id'] ?? null)]);
    }

    public function link(Request $request): JsonResponse
    {
        $this->allow($request, R::VIEW_FARMS);
        $data = $request->validate(['channel' => ['required', Rule::in(MessagingContact::CHANNELS)], 'address' => ['required', 'string', 'max:32'],
            'language' => ['nullable', Rule::in(MessagingContact::LANGUAGES)]]);
        $code = $this->messaging->link($request->user(), $data['channel'], $data['address'], $data['language'] ?? 'en');

        return response()->json(['code' => $code, 'message' => "Send {$code} from {$data['address']} on ".ucfirst($data['channel']).' within 30 minutes to link it.']);
    }
}
