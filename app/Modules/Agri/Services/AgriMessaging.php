<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMembership;
use App\Modules\HR\Hr\Attendance\Models\ShiftAssignment as FarmShiftAssignment;
use App\Modules\HR\Hr\Attendance\Models\Worker as FarmWorker;
use App\Modules\Agri\Models\MessagingContact;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 2 §16C: the farm assistant over WhatsApp, SMS and voice.
 *
 * A person links their number in the app and proves it by sending back the code they are given.
 * After that a message from that number is handled as that person — their permissions, their
 * farms — exactly as on the screen: a question goes to the same assistant; "shift" lists their next
 * shifts; "hazard …" reports a danger into SHEQ; "obs …" records a field observation; "alerts on"
 * sends them farm alerts. A voice provider sends its transcript the same way and speaks the reply.
 * Replies are in the person's chosen language where it is supported (English, Afrikaans); the
 * figures in an assistant answer stay as computed. An unknown or unverified number gets nothing
 * but how to link it.
 */
class AgriMessaging
{
    private const T = [
        'en' => ['unknown' => 'This number is not linked to a Mkhaya Agri account. Link it under My work → Messaging, then send the code you are given.',
            'verified' => 'Your number is linked. Send "help" to see what you can ask.', 'bad_code' => 'That code is wrong or has expired.',
            'help' => 'Ask a question about your farms, or send: "shift" (your next shifts), "hazard <what>" (report a danger), "obs <what>" (a field observation), "alerts on" / "alerts off".',
            'no_shifts' => 'You have no shifts coming up.', 'shifts' => 'Your next shifts:', 'reported' => 'Reported as :ref. Thank you.', 'observed' => 'Observation recorded on :farm.',
            'alerts_on' => 'Farm alerts will be sent to this number.', 'alerts_off' => 'Farm alerts will no longer be sent here.', 'no_farm' => 'You are not on any farm yet.',
            'not_allowed' => 'Your permissions do not cover that.', 'error' => 'That did not go through: :why'],
        'af' => ['unknown' => 'Hierdie nommer is nie aan \'n Mkhaya Agri-rekening gekoppel nie. Koppel dit onder My werk → Boodskappe, en stuur dan die kode wat jy kry.',
            'verified' => 'Jou nommer is gekoppel. Stuur "hulp" om te sien wat jy kan vra.', 'bad_code' => 'Daardie kode is verkeerd of het verval.',
            'help' => 'Vra \'n vraag oor jou plase, of stuur: "skof" (jou volgende skofte), "gevaar <wat>" (rapporteer \'n gevaar), "obs <wat>" (\'n veldwaarneming), "waarskuwings aan" / "waarskuwings af".',
            'no_shifts' => 'Jy het geen skofte wat kom nie.', 'shifts' => 'Jou volgende skofte:', 'reported' => 'Gerapporteer as :ref. Dankie.', 'observed' => 'Waarneming aangeteken op :farm.',
            'alerts_on' => 'Plaaswaarskuwings sal na hierdie nommer gestuur word.', 'alerts_off' => 'Plaaswaarskuwings sal nie meer hierheen gestuur word nie.', 'no_farm' => 'Jy is nog op geen plaas nie.',
            'not_allowed' => 'Jou toestemmings dek dit nie.', 'error' => 'Dit het nie deurgegaan nie: :why'],
    ];

    private function t(string $lang, string $key, array $r = []): string
    {
        $s = self::T[$lang][$key] ?? self::T['en'][$key];
        foreach ($r as $k => $v) {
            $s = str_replace(":{$k}", (string) $v, $s);
        }

        return $s;
    }

    /** Start linking a number: returns the code the person sends back from it. */
    public function link(User $user, string $channel, string $address, string $language): string
    {
        if (! in_array($channel, MessagingContact::CHANNELS, true) || ! preg_match('/^\+[1-9]\d{7,14}$/', $address)) {
            throw ValidationException::withMessages(['address' => 'Give the number in international form, e.g. +27821234567.']);
        }
        $code = (string) random_int(100000, 999999);
        MessagingContact::withoutGlobalScopes()->updateOrCreate(['channel' => $channel, 'address' => $address], ['company_id' => $user->company_id, 'user_id' => $user->id,
            'language' => in_array($language, MessagingContact::LANGUAGES, true) ? $language : 'en', 'code_hash' => hash('sha256', $code), 'code_expires_at' => now()->addMinutes(30), 'verified_at' => null]);

        return $code;
    }

    /** An inbound message (or a voice transcript). Returns the reply to send back. */
    public function handle(string $channel, string $from, string $text, ?string $messageId = null): string
    {
        $c = MessagingContact::withoutGlobalScopes()->where('channel', $channel)->where('address', $from)->first();
        $text = trim($text);
        $lang = $c?->language ?? 'en';
        if (! $c) {
            return $this->t('en', 'unknown');
        }
        if (! $c->verified_at) {
            if (preg_match('/(\d{6})/', $text, $m) && $c->code_hash && $c->code_expires_at?->isFuture() && hash_equals($c->code_hash, hash('sha256', $m[1]))) {
                $c->update(['verified_at' => now(), 'code_hash' => null]);

                return $this->t($lang, 'verified');
            }

            return $this->t($lang, $c->code_hash ? 'bad_code' : 'unknown');
        }
        $user = User::withoutGlobalScopes()->find($c->user_id);
        if (! $user || ! (int) $user->status) {
            return $this->t($lang, 'unknown');
        }

        // As that person, inside their organisation — the same scopes and permissions as the screen.
        return app(TenantContext::class)->run((int) $user->company_id, function () use ($user, $c, $text, $lang, $channel, $messageId) {
            $previous = Auth::user();
            Auth::setUser($user);
            app(FarmAccess::class)->flush();
            try {
                $key = $messageId ? "{$channel}:{$messageId}" : "{$channel}:{$c->address}:".sha1($text.now()->format('YmdHi'));
                ['event' => $e] = app(Integrations::class)->receive((int) $user->company_id, 'messaging', $key, ['from' => $c->address, 'text' => $text], null);

                return $e->result['reply'] ?? $this->t($lang, 'error', ['why' => $e->error ?? '?']);
            } finally {
                $previous ? Auth::setUser($previous) : Auth::forgetUser();
                app(FarmAccess::class)->flush();
            }
        });
    }

    /** The work behind a message, run once through the integration log. */
    public function respond(array $payload): array
    {
        $user = Auth::user();
        $c = MessagingContact::withoutGlobalScopes()->where('address', $payload['from'])->where('user_id', $user->id)->firstOrFail();
        $lang = $c->language;
        $text = $payload['text'];
        $lower = mb_strtolower($text);
        $farm = Farm::query()->whereIn('id', FarmMembership::query()->where('user_id', $user->id)->where('status', 'active')->pluck('farm_id'))->orderBy('name')->first()
            ?? Farm::query()->operational()->orderBy('name')->first();

        try {
            $reply = match (true) {
                in_array($lower, ['help', 'hulp', '?'], true) => $this->t($lang, 'help'),
                in_array($lower, ['shift', 'shifts', 'skof', 'skofte'], true) => $this->shifts($user, $lang),
                (bool) preg_match('/^(alerts|waarskuwings)\s+(on|aan|off|af)$/', $lower, $m) => $this->alerts($c, in_array($m[2], ['on', 'aan'], true), $lang),
                (bool) preg_match('/^(hazard|gevaar)\s+(.+)$/su', $text, $m) => $farm ? $this->t($lang, 'reported', ['ref' => app(FarmSafety::class)->report($farm,
                    ['kind' => 'hazard', 'description' => trim($m[2]), 'client_id' => null], $user)['reference']]) : $this->t($lang, 'no_farm'),
                (bool) preg_match('/^(obs|waarneming)\s+(.+)$/su', $text, $m) => $this->observe($user, $farm, trim($m[2]), $lang),
                default => $this->ask($user, $text),
            };
        } catch (ValidationException $x) {
            $reply = $this->t($lang, 'error', ['why' => collect($x->errors())->flatten()->implode(' ')]);
        }

        return ['reply' => mb_strimwidth($reply, 0, 1500, '…')];
    }

    private function ask(User $user, string $q): string
    {
        if (! $user->can(R::USE_ASSISTANT)) {
            return $this->t(MessagingContact::withoutGlobalScopes()->where('user_id', $user->id)->value('language') ?? 'en', 'not_allowed');
        }
        $a = app(AgriAssistant::class)->answer($q, $user);
        $lines = [$a['summary']];
        foreach (array_slice($a['rows'] ?? [], 0, 3) as $r) {
            $lines[] = '• '.implode(' · ', array_map(fn ($col) => is_scalar($r[$col] ?? null) ? (string) $r[$col] : '', array_slice($a['columns'], 0, 4)));
        }
        if (! empty($a['period'])) {
            $lines[] = "({$a['period']})";
        }

        return implode("\n", array_filter($lines));
    }

    private function shifts(User $user, string $lang): string
    {
        $worker = FarmWorker::query()->whereHas('person', fn ($q) => $q->withoutGlobalScopes()->where('user_id', $user->id))->first();
        $next = $worker ? FarmShiftAssignment::query()->where('worker_id', $worker->id)->where('status', 'assigned')->whereHas('shift', fn ($q) => $q->where('starts_at', '>=', now()))
            ->with('shift')->get()->sortBy(fn ($a) => $a->shift->starts_at)->take(3) : collect();
        if ($next->isEmpty()) {
            return $this->t($lang, 'no_shifts');
        }

        return $this->t($lang, 'shifts')."\n".$next->map(fn ($a) => '• '.\App\Modules\Agri\Support\FarmTime::local($a->shift->starts_at, $a->shift->farm_id)->format('D j M H:i').' '.$a->shift->title)->implode("\n");
    }

    private function observe(User $user, ?Farm $farm, string $what, string $lang): string
    {
        if (! $farm) {
            return $this->t($lang, 'no_farm');
        }
        if (! $user->can(R::RECORD_ACTIVITIES)) {
            return $this->t($lang, 'not_allowed');
        }
        CropObservation::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'observed_on' => today(), 'kind' => 'other', 'severity' => 'medium', 'status' => 'open',
            'description' => mb_strimwidth("By message: {$what}", 0, 1000), 'action_required' => false, 'recorded_by' => $user->id]);

        return $this->t($lang, 'observed', ['farm' => $farm->name]);
    }

    private function alerts(MessagingContact $c, bool $on, string $lang): string
    {
        $c->update(['alerts' => $on]);

        return $this->t($lang, $on ? 'alerts_on' : 'alerts_off');
    }

    /** Outbound: an alert or a reply, through the configured provider (the log when none is set). */
    public function send(MessagingContact $c, string $text): bool
    {
        $driver = config('agri.messaging.driver', 'log');
        try {
            if ($driver === 'whatsapp_cloud' && $c->channel === 'whatsapp') {
                $r = Http::withToken((string) config('agri.messaging.whatsapp_token'))->timeout(10)->retry(2, 500)
                    ->post('https://graph.facebook.com/v19.0/'.config('agri.messaging.whatsapp_phone_id').'/messages',
                        ['messaging_product' => 'whatsapp', 'to' => ltrim($c->address, '+'), 'type' => 'text', 'text' => ['body' => mb_strimwidth($text, 0, 4000)]]);

                return $r->successful();
            }
            Log::channel(config('logging.default'))->info('agri.message', ['to' => $c->address, 'channel' => $c->channel, 'text' => $text]);

            return true;
        } catch (Throwable $e) {
            Log::warning('agri.message.failed', ['to' => $c->address, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
