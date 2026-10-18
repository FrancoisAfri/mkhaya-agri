<?php

namespace Tests\Feature\Auth;

use App\Core\Auth\Mail\SendOtpsMail;
use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A one-time code reaches somebody who has no personnel record.
 *
 * Identity in this system lives in `hr_people`, not on the user — there is no `users.name`. Plenty
 * of accounts have no such row: clients, portal users, anyone created outside the employee flow.
 *
 * For every one of them, requesting an OTP read `first_name` off a null person and threw. The
 * catch around it answered "Failed to send OTP. Please try again later.", which reads as a mail
 * problem and could never come right however many times they tried. With no password reset in the
 * product either, that was the last way in.
 *
 * The mail was also queued rather than sent, on an installation whose oldest waiting job was two
 * months old. Nothing failed there either; the code simply never left.
 */
class OtpLoginWorksWithoutAProfileTest extends TestCase
{
    use DatabaseTransactions;

    private function userWithoutAProfile(): User
    {
        $companyId = DB::table('companies')->min('id');

        $user = User::withoutGlobalScopes()->create([
            'uuid'       => (string) Str::uuid(),
            'email'      => 'no-profile-'.Str::lower(Str::random(8)).'@mkhaya.test',
            'password'   => Hash::make(Str::random(16)),
            'company_id' => $companyId,
            'status'     => 1,
        ]);

        DB::table('hr_people')->where('user_id', $user->id)->delete();

        $this->assertNull($user->fresh()->person, 'The fixture user still has a personnel record.');

        return $user;
    }

    public function test_an_otp_is_sent_to_a_user_with_no_personnel_record(): void
    {
        Mail::fake();

        $user = $this->userWithoutAProfile();

        $this->post(route('send.otp'), ['email' => $user->email]);

        Mail::assertSent(
            SendOtpsMail::class,
            fn ($mail) => $mail->hasTo($user->email),
        );
    }

    /**
     * Sent, not queued.
     *
     * A code that waits for a worker is not a one-time code. On an install with nothing consuming
     * the queue it never arrives, and nothing anywhere reports a failure.
     */
    public function test_the_code_is_not_left_sitting_in_a_queue(): void
    {
        Mail::fake();

        $user = $this->userWithoutAProfile();

        $this->post(route('send.otp'), ['email' => $user->email]);

        Mail::assertNotQueued(SendOtpsMail::class);
        Mail::assertSent(SendOtpsMail::class);
    }

    /** And the response must not claim failure when nothing failed. */
    public function test_the_user_is_not_told_it_failed(): void
    {
        Mail::fake();

        $user = $this->userWithoutAProfile();

        $this->post(route('send.otp'), ['email' => $user->email]);

        $result = session('otpResult');

        $this->assertNotNull($result, 'No outcome was reported at all.');
        $this->assertTrue($result['success'] ?? false, 'Sending an OTP reported failure: '.($result['message'] ?? ''));
    }
}
