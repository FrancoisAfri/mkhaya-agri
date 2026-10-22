<?php

namespace App\Modules\Agri\Services;

use App\Core\SMS\Services\SmsService;
use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\Grower;
use App\Modules\Agri\Models\GrowerAccount;
use App\Modules\Agri\Models\GrowerAdvance;
use App\Modules\Agri\Models\GrowerCollection;
use App\Modules\Agri\Models\GrowerContract;
use App\Modules\Agri\Models\GrowerInspection;
use App\Modules\Agri\Models\GrowerNotice;
use App\Modules\Agri\Models\GrowerPortalEvent;
use App\Modules\Agri\Models\GrowerReport;
use App\Modules\Agri\Models\GrowerSettlement;
use App\Modules\Agri\Models\GrowerVisit;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Notifications\GrowerPortalMessage;
use App\Modules\Agri\Support\UiLabels;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Tenancy\Services\CompanyBranding;
use App\Modules\Tenancy\Services\TenantContext;
use App\Shared\Messaging\CompanyMessaging;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The grower portal and grower self-service by WhatsApp / SMS.
 *
 * A contracted grower is invited by the company to a mobile number and/or an email address and
 * signs in with a one-time code sent there (or a password they set once inside). The account is on
 * its own guard and belongs to exactly one grower: every read here takes the grower from the
 * signed-in account and filters on it, so another grower's contract, delivery or statement is a
 * 404 and never a page. A mobile proved by a code sign-in is the number the grower's WhatsApp / SMS
 * commands are answered on, from the same scoped figures.
 *
 * Every sign-in, failure, code sent, revocation, statement download and command is written to the
 * portal events, which the outgrower screen shows as portal activity.
 */
class GrowerPortal
{
    public const CODE_MINUTES = 10;

    public const LOCK_MINUTES = 15;

    /** What the company says to a grower by text or email; replies are short (SMS-sized). */
    private const T = [
        'en' => [
            'invite_subject' => 'Your grower portal at :company',
            'invite' => ':company has opened a grower portal for you: your contracts, deliveries, advances and statements. Sign in with this mobile number or email address; you will be sent a code.',
            'invite_action' => 'Open the grower portal',
            'code_subject' => 'Your sign-in code',
            'code' => ':company: your grower portal sign-in code is :code. It expires in :minutes minutes. Do not share it.',
            'notice_subject' => 'New message from :company',
            'notice' => ':company: :title. Read it in your grower portal.',
            'answer' => ':company answered your :kind report: :response',
            'help' => 'Send: "balance", "deliveries", "statement" or "next visit".',
            'balance' => ':code — delivered R:collected; advances outstanding R:advances; to be paid R:to_pay.',
            'no_deliveries' => 'No deliveries recorded yet.',
            'deliveries' => 'Your last deliveries:',
            'statement' => 'Statement :code: delivered R:collected, advanced R:advanced, recovered R:recovered, to be paid R:to_pay. Full statement: :url',
            'no_visit' => 'No visits are planned.',
            'next_visit' => 'Next visit: :date — :purpose:contract.',
            'ambiguous' => 'This number is on more than one grower account. Please use your grower portal.',
        ],
        'af' => [
            'invite_subject' => 'Jou produsenteportaal by :company',
            'invite' => ':company het \'n produsenteportaal vir jou oopgemaak: jou kontrakte, leverings, voorskotte en state. Meld aan met hierdie selnommer of e-posadres; jy sal \'n kode kry.',
            'invite_action' => 'Open die produsenteportaal',
            'code_subject' => 'Jou aanmeldkode',
            'code' => ':company: jou aanmeldkode vir die produsenteportaal is :code. Dit verval oor :minutes minute. Moet dit met niemand deel nie.',
            'notice_subject' => 'Nuwe boodskap van :company',
            'notice' => ':company: :title. Lees dit in jou produsenteportaal.',
            'answer' => ':company het jou :kind-verslag beantwoord: :response',
            'help' => 'Stuur: "saldo", "leverings", "staat" of "volgende besoek".',
            'balance' => ':code — gelewer R:collected; uitstaande voorskotte R:advances; nog te betaal R:to_pay.',
            'no_deliveries' => 'Nog geen leverings aangeteken nie.',
            'deliveries' => 'Jou laaste leverings:',
            'statement' => 'Staat :code: gelewer R:collected, voorgeskiet R:advanced, verhaal R:recovered, nog te betaal R:to_pay. Volle staat: :url',
            'no_visit' => 'Geen besoeke is beplan nie.',
            'next_visit' => 'Volgende besoek: :date — :purpose:contract.',
            'ambiguous' => 'Hierdie nommer is op meer as een produsenterekening. Gebruik asseblief jou produsenteportaal.',
        ],
    ];

    private const PURPOSES = ['en' => ['inspection' => 'crop inspection', 'field_visit' => 'field visit', 'collection' => 'collection', 'training' => 'training'],
        'af' => ['inspection' => 'oesinspeksie', 'field_visit' => 'plaasbesoek', 'collection' => 'afhaal', 'training' => 'opleiding']];

    public function __construct(private readonly Outgrowers $outgrowers, private readonly TenantContext $tenants, private readonly CompanyBranding $branding) {}

    private function t(?string $lang, string $key, array $r = []): string
    {
        $s = self::T[$lang ?? 'en'][$key] ?? self::T['en'][$key];
        foreach ($r as $k => $v) {
            $s = str_replace(":{$k}", (string) $v, $s);
        }

        return $s;
    }

    /** The name a grower knows the company by: its own, white-labelled or not. */
    public function companyName(int $companyId): string
    {
        $b = $this->branding->for($companyId, withLogoData: false);

        return (string) ($b['white_label'] ? $b['name'] : ($b['company_name'] ?? $b['name']));
    }

    /** +27… from what was typed (a South African 0… number is accepted). */
    public static function normaliseMobile(?string $raw): ?string
    {
        $n = preg_replace('/[\s\-()]/', '', (string) $raw);
        if ($n === '') {
            return null;
        }
        if (preg_match('/^0\d{9}$/', $n)) {
            $n = '+27'.substr($n, 1);
        } elseif (preg_match('/^27\d{9}$/', $n)) {
            $n = '+'.$n;
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $n) ? $n : throw ValidationException::withMessages(['mobile' => 'Give the mobile number as 082 123 4567 or +27821234567.']);
    }

    // ── staff: invite, revoke, restore ──────────────────────────────────────────────────────────

    public function invite(Grower $g, array $data, User $actor): GrowerAccount
    {
        $mobile = self::normaliseMobile($data['mobile'] ?? null);
        $email = filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
        if (! $mobile && ! $email) {
            throw ValidationException::withMessages(['mobile' => 'Give the grower\'s mobile number or email address to invite them.']);
        }
        $clash = GrowerAccount::query()->where('company_id', $g->company_id)->where('grower_id', '!=', $g->id)->where('status', '!=', GrowerAccount::REVOKED)
            ->where(fn ($q) => $q->when($mobile, fn ($w) => $w->orWhere('mobile', $mobile))->when($email, fn ($w) => $w->orWhere('email', $email)))->first();
        if ($clash) {
            throw ValidationException::withMessages(['mobile' => 'That number or address already signs in as another grower.']);
        }
        $a = GrowerAccount::query()->firstOrNew(['company_id' => $g->company_id, 'grower_id' => $g->id]);
        $changed = $a->exists && ($a->mobile !== $mobile || $a->email !== $email);
        $a->fill(['name' => $data['name'] ?? $a->name ?? $g->party?->name ?? $g->code, 'mobile' => $mobile, 'email' => $email,
            'locale' => in_array($data['locale'] ?? null, GrowerAccount::LOCALES, true) ? $data['locale'] : ($a->locale ?? 'en'),
            'invited_at' => now(), 'invited_by' => $actor->id]);
        if (! $a->exists || $a->status === GrowerAccount::REVOKED) {
            $a->status = GrowerAccount::INVITED;
            $a->revoked_at = null;
        }
        if ($changed) {
            // A new number or address has to be proved again before WhatsApp answers on it.
            $a->mobile_verified_at = $a->getOriginal('mobile') === $mobile ? $a->mobile_verified_at : null;
            $a->email_verified_at = $a->getOriginal('email') === $email ? $a->email_verified_at : null;
        }
        $a->save();
        $this->event($a, 'invited', 'web', null, null, ['mobile' => $mobile, 'email' => $email], $actor->id);

        $company = $this->companyName((int) $a->company_id);
        $url = $this->loginUrl($a);
        $via = $email ? 'email' : $this->defaultTextChannel((int) $a->company_id);
        $this->tell($a, $via, $this->t($a->locale, 'invite_subject', ['company' => $company]), $this->t($a->locale, 'invite', ['company' => $company]), $url,
            $this->t($a->locale, 'invite_action'));
        if ($email && $mobile) {
            $this->tell($a, $this->defaultTextChannel((int) $a->company_id), '', $this->t($a->locale, 'invite', ['company' => $company]), $url);
        }

        return $a->fresh();
    }

    public function revoke(GrowerAccount $a, User $actor, ?string $reason = null): GrowerAccount
    {
        $a->forceFill(['status' => GrowerAccount::REVOKED, 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revoked_reason' => $reason,
            'otp_hash' => null, 'otp_expires_at' => null, 'remember_token' => null])->save();
        $this->event($a, 'revoked', 'web', null, null, $reason ? ['reason' => $reason] : null, $actor->id);

        return $a;
    }

    public function restore(GrowerAccount $a, User $actor): GrowerAccount
    {
        $a->forceFill(['status' => $a->last_login_at ? GrowerAccount::ACTIVE : GrowerAccount::INVITED, 'revoked_at' => null, 'revoked_by' => null,
            'revoked_reason' => null, 'failed_attempts' => 0, 'locked_until' => null])->save();
        $this->event($a, 'restored', 'web', null, null, null, $actor->id);

        return $a;
    }

    public function loginUrl(GrowerAccount $a): string
    {
        return route('grower.portal.login', ['c' => $this->branding->for((int) $a->company_id, withLogoData: false)['company_uuid']]);
    }

    // ── sign-in ──────────────────────────────────────────────────────────────────────────────────

    /**
     * The account a mobile number or email address signs in as. Read with no tenant in play — the
     * visitor has none yet — and narrowed to the company in their invitation link when there is
     * one. More than one match without a company is refused rather than guessed.
     */
    public function find(string $identifier, ?string $companyUuid = null): ?GrowerAccount
    {
        $identifier = trim($identifier);
        $isEmail = str_contains($identifier, '@');
        try {
            $value = $isEmail ? mb_strtolower($identifier) : self::normaliseMobile($identifier);
        } catch (ValidationException) {
            return null;
        }
        if (! $value) {
            return null;
        }

        return $this->tenants->run(null, function () use ($isEmail, $value, $companyUuid) {
            $q = GrowerAccount::withoutGlobalScopes()->where($isEmail ? 'email' : 'mobile', $value)->where('status', '!=', GrowerAccount::REVOKED);
            if ($companyUuid) {
                $q->whereIn('company_id', DB::table('companies')->where('uuid', $companyUuid)->pluck('id'));
            }
            $rows = $q->limit(2)->get();

            return $rows->count() === 1 ? $rows->first() : null;
        });
    }

    /** A revoked account, for the "this access was withdrawn" message (never to sign in). */
    public function findRevoked(string $identifier, ?string $companyUuid = null): ?GrowerAccount
    {
        $isEmail = str_contains($identifier, '@');
        try {
            $value = $isEmail ? mb_strtolower(trim($identifier)) : self::normaliseMobile($identifier);
        } catch (ValidationException) {
            return null;
        }

        return $value ? $this->tenants->run(null, fn () => GrowerAccount::withoutGlobalScopes()->where($isEmail ? 'email' : 'mobile', $value)
            ->where('status', GrowerAccount::REVOKED)->when($companyUuid, fn ($q) => $q->whereIn('company_id', DB::table('companies')->where('uuid', $companyUuid)->pluck('id')))->first()) : null;
    }

    /** Send a one-time code to the address the grower signed in with (WhatsApp or SMS for a mobile). */
    public function sendCode(GrowerAccount $a, string $identifier, ?string $via, ?string $ip, ?string $agent): void
    {
        $channel = str_contains($identifier, '@') ? 'email' : (in_array($via, ['whatsapp', 'sms'], true) ? $via : $this->defaultTextChannel((int) $a->company_id));
        $code = (string) random_int(100000, 999999);
        $this->tenants->run((int) $a->company_id, function () use ($a, $channel, $code, $ip, $agent) {
            $a->forceFill(['otp_hash' => hash('sha256', $code), 'otp_channel' => $channel, 'otp_expires_at' => now()->addMinutes(self::CODE_MINUTES), 'otp_attempts' => 0])->save();
            $company = $this->companyName((int) $a->company_id);
            $this->tell($a, $channel, $this->t($a->locale, 'code_subject'), $this->t($a->locale, 'code', ['company' => $company, 'code' => $code, 'minutes' => self::CODE_MINUTES]), null, null, $code);
            $this->event($a, 'code_sent', $channel, $ip, $agent);
        });
    }

    /** True and the account (now active, with the address proved) when the code is right. */
    public function verifyCode(GrowerAccount $a, string $code, ?string $ip, ?string $agent): bool
    {
        return $this->tenants->run((int) $a->company_id, function () use ($a, $code, $ip, $agent) {
            $a->refresh();
            if (! $a->canSignIn()) {
                $this->event($a, 'sign_in_failed', 'web', $ip, $agent, ['why' => $a->status === GrowerAccount::REVOKED ? 'revoked' : 'locked']);

                return false;
            }
            $ok = $a->otp_hash && $a->otp_expires_at?->isFuture() && $a->otp_attempts < GrowerAccount::MAX_ATTEMPTS
                && hash_equals($a->otp_hash, hash('sha256', preg_replace('/\D/', '', $code)));
            if (! $ok) {
                $a->forceFill(['otp_attempts' => $a->otp_attempts + 1])->save();
                $this->failed($a, $ip, $agent, 'code');

                return false;
            }
            $proved = $a->otp_channel === 'email' ? ['email_verified_at' => now()] : ['mobile_verified_at' => now()];
            $a->forceFill($proved + ['otp_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0])->save();
            $this->signedIn($a, $ip, $agent, $a->otp_channel);

            return true;
        });
    }

    public function attemptPassword(GrowerAccount $a, string $password, ?string $ip, ?string $agent): bool
    {
        return $this->tenants->run((int) $a->company_id, function () use ($a, $password, $ip, $agent) {
            $a->refresh();
            if (! $a->canSignIn() || ! $a->password || ! password_verify($password, $a->password)) {
                $this->failed($a, $ip, $agent, 'password');

                return false;
            }
            $this->signedIn($a, $ip, $agent, 'password');

            return true;
        });
    }

    private function signedIn(GrowerAccount $a, ?string $ip, ?string $agent, ?string $how): void
    {
        $a->forceFill(['status' => GrowerAccount::ACTIVE, 'failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => $ip])->save();
        $this->event($a, 'signed_in', 'web', $ip, $agent, ['by' => $how]);
    }

    private function failed(GrowerAccount $a, ?string $ip, ?string $agent, string $how): void
    {
        $n = $a->failed_attempts + 1;
        $a->forceFill(['failed_attempts' => $n, 'locked_until' => $n >= GrowerAccount::MAX_ATTEMPTS ? now()->addMinutes(self::LOCK_MINUTES) : $a->locked_until])->save();
        $this->event($a, 'sign_in_failed', 'web', $ip, $agent, ['by' => $how]);
    }

    public function setPassword(GrowerAccount $a, string $password): void
    {
        $a->forceFill(['password' => $password])->save();
        $this->event($a, 'password_set', 'web');
    }

    public function event(GrowerAccount $a, string $event, ?string $channel = null, ?string $ip = null, ?string $agent = null, ?array $detail = null, ?int $actorId = null): void
    {
        GrowerPortalEvent::create(['company_id' => $a->company_id, 'grower_id' => $a->grower_id, 'account_id' => $a->id, 'event' => $event, 'channel' => $channel,
            'ip' => $ip, 'user_agent' => $agent ? mb_strimwidth($agent, 0, 300) : null, 'detail' => $detail, 'actor_id' => $actorId, 'created_at' => now()]);
    }

    // ── outbound ─────────────────────────────────────────────────────────────────────────────────

    private function defaultTextChannel(int $companyId): string
    {
        return app(CompanyMessaging::class)->usable($companyId, 'whatsapp') || config('agri.messaging.driver') === 'whatsapp_cloud' ? 'whatsapp' : 'sms';
    }

    private function tell(GrowerAccount $a, string $channel, string $subject, string $text, ?string $url = null, ?string $action = null, ?string $code = null): void
    {
        if (($channel === 'email' && ! $a->email) || ($channel !== 'email' && ! $a->mobile)) {
            return;
        }
        try {
            $a->notify(new GrowerPortalMessage($channel, $subject, $text, $url, $action, $code));
        } catch (\Throwable $e) {
            Log::warning('Grower portal message not sent', ['account' => $a->id, 'channel' => $channel, 'error' => $e->getMessage()]);
        }
    }

    /** A text to the grower's mobile: the company's WhatsApp template, the agri WhatsApp driver, else SMS. */
    public function deliverText(GrowerAccount $a, string $channel, string $text): bool
    {
        if (! $a->mobile) {
            return false;
        }

        return $this->tenants->run((int) $a->company_id, function () use ($a, $channel, $text) {
            if ($channel === 'whatsapp') {
                $cm = app(CompanyMessaging::class);
                if ($cm->usable((int) $a->company_id, 'whatsapp')) {
                    return $cm->whatsappTo((int) $a->company_id, $a->mobile, [$text]) === null;
                }
                if (config('agri.messaging.driver') === 'whatsapp_cloud') {
                    return app(AgriMessaging::class)->sendTo('whatsapp', $a->mobile, $text);
                }
            }
            if (app()->runningUnitTests()) {
                return true;
            }

            return SmsService::send($a->mobile, mb_strimwidth($text, 0, 300), 'otp');
        });
    }

    // ── what the grower sees: everything filtered on their own grower ───────────────────────────

    public function grower(GrowerAccount $a): Grower
    {
        return Grower::query()->where('company_id', $a->company_id)->findOrFail($a->grower_id);
    }

    public function contracts(Grower $g): Collection
    {
        return GrowerContract::query()->where('grower_id', $g->id)->where('status', '!=', 'draft')->orderByDesc('id')->get()->map(function (GrowerContract $c) {
            $delivered = GrowerCollection::query()->where('contract_id', $c->id)->get();

            return ['id' => $c->uuid, 'reference' => $c->reference, 'product' => $c->product, 'crop' => $c->crop_type_id ? DB::table('crop_types')->where('id', $c->crop_type_id)->value('name') : null,
                'hectares' => $c->hectares, 'expected_quantity' => $c->expected_quantity, 'unit' => $c->unit, 'price' => $c->price,
                'grades' => collect($c->grade_prices ?? [])->map(fn ($p, $grade) => ['grade' => $grade, 'price' => (float) $p])->values()->all(),
                'advance_limit' => $c->advance_limit, 'status' => $c->status,
                'delivered' => round((float) $delivered->sum(fn ($d) => $d->quantity - $d->rejected), 3), 'delivered_value' => round((float) $delivered->sum('value'), 2)];
        })->values();
    }

    public function contract(Grower $g, string $uuid): GrowerContract
    {
        return GrowerContract::query()->where('grower_id', $g->id)->where('status', '!=', 'draft')->where('uuid', $uuid)->firstOrFail();
    }

    public function deliveries(Grower $g, ?GrowerContract $only = null, ?int $limit = null): Collection
    {
        $rows = GrowerCollection::query()->where('grower_id', $g->id)->when($only, fn ($q) => $q->where('contract_id', $only->id))
            ->orderByDesc('collected_on')->orderByDesc('id')->when($limit, fn ($q) => $q->limit($limit))->get();
        $lots = ProduceLot::withoutGlobalScopes()->where('company_id', $g->company_id)->whereIn('id', $rows->pluck('lot_id')->filter())->pluck('code', 'id');
        $contracts = GrowerContract::query()->whereIn('id', $rows->pluck('contract_id'))->get(['id', 'reference', 'unit'])->keyBy('id');
        $settled = GrowerSettlement::query()->whereIn('id', $rows->pluck('settlement_id')->filter())->pluck('reference', 'id');

        return $rows->map(fn (GrowerCollection $c) => ['id' => $c->uuid, 'date' => $c->collected_on?->toDateString(), 'contract' => $contracts[$c->contract_id]->reference ?? null,
            'unit' => $contracts[$c->contract_id]->unit ?? null, 'lot' => $lots[$c->lot_id] ?? null, 'delivered' => $c->quantity, 'rejected' => $c->rejected,
            'accepted' => round($c->quantity - $c->rejected, 3), 'grade' => $c->grade, 'price' => $c->price, 'value' => $c->value,
            'settlement' => $settled[$c->settlement_id] ?? null])->values();
    }

    public function advances(Grower $g): array
    {
        $rows = GrowerAdvance::query()->where('grower_id', $g->id)->orderByDesc('issued_on')->orderByDesc('id')->get();
        $items = FinStockItem::withoutGlobalScopes()->whereIn('id', $rows->pluck('stock_item_id')->filter())->pluck('name', 'id');
        $contracts = GrowerContract::query()->whereIn('id', $rows->pluck('contract_id'))->pluck('reference', 'id');

        return ['rows' => $rows->map(fn (GrowerAdvance $a) => ['id' => $a->uuid, 'date' => $a->issued_on?->toDateString(), 'kind' => $a->kind, 'contract' => $contracts[$a->contract_id] ?? null,
            'item' => $a->stock_item_id ? ($items[$a->stock_item_id] ?? null) : null, 'quantity' => $a->quantity, 'value' => $a->value, 'recovered' => $a->recovered,
            'balance' => round($a->value - $a->recovered, 2)])->values()->all(),
            'total' => round((float) $rows->sum('value'), 2), 'recovered' => round((float) $rows->sum('recovered'), 2),
            'balance' => round((float) $rows->sum(fn ($a) => $a->value - $a->recovered), 2)];
    }

    public function settlements(Grower $g): Collection
    {
        return GrowerSettlement::query()->where('grower_id', $g->id)->orderByDesc('id')->get()->map(fn (GrowerSettlement $s) => ['id' => $s->uuid, 'reference' => $s->reference,
            'date' => $s->created_at?->toDateString(), 'gross' => $s->gross, 'advances_recovered' => $s->advances_recovered, 'net' => $s->net, 'status' => $s->status])->values();
    }

    public function settlement(Grower $g, string $uuid): GrowerSettlement
    {
        return GrowerSettlement::query()->where('grower_id', $g->id)->where('uuid', $uuid)->firstOrFail();
    }

    public function visits(Grower $g): array
    {
        $contracts = GrowerContract::query()->where('grower_id', $g->id)->pluck('reference', 'id');

        return [
            'upcoming' => GrowerVisit::query()->where('grower_id', $g->id)->where('status', 'planned')->whereDate('planned_on', '>=', today())->orderBy('planned_on')->get()
                ->map(fn (GrowerVisit $v) => ['id' => $v->uuid, 'date' => $v->planned_on?->toDateString(), 'purpose' => $v->purpose, 'contract' => $contracts[$v->contract_id] ?? null,
                    'notes' => $v->notes])->values()->all(),
            'past' => GrowerInspection::query()->where('grower_id', $g->id)->orderByDesc('inspected_on')->limit(20)->get()
                ->map(fn (GrowerInspection $i) => ['id' => $i->uuid, 'date' => $i->inspected_on?->toDateString(), 'stage' => $i->stage, 'outcome' => $i->outcome,
                    'estimated_quantity' => $i->estimated_quantity, 'contract' => $contracts[$i->contract_id] ?? null, 'notes' => $i->notes])->values()->all(),
        ];
    }

    public function notices(Grower $g, ?int $limit = null): Collection
    {
        return GrowerNotice::query()->where('farm_id', $g->farm_id)->where(fn ($q) => $q->whereNull('grower_id')->orWhere('grower_id', $g->id))
            ->orderByDesc('created_at')->orderByDesc('id')->when($limit, fn ($q) => $q->limit($limit))->get()
            ->map(fn (GrowerNotice $n) => ['id' => $n->uuid, 'title' => $n->title, 'body' => $n->body, 'date' => $n->created_at?->format('Y-m-d'), 'to_me' => (bool) $n->grower_id])->values();
    }

    public function reports(Grower $g): Collection
    {
        $contracts = GrowerContract::query()->where('grower_id', $g->id)->pluck('reference', 'id');

        return GrowerReport::query()->where('grower_id', $g->id)->orderByDesc('id')->limit(50)->get()->map(fn (GrowerReport $r) => ['id' => $r->uuid, 'kind' => $r->kind,
            'date' => $r->created_at?->format('Y-m-d'), 'contract' => $contracts[$r->contract_id] ?? null, 'planted_on' => $r->planted_on?->toDateString(), 'hectares' => $r->hectares,
            'details' => $r->details, 'has_photo' => (bool) $r->photo_name, 'status' => $r->status, 'response' => $r->response])->values();
    }

    public function summary(Grower $g): array
    {
        return $this->outgrowers->statement($g);
    }

    /** A planting report or an issue, with an optional photo kept in the database. */
    public function submitReport(GrowerAccount $a, array $data, ?UploadedFile $photo): GrowerReport
    {
        $g = $this->grower($a);
        $contract = filled($data['contract_id'] ?? null) ? $this->contract($g, $data['contract_id']) : null;
        $r = GrowerReport::create(['company_id' => $g->company_id, 'grower_id' => $g->id, 'contract_id' => $contract?->id, 'account_id' => $a->id, 'kind' => $data['kind'],
            'planted_on' => $data['planted_on'] ?? null, 'hectares' => $data['hectares'] ?? null, 'details' => $data['details'], 'status' => 'open']
            + ($photo ? ['photo_name' => mb_strimwidth($photo->getClientOriginalName(), 0, 200), 'photo_mime' => $photo->getMimeType(),
                'photo_data' => base64_encode((string) file_get_contents($photo->getRealPath()))] : []));
        $this->event($a, 'report', 'web', null, null, ['kind' => $r->kind, 'report' => $r->uuid]);

        return $r;
    }

    // ── staff: notices, visits, answering reports ───────────────────────────────────────────────

    public function notice(Farm $farm, ?Grower $only, string $title, string $body, User $actor): GrowerNotice
    {
        $n = GrowerNotice::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'grower_id' => $only?->id, 'title' => $title, 'body' => $body, 'sent_by' => $actor->id]);
        $company = $this->companyName((int) $farm->company_id);
        $growers = $only ? collect([$only->id]) : Grower::query()->where('farm_id', $farm->id)->pluck('id');
        foreach (GrowerAccount::query()->whereIn('grower_id', $growers)->where('status', GrowerAccount::ACTIVE)->get() as $a) {
            $channel = $a->mobile_verified_at ? $this->defaultTextChannel((int) $a->company_id) : ($a->email ? 'email' : null);
            if ($channel) {
                $this->tell($a, $channel, $this->t($a->locale, 'notice_subject', ['company' => $company]), $this->t($a->locale, 'notice', ['company' => $company, 'title' => $title]),
                    $this->loginUrl($a));
            }
        }

        return $n;
    }

    public function scheduleVisit(GrowerContract $c, array $data, User $actor): GrowerVisit
    {
        return GrowerVisit::create(['company_id' => $c->company_id, 'grower_id' => $c->grower_id, 'contract_id' => $c->id, 'planned_on' => $data['planned_on'],
            'purpose' => $data['purpose'] ?? 'inspection', 'notes' => $data['notes'] ?? null, 'status' => 'planned', 'officer_id' => $actor->id, 'created_by' => $actor->id]);
    }

    public function answer(GrowerReport $r, string $response, string $status, User $actor): GrowerReport
    {
        $r->update(['response' => $response, 'status' => $status, 'answered_by' => $actor->id, 'answered_at' => now()]);
        $a = GrowerAccount::query()->where('grower_id', $r->grower_id)->where('status', GrowerAccount::ACTIVE)->first();
        if ($a) {
            $channel = $a->mobile_verified_at ? $this->defaultTextChannel((int) $a->company_id) : ($a->email ? 'email' : null);
            if ($channel) {
                $company = $this->companyName((int) $a->company_id);
                $this->tell($a, $channel, $this->t($a->locale, 'notice_subject', ['company' => $company]),
                    $this->t($a->locale, 'answer', ['company' => $company, 'kind' => $r->kind, 'response' => mb_strimwidth($response, 0, 200, '…')]));
            }
        }

        return $r;
    }

    /** Portal activity for the outgrower screen. */
    public function activity(Collection $growerIds, int $limit = 100): Collection
    {
        $codes = Grower::query()->whereIn('id', $growerIds)->pluck('code', 'id');
        $staff = [];

        return GrowerPortalEvent::query()->whereIn('grower_id', $growerIds)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(function (GrowerPortalEvent $e) use ($codes, &$staff) {
                if ($e->actor_id && ! isset($staff[$e->actor_id])) {
                    $staff[$e->actor_id] = (string) (User::withoutGlobalScopes()->find($e->actor_id)?->name ?? '');
                }

                return ['id' => $e->uuid, 'at' => $e->created_at?->format('Y-m-d H:i'), 'grower' => $codes[$e->grower_id] ?? null, 'event' => $e->event,
                    'channel' => $e->channel, 'ip' => $e->ip, 'by' => $e->actor_id ? ($staff[$e->actor_id] ?? null) : null, 'detail' => $e->detail];
            })->values();
    }

    // ── the statement ───────────────────────────────────────────────────────────────────────────

    /** The statement's figures: everything, or one settlement and the deliveries it paid for. */
    public function statementData(Grower $g, ?GrowerSettlement $s = null): array
    {
        $farm = Farm::withoutGlobalScopes()->where('company_id', $g->company_id)->find($g->farm_id);
        $deliveries = $s
            ? $this->deliveries($g)->filter(fn ($d) => $d['settlement'] === $s->reference)->values()
            : $this->deliveries($g);

        return ['grower' => ['code' => $g->code, 'name' => $g->party?->name, 'location' => $g->location], 'farm' => $farm?->name,
            'settlement' => $s ? ['reference' => $s->reference, 'date' => $s->created_at?->toDateString(), 'gross' => $s->gross, 'advances_recovered' => $s->advances_recovered,
                'net' => $s->net, 'status' => $s->status] : null,
            'summary' => $this->summary($g), 'deliveries' => $deliveries->all(), 'advances' => $this->advances($g), 'settlements' => $s ? [] : $this->settlements($g)->all(),
            'generated' => now()->format('Y-m-d H:i')];
    }

    public function statementHtml(Grower $g, ?GrowerSettlement $s = null): string
    {
        return view('agri.grower-statement', ['d' => $this->statementData($g, $s), 'brand' => $this->branding->for((int) $g->company_id),
            'company' => $this->companyName((int) $g->company_id),
            'L' => fn (string $k, array $p = []) => UiLabels::get('agri.grower_portal.statement.'.$k, $p)])->render();
    }

    public function statementPdf(Grower $g, ?GrowerSettlement $s = null): string
    {
        return Pdf::loadHTML($this->statementHtml($g, $s))->setPaper('a4')->output();
    }

    // ── WhatsApp / SMS commands ─────────────────────────────────────────────────────────────────

    /**
     * The reply to a message from a grower's number, or null when the number is not a grower's
     * proved number (so the caller gives its usual "not linked" answer). Only an active, not revoked
     * account whose mobile was proved by a code sign-in is answered — and only with that grower's
     * own figures.
     */
    public function messageReply(string $channel, string $from, string $text): ?string
    {
        $accounts = $this->tenants->run(null, fn () => GrowerAccount::withoutGlobalScopes()->where('mobile', $from)->where('status', GrowerAccount::ACTIVE)
            ->whereNotNull('mobile_verified_at')->limit(2)->get());
        if ($accounts->isEmpty()) {
            return null;
        }
        if ($accounts->count() > 1) {
            return $this->t($accounts->first()->locale, 'ambiguous');
        }
        $a = $accounts->first();

        return $this->tenants->run((int) $a->company_id, function () use ($a, $channel, $text) {
            $lang = $a->locale;
            $g = $this->grower($a);
            $lower = trim(mb_strtolower($text));
            [$command, $reply] = match (true) {
                in_array($lower, ['balance', 'saldo', 'bal'], true) => ['balance', $this->balanceReply($g, $lang)],
                in_array($lower, ['deliveries', 'delivery', 'leverings', 'lewering'], true) => ['deliveries', $this->deliveriesReply($g, $lang)],
                in_array($lower, ['statement', 'staat'], true) => ['statement', $this->statementReply($a, $g, $lang)],
                (bool) preg_match('/^(next\s+visit|visit|visits|volgende\s+besoek|besoek)$/u', $lower) => ['next_visit', $this->visitReply($g, $lang)],
                default => ['help', $this->t($lang, 'help')],
            };
            $this->event($a, 'command', $channel, null, null, ['command' => $command]);

            return mb_strimwidth($reply, 0, 600, '…');
        });
    }

    private function money(float $v): string
    {
        return number_format($v, 2, '.', ' ');
    }

    private function balanceReply(Grower $g, string $lang): string
    {
        $s = $this->summary($g);

        return $this->t($lang, 'balance', ['code' => $g->code, 'collected' => $this->money($s['collected_value']), 'advances' => $this->money($s['advances_outstanding']),
            'to_pay' => $this->money($s['to_pay'])]);
    }

    private function deliveriesReply(Grower $g, string $lang): string
    {
        $rows = $this->deliveries($g, null, 3);
        if ($rows->isEmpty()) {
            return $this->t($lang, 'no_deliveries');
        }

        return $this->t($lang, 'deliveries')."\n".$rows->map(fn ($d) => '• '.$d['date'].' · '.rtrim(rtrim(number_format($d['accepted'], 3, '.', ''), '0'), '.').' '.$d['unit']
            .($d['grade'] ? ' '.$d['grade'] : '').($d['lot'] ? ' · '.$d['lot'] : '').' · R'.$this->money($d['value']))->implode("\n");
    }

    private function statementReply(GrowerAccount $a, Grower $g, string $lang): string
    {
        $s = $this->summary($g);

        return $this->t($lang, 'statement', ['code' => $g->code, 'collected' => $this->money($s['collected_value']), 'advanced' => $this->money($s['advanced']),
            'recovered' => $this->money($s['recovered']), 'to_pay' => $this->money($s['to_pay']), 'url' => route('grower.portal.statements')]);
    }

    private function visitReply(Grower $g, string $lang): string
    {
        $v = GrowerVisit::query()->where('grower_id', $g->id)->where('status', 'planned')->whereDate('planned_on', '>=', today())->orderBy('planned_on')->first();
        if (! $v) {
            return $this->t($lang, 'no_visit');
        }
        $ref = $v->contract_id ? GrowerContract::query()->whereKey($v->contract_id)->value('reference') : null;

        return $this->t($lang, 'next_visit', ['date' => $v->planned_on->format('D j M Y'), 'purpose' => self::PURPOSES[$lang][$v->purpose] ?? $v->purpose,
            'contract' => $ref ? " ({$ref})" : '']);
    }
}
