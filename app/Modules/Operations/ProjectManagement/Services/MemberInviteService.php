<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Mail\PortalInviteMail;
use App\Modules\Operations\ProjectManagement\Models\MemberInvite;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Inviting an external party to the portal.
 *
 * The project manager sends a link; the recipient sets a password and lands on the portal. No
 * self-registration, because the invitation *is* the authorisation — anybody who could sign
 * themselves up could see a client's project by guessing an address.
 *
 * ── Only external roles ─────────────────────────────────────────────────────
 *
 * Invitations are restricted to the client and contractor roles. The portal is the only thing this
 * grants, and a project manager creating logins for internal staff would be user administration
 * happening in the wrong module, with none of its checks. Somebody who needs a real Hub account
 * gets one from the people who manage accounts.
 *
 * ── An existing account is never given a new password ───────────────────────
 *
 * If the invited address already belongs to a user, accepting links the membership and stops.
 * The alternative — letting the invitation set a password — would mean anybody able to add a
 * project member could take over any account whose address they could guess. So that branch signs
 * nobody in and asks them to log in as themselves.
 */
class MemberInviteService
{
    /** Long enough to survive a fortnight's leave, short enough that a forwarded email goes stale. */
    public const VALID_FOR_DAYS = 14;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Issue an invitation, superseding any that is still outstanding.
     *
     * The plain token is returned on the model as a transient attribute — it is never stored and
     * cannot be read back afterwards, so the caller that needs it for the email is the only one
     * that will ever see it.
     */
    public function invite(ProjectMember $member, ?string $email = null, ?int $invitedBy = null): MemberInvite
    {
        if (! RoleCatalog::isExternal($member->role_key)) {
            throw new InvalidArgumentException(
                'Only client and contractor members can be invited to the portal.'
            );
        }

        /*
         * A member who already has a login may still be sent a fresh link.
         *
         * Refusing was a dead end. An invitation is single-use, so a client who lost the email, or
         * who followed the link and did not realise it had signed them in, could not be sent
         * another — and the project contact they were told to ask was refused too. Nobody could
         * help them.
         *
         * It is safe because accepting never sets a password on an account that already exists
         * (see accept()): the link can link a membership and point somebody at the login screen,
         * and that is all it can ever do.
         */

        $address = trim((string) ($email ?? $member->email ?? ''));

        if ($address === '') {
            throw new InvalidArgumentException('An email address is needed to send an invitation.');
        }

        return DB::transaction(function () use ($member, $address, $invitedBy) {
            // Supersede rather than accumulate: the partial unique index allows one open
            // invitation per member, and leaving an older link working would make "revoke" a lie.
            MemberInvite::where('member_id', $member->id)->open()->update([
                'revoked_at' => now(),
                'revoked_by' => $invitedBy,
            ]);

            $plain = Str::random(64);

            $invite = MemberInvite::create([
                'company_id' => $member->company_id,
                'project_id' => $member->project_id,
                'member_id'  => $member->id,
                'email'      => $address,
                'token_hash' => hash('sha256', $plain),
                'role_key'   => $member->role_key,
                'invited_by' => $invitedBy,
                'expires_at' => now()->addDays(self::VALID_FOR_DAYS),
            ]);

            // The member's own address is brought in line with where the invitation went, so the
            // team screen does not show one address while a live link points at another.
            if ($member->email !== $address) {
                $member->update(['email' => $address]);
            }

            $this->audit->recordAction($invite, 'invite.issued', [
                'member' => $member->uuid,
                'role'   => $member->role_key,
                'email'  => $address,
            ]);

            $invite->plainToken = $plain;

            return $invite;
        });
    }

    /** Issue and send. Split from invite() so a test can inspect the token without a mailer. */
    public function send(ProjectMember $member, ?string $email = null, ?int $invitedBy = null): MemberInvite
    {
        $invite = $this->invite($member, $email, $invitedBy);

        /*
         * A mail server that will not take the message must not cost the invitation.
         *
         * The invitation is already issued and perfectly valid at this point; throwing here left
         * the person who sent it looking at an error, with a live link they could not see and no
         * way to pass it on. On an installation whose SMTP is refusing connections that is every
         * invitation, and the portal becomes unreachable for reasons that have nothing to do with
         * the portal.
         */
        try {
            Mail::to($invite->email)->send(new PortalInviteMail(
                $invite,
                $member->project()->first(),
                $this->linkFor($invite),
            ));

            $invite->delivered = true;
        } catch (\Throwable $e) {
            report($e);

            // The reason, against the address, so the delivery log can say why rather than only
            // that it did not arrive.
            \App\Shared\Models\EmailDeliveryLog::fail(
                $invite->email,
                $member->project()->first()?->name,
                $e,
                PortalInviteMail::class,
            );

            $invite->delivered = false;
        }

        return $invite;
    }

    /** The URL an invitation is taken up at. Only ever known while the plain token is in hand. */
    public function linkFor(MemberInvite $invite): ?string
    {
        return $invite->plainToken
            ? route('project-management.invite.show', ['token' => $invite->plainToken])
            : null;
    }

    /**
     * The invitation a token refers to, whatever its state.
     *
     * State is checked by the caller through refusalReason() rather than filtered out here: an
     * expired invitation should say it expired, not read as though it never existed.
     */
    public function findByToken(string $plainToken): ?MemberInvite
    {
        if ($plainToken === '') {
            return null;
        }

        return MemberInvite::where('token_hash', hash('sha256', $plainToken))->first();
    }

    /**
     * Take up an invitation.
     *
     * @return array{user: User, created: bool} `created` is false when the address already had an
     *                                          account, in which case no password was set and the
     *                                          caller must not sign them in.
     */
    public function accept(MemberInvite $invite, ?string $password = null): array
    {
        if (! $invite->isOpen()) {
            throw new RuntimeException('That invitation can no longer be used.');
        }

        return DB::transaction(function () use ($invite, $password) {
            $member = ProjectMember::withTrashed()->find($invite->member_id);

            if (! $member || $member->trashed()) {
                throw new RuntimeException('The project membership this invitation refers to is gone.');
            }

            // withoutGlobalScope: the accept request is unauthenticated, so no tenant is active and
            // the users scope is inert — but being explicit means this keeps working if that
            // changes, and an address is unique across the platform regardless of company.
            $existing = User::withoutGlobalScope('company')->where('email', $invite->email)->first();
            $created = $existing === null;

            if ($created) {
                if ($password === null || $password === '') {
                    throw new InvalidArgumentException('A password is needed to create the account.');
                }

                $user = new User([
                    'email'    => $invite->email,
                    'password' => Hash::make($password),
                    // The ordinary-user value; portal access is decided by project membership and
                    // the role, not by anything on the user row.
                    'type'     => 1,
                    'status'   => 1,
                ]);
                // Set explicitly: the model auto-stamps from CurrentCompany, which is not resolved
                // on an unauthenticated request, and a user with a null company reaches nothing.
                $user->company_id = $invite->company_id;
                $user->save();
            } else {
                $user = $existing;
            }

            $member->update(['user_id' => $user->id, 'is_active' => true]);

            $invite->update([
                'accepted_at'      => now(),
                'accepted_user_id' => $user->id,
            ]);

            $this->audit->recordAction($invite, 'invite.accepted', [
                'member'          => $member->uuid,
                'created_account' => $created,
            ]);

            return ['user' => $user, 'created' => $created];
        });
    }

    public function revoke(MemberInvite $invite, ?int $by = null): MemberInvite
    {
        if ($invite->accepted_at !== null) {
            throw new RuntimeException('That invitation has already been accepted.');
        }

        $invite->update(['revoked_at' => now(), 'revoked_by' => $by]);

        $this->audit->recordAction($invite, 'invite.revoked', []);

        return $invite;
    }
}
