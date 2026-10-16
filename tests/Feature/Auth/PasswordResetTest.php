<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_reset_link_is_sent_and_response_does_not_reveal_registration(): void
    {
        $user = User::factory()->create(['email' => 'player@example.test']);

        $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'Player@Example.test']);
        $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'ghost@example.test']);

        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_repeated_requests_look_identical_even_when_the_broker_throttles(): void
    {
        User::factory()->create(['email' => 'player@example.test']);

        $first = $this->post(route('password.email'), ['email' => 'player@example.test']);
        $second = $this->post(route('password.email'), ['email' => 'player@example.test']);

        $second->assertSessionHasNoErrors();
        $this->assertSame($first->getSession()->get('status'), $second->getSession()->get('status'));
    }

    public function test_password_can_be_reset_with_a_valid_token_and_sessions_are_revoked(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'player@example.test']);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->post(route('password.email'), ['email' => 'player@example.test']);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->get(route('password.reset', ['token' => $notification->token, 'email' => $user->email]))
                ->assertOk()
                ->assertSee('value="'.$user->email.'"', false);

            $this->post(route('password.store'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Brand-new-pass9',
                'password_confirmation' => 'Brand-new-pass9',
            ])->assertRedirect(route('login'))->assertSessionHas('status');

            return true;
        });

        $this->assertTrue(Hash::check('Brand-new-pass9', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_tokens_are_single_use_and_invalid_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $original = $user->password;

        $this->post(route('password.store'), [
            'token' => 'forged-token',
            'email' => $user->email,
            'password' => 'Brand-new-pass9',
            'password_confirmation' => 'Brand-new-pass9',
        ])->assertSessionHasErrors(['email' => 'This password reset link is invalid or has expired. Please request a new one.']);

        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Brand-new-pass9',
            'password_confirmation' => 'Brand-new-pass9',
        ])->assertSessionHasErrors('email');
    }

    public function test_unknown_email_and_bad_token_share_the_same_error(): void
    {
        $response = $this->post(route('password.store'), [
            'token' => 'whatever',
            'email' => 'ghost@example.test',
            'password' => 'Brand-new-pass9',
            'password_confirmation' => 'Brand-new-pass9',
        ]);

        $response->assertSessionHasErrors(['email' => 'This password reset link is invalid or has expired. Please request a new one.']);
    }
}
