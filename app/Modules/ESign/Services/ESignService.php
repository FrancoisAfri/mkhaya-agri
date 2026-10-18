<?php

namespace App\Modules\ESign\Services;

use App\Modules\ESign\Enums\EnvelopeStatus;
use App\Modules\ESign\Enums\SignerStatus;
use App\Core\SMS\Services\SmsService;
use App\Modules\ESign\Jobs\GenerateSignedDocumentJob;
use App\Modules\ESign\Mail\SignerInviteMail;
use App\Modules\ESign\Mail\SignerOtpMail;
use App\Modules\ESign\Models\AuditEntry;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Models\OtpChallenge;
use App\Modules\ESign\Models\Signer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ESignService
{
    /**
     * Create an envelope and its signers.
     *
     * @param  array{title: string, document_type: string, message?: string, expires_at?: string, related_type?: string, related_id?: int}  $envelopeData
     * @param  array<array{name: string, email: string, phone?: string, role?: string, signing_order?: int, user_id?: int}>  $signers
     */
    public function create(array $envelopeData, array $signers, int $createdBy): Envelope
    {
        return DB::transaction(function () use ($envelopeData, $signers, $createdBy) {
            $envelope = Envelope::create(array_merge($envelopeData, ['created_by' => $createdBy]));

            foreach ($signers as $i => $signerData) {
                Signer::create(array_merge($signerData, [
                    'envelope_id'   => $envelope->id,
                    'signing_order' => $signerData['signing_order'] ?? ($i + 1),
                ]));
            }

            $this->audit($envelope, null, 'envelope_created', null, null, null, null, null);

            return $envelope->load('signers');
        });
    }

    /**
     * Send the envelope to all signers: generate tokens, dispatch invite emails.
     */
    public function send(Envelope $envelope, int $senderId, ?Request $request = null): Envelope
    {
        if (!in_array($envelope->status, [EnvelopeStatus::Draft])) {
            throw ValidationException::withMessages([
                'status' => __('esign.flash.send_draft_only'),
            ]);
        }

        /*
         * The invitations are prepared inside the transaction and posted outside it.
         *
         * They used to be sent inside, which on a `sync` queue means the SMTP conversation happens
         * inline. A mail server that accepts the message and is slow to acknowledge it throws a
         * timeout — and that exception rolled the whole transaction back. The recipient held a
         * perfectly real invitation whose token had just been undone: the link was dead, the
         * envelope was still a draft, and nothing in the system recorded that any of it had
         * happened. A sent email cannot be unsent, so the database must be the thing that commits
         * first.
         */
        $invitations = DB::transaction(function () use ($envelope, $senderId, $request) {
            $envelope->status  = EnvelopeStatus::Sent->value;
            $envelope->sent_at = now();
            if (!$envelope->expires_at) {
                $envelope->expires_at = now()->addDays(14);
            }
            $envelope->save();

            $prepared = [];

            foreach ($envelope->signers as $signer) {
                $rawToken = Str::random(64);

                $signer->token            = $rawToken;
                $signer->token_expires_at = $envelope->expires_at;
                $signer->status           = SignerStatus::Notified->value;
                $signer->save();

                $prepared[] = [$signer, route('esign.portal.view', ['token' => $rawToken])];

                $this->audit($envelope, $signer, 'sent', $signer->name, $signer->email,
                    $request?->ip(), $request?->userAgent(), null);
            }

            return $prepared;
        });

        $undelivered = [];

        foreach ($invitations as [$signer, $portalUrl]) {
            try {
                Mail::to($signer->email)->queue(new SignerInviteMail($envelope, $signer, $portalUrl));
            } catch (\Throwable $e) {
                // One unreachable address must not cost the other signers their invitation — on a
                // sync queue an uncaught throw here would end the loop.
                report($e);

                \App\Shared\Models\EmailDeliveryLog::fail(
                    $signer->email,
                    $envelope->title,
                    $e,
                    SignerInviteMail::class,
                );

                $undelivered[] = $signer->name ?: $signer->email;
            }
        }

        $envelope = $envelope->fresh('signers');

        /*
         * Said out loud, because there is no way to send an invitation again: `send()` accepts only
         * a draft, and the envelope is no longer one. Somebody who is not told which signer never
         * received their link has no way to find out except by waiting for a signature that is
         * never coming.
         */
        if ($undelivered !== []) {
            throw ValidationException::withMessages([
                'email' => __('esign.flash.some_invites_failed', ['names' => implode(', ', $undelivered)]),
            ]);
        }

        return $envelope;
    }

    /**
     * Mark a signer as having viewed the document.
     */
    public function recordView(Signer $signer, Request $request): void
    {
        if ($signer->status === SignerStatus::Notified || $signer->status === SignerStatus::Pending) {
            $signer->status     = SignerStatus::Viewed->value;
            $signer->ip_address = $request->ip();
            $signer->user_agent = $request->userAgent();
            $signer->save();

            $this->audit($signer->envelope, $signer, 'viewed', $signer->name, $signer->email,
                $request->ip(), $request->userAgent(), null);

            $this->progressEnvelope($signer->envelope);
        }
    }

    /**
     * Record signer consent.
     */
    public function recordConsent(Signer $signer, Request $request): void
    {
        if ($signer->consent_given) {
            return;
        }

        $signer->consent_given    = true;
        $signer->consent_given_at = now();
        $signer->save();

        $this->audit($signer->envelope, $signer, 'consent_given', $signer->name, $signer->email,
            $request->ip(), $request->userAgent(), null);
    }

    /**
     * Generate and send an OTP to the signer.
     * Prefers SMS to the signer's personal cell when configured; falls back to email.
     * Always also sends to email so there are two delivery paths for CCMA defensibility.
     */
    public function sendOtp(Signer $signer, Request $request): void
    {
        if ($signer->isSigned()) {
            throw ValidationException::withMessages(['otp' => __('esign.flash.already_signed')]);
        }

        $rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $useSms  = $signer->phone && SmsService::isConfigured();
        $channel = $useSms ? 'sms' : 'email';
        $sentTo  = $useSms ? $signer->phone : $signer->email;

        OtpChallenge::create([
            'signer_id'  => $signer->id,
            'code_hash'  => Hash::make($rawCode),
            'channel'    => $channel,
            'sent_to'    => $sentTo,
            'expires_at' => now()->addMinutes(15),
        ]);

        $signer->status = SignerStatus::OtpSent->value;
        $signer->save();

        // Always deliver via email
        Mail::to($signer->email)->queue(new SignerOtpMail($signer, $rawCode));

        // Additionally send via SMS if available — gives two independent channels
        if ($useSms) {
            SmsService::send(
                $signer->phone,
                "MkhayaHub ESign OTP: {$rawCode}. Expires in 15 min. Do not share."
            );
        }

        $this->audit($signer->envelope, $signer, 'otp_sent', $signer->name, $signer->email,
            $request->ip(), $request->userAgent(), [
                'channel' => $channel,
                'sent_to' => $sentTo,
                'sms_sent' => $useSms,
            ]);
    }

    /**
     * One code for a batch of documents.
     *
     * Signing ten appointment letters should not mean ten emails and ten codes typed one at a
     * time — that is the tedium that makes people rubber-stamp without reading, which is the
     * opposite of what a signature is for.
     *
     * The same code is written as a separate challenge against each signer rather than shared by
     * reference. Each envelope then verifies its own challenge through the ordinary sign() path,
     * so every one keeps its own attempt count, its own verified_at and its own audit line. What
     * is saved is the typing, not the evidence.
     *
     * @param  \Illuminate\Support\Collection<int, Signer>  $signers
     */
    public function sendBatchOtp($signers, Request $request): void
    {
        if ($signers->isEmpty()) {
            throw ValidationException::withMessages(['documents' => __('esign.flash.choose_one')]);
        }

        $rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        foreach ($signers as $signer) {
            if ($signer->isSigned()) {
                continue;
            }

            OtpChallenge::create([
                'signer_id'  => $signer->id,
                'code_hash'  => Hash::make($rawCode),
                'channel'    => 'email',
                'sent_to'    => $signer->email,
                'expires_at' => now()->addMinutes(15),
            ]);

            $signer->status = SignerStatus::OtpSent->value;
            $signer->save();

            $this->audit($signer->envelope, $signer, 'otp_sent', $signer->name, $signer->email,
                $request->ip(), $request->userAgent(), ['channel' => 'email', 'batch' => $signers->count()]);
        }

        // One email, however many documents. Sent to the address on the first signer — they are
        // all the same person, which is what makes this a batch.
        Mail::to($signers->first()->email)->queue(new SignerOtpMail($signers->first(), $rawCode));
    }

    /**
     * Verify OTP and record signature (drawn or typed).
     */
    public function sign(
        Signer  $signer,
        string  $rawCode,
        string  $signatureData,
        string  $signatureType,
        Request $request,
    ): Signer {
        if ($signer->isSigned()) {
            throw ValidationException::withMessages(['signature' => __('esign.flash.already_signed')]);
        }

        $challenge = $signer->latestPendingOtp();

        if (!$challenge) {
            throw ValidationException::withMessages(['otp' => 'No active OTP found. Please request a new one.']);
        }

        $challenge->increment('attempts');

        if (!$challenge->verifyCode($rawCode)) {
            $this->audit($signer->envelope, $signer, 'otp_failed', $signer->name, $signer->email,
                $request->ip(), $request->userAgent(), ['attempts' => $challenge->fresh()->attempts]);

            throw ValidationException::withMessages(['otp' => 'Invalid or expired OTP.']);
        }

        return DB::transaction(function () use ($signer, $challenge, $signatureData, $signatureType, $request) {
            $challenge->verified_at = now();
            $challenge->save();

            $signer->status = SignerStatus::Signed->value;

            // A drawn signature is an image; it goes to the shared disk like any other. A typed one
            // is just a name and stays in the row.
            if (is_string($signatureData) && str_starts_with($signatureData, 'data:image')) {
                $binary = base64_decode((string) preg_replace('#^data:[^;]+;base64,#', '', $signatureData), true) ?: '';
                $signer->signature_path = \App\Core\Storage\StoredImage::putBytes($binary, 'esign/signatures');
                $signer->signature_data = null;
            } else {
                $signer->signature_data = $signatureData;
            }
            $signer->signature_type = $signatureType;
            $signer->signed_at      = now();
            $signer->ip_address     = $request->ip();
            $signer->user_agent     = $request->userAgent();
            $signer->save();

            $this->audit($signer->envelope, $signer, 'signed', $signer->name, $signer->email,
                $request->ip(), $request->userAgent(), ['signature_type' => $signatureType]);

            $this->progressEnvelope($signer->envelope);

            return $signer->fresh();
        });
    }

    /**
     * Void an envelope.
     */
    public function void(Envelope $envelope, int $voidedBy, string $reason, ?Request $request = null): Envelope
    {
        if (in_array($envelope->status, [EnvelopeStatus::Completed, EnvelopeStatus::Voided])) {
            throw ValidationException::withMessages([
                'status' => 'Completed or already-voided envelopes cannot be voided.',
            ]);
        }

        $envelope->status        = EnvelopeStatus::Voided->value;
        $envelope->voided_at     = now();
        $envelope->voided_by     = $voidedBy;
        $envelope->voided_reason = $reason;
        $envelope->save();

        $this->audit($envelope, null, 'voided', null, null,
            $request?->ip(), $request?->userAgent(), ['reason' => $reason]);

        return $envelope->fresh();
    }

    /**
     * Remove an envelope for good.
     *
     * Only a draft or a voided one. A completed envelope is signed evidence — the signatures, the
     * audit trail and the certificate are the whole reason the module exists, and the ability to
     * make them disappear would undermine every envelope in the system, not just the one deleted.
     * An envelope that is out with signers is not deletable either: people are holding live links
     * to it, and deleting it turns those into dead ends with no explanation. Void it first, which
     * tells the signers it is cancelled and leaves the record of why.
     *
     * Soft-deleted, so the row and its audit entries survive a mistake. It leaves every list in
     * the module and nothing in the application reads it again.
     */
    public function delete(Envelope $envelope, int $deletedBy, ?Request $request = null): void
    {
        if (! in_array($envelope->status, [EnvelopeStatus::Draft, EnvelopeStatus::Voided], true)) {
            throw ValidationException::withMessages([
                'status' => __('esign.flash.delete_draft_or_voided_only'),
            ]);
        }

        // Written before the delete: the entry belongs to the envelope, and recording it afterwards
        // would attach it to a row the module no longer reads.
        $this->audit($envelope, null, 'deleted', null, null,
            $request?->ip(), $request?->userAgent(), ['status' => $envelope->status->value]);

        $envelope->delete();
    }

    private function progressEnvelope(Envelope $envelope): void
    {
        $envelope->refresh();

        $allSigned = $envelope->signers->every(fn($s) => $s->status === SignerStatus::Signed);

        if ($allSigned) {
            $envelope->status       = EnvelopeStatus::Completed->value;
            $envelope->completed_at = now();
            $envelope->save();

            $this->audit($envelope, null, 'completed', null, null, null, null, null);

            // Generate the signed certificate PDF after commit
            GenerateSignedDocumentJob::dispatch($envelope)->onQueue('documents');
        } elseif ($envelope->status === EnvelopeStatus::Sent) {
            $envelope->status = EnvelopeStatus::InProgress->value;
            $envelope->save();
        }
    }

    private function audit(
        Envelope  $envelope,
        ?Signer   $signer,
        string    $event,
        ?string   $actorName,
        ?string   $actorEmail,
        ?string   $ip,
        ?string   $ua,
        ?array    $metadata,
    ): void {
        AuditEntry::create([
            'envelope_id' => $envelope->id,
            'signer_id'   => $signer?->id,
            'event'       => $event,
            'actor_name'  => $actorName,
            'actor_email' => $actorEmail,
            'ip_address'  => $ip,
            'user_agent'  => $ua,
            'metadata'    => $metadata,
            'created_at'  => now(),
        ]);
    }
}
