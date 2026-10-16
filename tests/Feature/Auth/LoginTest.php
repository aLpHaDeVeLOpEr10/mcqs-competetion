<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Sign in')->assertSee(route('password.request'), false);
    }

    public function test_users_can_sign_in_with_case_insensitive_email_and_session_is_regenerated(): void
    {
        $user = User::factory()->create(['email' => 'player@example.test']);

        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login'), ['email' => ' Player@Example.test ', 'password' => 'password'])
            ->assertRedirect(route('account.show'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
    }

    public function test_intended_url_is_respected(): void
    {
        User::factory()->create(['email' => 'player@example.test']);

        $this->get(route('account.security'))->assertRedirect(route('login'));

        $this->post(route('login'), ['email' => 'player@example.test', 'password' => 'password'])
            ->assertRedirect(route('account.security'));
    }

    public function test_wrong_password_and_unknown_email_get_the_same_error(): void
    {
        User::factory()->create(['email' => 'player@example.test']);

        $wrong = $this->post(route('login'), ['email' => 'player@example.test', 'password' => 'nope']);
        $unknown = $this->post(route('login'), ['email' => 'ghost@example.test', 'password' => 'nope']);

        $wrong->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $unknown->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        User::factory()->create(['email' => 'player@example.test']);

        foreach (range(1, 5) as $i) {
            $this->post(route('login'), ['email' => 'player@example.test', 'password' => 'wrong']);
        }

        $this->post(route('login'), ['email' => 'player@example.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('Too many sign-in attempts', session('errors')->first('email'));
    }

    public function test_suspended_and_closed_accounts_cannot_sign_in(): void
    {
        foreach ([UserStatus::Suspended, UserStatus::Closed] as $status) {
            $user = User::factory()->create(['status' => $status]);

            $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
                ->assertSessionHasErrors('email');

            $this->assertGuest();
        }
    }

    public function test_restricted_accounts_can_sign_in(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Restricted]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('account.show'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_soft_deleted_users_cannot_sign_in(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        session()->put('marker', 'secret');

        $this->post(route('logout'))->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull(session('marker'));
    }

    public function test_logout_requires_post(): void
    {
        $this->actingAs(User::factory()->create())->get('/logout')->assertStatus(405);
        $this->assertAuthenticated();
    }

    public function test_session_of_a_user_suspended_mid_session_is_ended(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('account.show'))->assertOk();

        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->get(route('account.show'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
