<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin dashboard has its own sign-in and session, separate from the
 * player's sign-in on the site.
 */
class AdminSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_admin_sign_in_page(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.login'))->assertOk()->assertSee('Admin sign in');
    }

    public function test_staff_sign_in_from_the_admin_page_opens_the_dashboard_only(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();

        $this->post(route('admin.login'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_players_cannot_sign_in_to_the_admin_dashboard(): void
    {
        $player = User::factory()->create();

        $this->from(route('admin.login'))
            ->post(route('admin.login'), ['email' => $player->email, 'password' => 'password'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_site_sign_in_does_not_open_the_admin_dashboard(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('account.show'));

        $this->assertAuthenticatedAs($admin, 'web');
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    public function test_signing_out_of_admin_keeps_the_site_session(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $this->be($admin, 'web');
        $this->be($admin, 'admin');

        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
        $this->assertAuthenticatedAs($admin, 'web');
    }

    public function test_admin_pages_use_their_own_session_cookie(): void
    {
        $base = config('session.cookie');

        $this->get(route('admin.login'))->assertCookie($base.'_admin');
        $this->get(route('home'))->assertCookie($base)->assertCookieMissing($base.'_admin');
    }
}
