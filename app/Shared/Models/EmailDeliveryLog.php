<?php

namespace App\Shared\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One attempt to send one email.
 *
 * `status` says what this system observed, not what the recipient experienced: SMTP accepting a
 * message is not the same as it arriving, and a log that claimed "delivered" would be telling
 * somebody a comforting thing it has no way of knowing.
 */
class EmailDeliveryLog extends Model
{
    use BelongsToCompany;

    protected $table = 'email_delivery_log';

    protected $fillable = [
        'uuid', 'company_id', 'to', 'subject', 'mailable',
        'status', 'error', 'message_id', 'mailer', 'user_id',
    ];

    /** Written before the transport is attempted; a row still here is one whose send never came back. */
    public const PENDING = 'pending';

    /** The transport accepted it. Not the same as the recipient having it, which we cannot see. */
    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const STATUSES = [self::PENDING, self::SENT, self::FAILED];

    /**
     * Record a send that threw.
     *
     * Called from wherever a mail failure is caught, because a transport that throws never reaches
     * `MessageSent` — there is no event to listen for. Where the attempt was already logged as
     * pending, the same row is completed rather than a second one written.
     */
    public static function fail(string $to, ?string $subject, \Throwable $e, ?string $mailable = null): void
    {
        try {
            $pending = static::query()
                ->where('to', $to)
                ->where('status', self::PENDING)
                ->latest('id')
                ->first();

            $attributes = [
                'status' => self::FAILED,
                'error'  => \Illuminate\Support\Str::limit($e->getMessage(), 1000, ''),
            ];

            if ($pending) {
                $pending->update($attributes);

                return;
            }

            static::create($attributes + [
                'uuid'     => (string) \Illuminate\Support\Str::uuid(),
                'to'       => $to,
                'subject'  => $subject,
                'mailable' => $mailable,
                'mailer'   => config('mail.default'),
                'user_id'  => \Illuminate\Support\Facades\Auth::id(),
            ]);
        } catch (\Throwable $logging) {
            // A log that can break a send is worse than no log.
            report($logging);
        }
    }
}
