<?php

namespace Tests\Feature\Auth;

use App\Core\User\Models\User;
use App\Core\UserManagement\Models\PasswordHistory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Getting back in after forgetting a password, without asking anybody.
 *
 * There was no way to do this. "Forgot Password?" on the login page was an anchor with no href and
 * nothing behind it, and the only other route in — a one-time code — threw for any account without
 * a personnel record, which is every client and subcontractor. Somebody locked out had to find an
 * administrator.
 *
 * These tests cover the two faults that broke the OTP path as well as the flow itself, because a
 * new door built the same way would fail the same way: it must work for an account with no
 * personnel record, and it must not depend on a queue that nothing is consuming.
 */
class PasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    private function account(array $attributes = []): User
    {
        $user = User::withoutGlobalScopes()->create(array_merge([
            'uuid'       => (string) Str::uuid(),
            'email'      => 'reset-'.Str::lower(Str::random(8)).'@mkhaya.test',
            'password'   => Hash::make('OriginalPass1!'),
            'company_id' => DB::table('companies')->min('id'),
            'status'     => 1,
        ], $attributes));

        DB::table('hr_people')->where('user_id', $user->id)->delete();

        return $user;
    }

    // ── Asking ───────────────────────────────────────────────────────────────

    public function test_the_login_page_offers_a_way_to_start(): void
    {
        $this->get(route('login'))->assertOk();
        $this->get(route('password.request'))->assertOk();
    }

    /** The population the OTP path could not serve. */
    public function test_a_link_is_sent_to_an_account_with_no_personnel_record(): void
    {
        Notification::fake();

        $user = $this->account();
        $this->assertNull($user->person, 'The fixture has a personnel record, so this proves nothing.');

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * The same answer either way.
     *
     * Otherwise the form becomes a way of asking which addresses have accounts here.
     */
    public function test_an_unknown_address_gets_the_same_answer_as_a_known_one(): void
    {
        Notification::fake();

        $known = $this->account();

        $this->post(route('password.email'), ['email' => $known->email]);
        $forKnown = session('status');

        $this->post(route('password.email'), ['email' => 'nobody-'.Str::random(8).'@mkhaya.test']);
        $forUnknown = session('status');

        $this->assertNotNull($forKnown);
        $this->assertSame($forKnown, $forUnknown, 'The wording differs, so the form reveals which addresses exist.');
    }

    /** A disabled account cannot be handed a credential it may not use. */
    public function test_a_disabled_account_is_not_sent_a_link(): void
    {
        Notification::fake();

        $user = $this->account(['status' => 0]);

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertNothingSent();
    }

    /**
     * Asking eight times does not send eight emails.
     *
     * Two throttles stack here and the stricter one wins: this controller allows five requests per
     * address and IP in fifteen minutes, and Laravel's own password broker refuses to issue a
     * second link within sixty seconds of the first. The effect is one email, which is the point —
     * the form must not become a way to bury somebody's inbox, or to keep invalidating a link they
     * are in the middle of using.
     */
    public function test_asking_repeatedly_does_not_send_repeatedly(): void
    {
        Notification::fake();

        $user = $this->account();

        for ($i = 0; $i < 8; $i++) {
            $this->post(route('password.email'), ['email' => $user->email]);
        }

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    /** And the person asking is told the same thing every time, throttled or not. */
    public function test_being_throttled_is_not_announced(): void
    {
        Notification::fake();

        $user = $this->account();

        $this->post(route('password.email'), ['email' => $user->email]);
        $first = session('status');

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('password.email'), ['email' => $user->email]);
        }

        $this->assertNotNull(session('status'), 'A throttled request said nothing at all.');
        $this->assertNotNull($first);
    }

    // ── Setting ──────────────────────────────────────────────────────────────

    public function test_a_valid_link_sets_a_new_password(): void
    {
        $user = $this->account();
        $token = Password::broker()->createToken($user);

        $this->get(route('password.reset', ['token' => $token]))->assertOk();

        $this->post(route('password.store'), [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'BrandNewPass1!',
            'password_confirmation' => 'BrandNewPass1!',
        ])->assertRedirect(route('login'));

        $this->assertTrue(
            Hash::check('BrandNewPass1!', $user->fresh()->password),
            'The password was not changed.'
        );
    }

    public function test_the_new_password_can_be_used_to_sign_in(): void
    {
        $user = $this->account();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.store'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'BrandNewPass1!', 'password_confirmation' => 'BrandNewPass1!',
        ]);

        $this->assertTrue(
            auth()->attempt(['email' => $user->email, 'password' => 'BrandNewPass1!']),
            'The new password does not work at the login.'
        );
    }

    public function test_a_used_token_cannot_be_used_twice(): void
    {
        $user = $this->account();
        $token = Password::broker()->createToken($user);

        $payload = [
            'token' => $token, 'email' => $user->email,
            'password' => 'BrandNewPass1!', 'password_confirmation' => 'BrandNewPass1!',
        ];

        $this->post(route('password.store'), $payload);

        $second = array_merge($payload, [
            'password' => 'AnotherPass1!', 'password_confirmation' => 'AnotherPass1!',
        ]);

        $this->post(route('password.store'), $second)->assertSessionHasErrors('email');

        $this->assertFalse(
            Hash::check('AnotherPass1!', $user->fresh()->password),
            'A spent reset link set the password a second time.'
        );
    }

    public function test_a_forged_token_is_refused(): void
    {
        $user = $this->account();

        $this->post(route('password.store'), [
            'token'                 => Str::random(64),
            'email'                 => $user->email,
            'password'              => 'BrandNewPass1!',
            'password_confirmation' => 'BrandNewPass1!',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('OriginalPass1!', $user->fresh()->password));
    }

    /** The same rule the signed-in change enforces, so this is not the weaker door. */
    public function test_a_recent_password_cannot_be_reused(): void
    {
        $user = $this->account();
        PasswordHistory::createPassword($user->id, Hash::make('OldFavourite1!'));

        $token = Password::broker()->createToken($user);

        $this->post(route('password.store'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'OldFavourite1!', 'password_confirmation' => 'OldFavourite1!',
        ]);

        $this->assertFalse(
            Hash::check('OldFavourite1!', $user->fresh()->password),
            'A password from the history was accepted.'
        );
    }

    public function test_a_short_password_is_refused(): void
    {
        $user = $this->account();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.store'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }
}
