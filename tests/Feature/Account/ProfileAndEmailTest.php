<?php

namespace Tests\Feature\Account;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailChangeRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileAndEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_be_updated_with_normalized_input(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('account.profile.update'), ['name' => '  New   Name ', 'display_name' => 'newalias'])
            ->assertRedirect(route('account.profile'))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('newalias', $user->display_name);
    }

    public function test_profile_update_cannot_change_privileged_fields(): void
    {
        $user = User::factory()->create(['email' => 'me@example.test']);

        $this->actingAs($user)->patch(route('account.profile.update'), [
            'name' => 'Me',
            'display_name' => 'meme',
            'role' => 'super_admin',
            'status' => 'active',
            'email' => 'hijack@example.test',
            'email_verified_at' => null,
            'password' => 'Whatever-123',
        ]);

        $user->refresh();
        $this->assertSame(UserRole::Player, $user->role);
        $this->assertSame('me@example.test', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(password_verify('password', $user->password));
    }

    public function test_display_name_must_stay_unique_but_own_name_can_be_kept(): void
    {
        User::factory()->create(['display_name' => 'taken']);
        $user = User::factory()->create(['display_name' => 'mine']);

        $this->actingAs($user)->patch(route('account.profile.update'), ['name' => 'Me', 'display_name' => 'Taken'])
            ->assertSessionHasErrors('display_name');

        $this->actingAs($user)->patch(route('account.profile.update'), ['name' => 'Me', 'display_name' => 'mine'])
            ->assertSessionHasNoErrors();
    }

    public function test_email_change_requires_password_and_confirmation_from_new_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.test']);

        $this->actingAs($user)->post(route('account.email.update'), ['email' => 'new@example.test', 'current_password' => 'wrong'])
            ->assertSessionHasErrorsIn('email', 'current_password');

        $this->actingAs($user)->post(route('account.email.update'), ['email' => ' New@Example.test ', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertSame('old@example.test', $user->fresh()->email);
        $this->assertSame('new@example.test', $user->fresh()->pending_email);
        Notification::assertSentTo($user, EmailChangeRequested::class);

        $url = null;
        Notification::assertSentOnDemand(ConfirmEmailChange::class, function ($n, $channels, $notifiable) use (&$url) {
            $url = $n->url;

            return $notifiable->routes['mail'] === 'new@example.test';
        });

        $this->actingAs($user)->get($url)->assertRedirect(route('account.profile'))->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('new@example.test', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_requesting_a_taken_email_looks_identical_and_never_switches(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'taken@example.test']);
        $user = User::factory()->create(['email' => 'me@example.test']);

        $response = $this->actingAs($user)->post(route('account.email.update'), ['email' => 'taken@example.test', 'current_password' => 'password']);
        $response->assertSessionHasNoErrors()->assertSessionHas('success');

        $url = null;
        Notification::assertSentOnDemand(ConfirmEmailChange::class, function ($n) use (&$url) {
            $url = $n->url;

            return true;
        });

        $this->actingAs($user)->get($url)->assertSessionHas('error');
        $this->assertSame('me@example.test', $user->fresh()->email);
    }

    public function test_confirmation_links_are_signed_expiring_and_tied_to_the_pending_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'me@example.test']);
        $this->actingAs($user)->post(route('account.email.update'), ['email' => 'new@example.test', 'current_password' => 'password']);

        $url = null;
        Notification::assertSentOnDemand(ConfirmEmailChange::class, function ($n) use (&$url) {
            $url = $n->url;

            return true;
        });

        // Tampered signature
        $this->actingAs($user)->get($url.'x')->assertForbidden();

        // A link for an address that is no longer pending
        $this->actingAs($user)->delete(route('account.email.cancel'));
        $this->actingAs($user)->get($url)->assertSessionHas('error');
        $this->assertSame('me@example.test', $user->fresh()->email);

        // Expired
        $this->actingAs($user)->post(route('account.email.update'), ['email' => 'later@example.test', 'current_password' => 'password']);
        Notification::assertSentOnDemand(ConfirmEmailChange::class, function ($n, $c, $notifiable) use (&$url) {
            $url = $n->url;

            return $notifiable->routes['mail'] === 'later@example.test';
        });
        $this->travel(61)->minutes();
        $this->actingAs($user)->get($url)->assertForbidden();
    }

    public function test_another_user_cannot_use_my_confirmation_link(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $this->actingAs($owner)->post(route('account.email.update'), ['email' => 'new@example.test', 'current_password' => 'password']);

        $url = null;
        Notification::assertSentOnDemand(ConfirmEmailChange::class, function ($n) use (&$url) {
            $url = $n->url;

            return true;
        });

        $this->actingAs($attacker)->get($url)->assertSessionHas('error');
        $this->assertNotSame('new@example.test', $attacker->fresh()->email);
    }

    public function test_restricted_users_can_still_edit_their_profile(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Restricted]);

        $this->actingAs($user)->patch(route('account.profile.update'), ['name' => 'Still Me', 'display_name' => 'stillme'])
            ->assertSessionHasNoErrors();
    }
}
