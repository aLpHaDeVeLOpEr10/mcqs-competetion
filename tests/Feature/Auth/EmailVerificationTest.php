<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function verificationUrl(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => $hash ?? sha1($user->email),
        ]);
    }

    public function test_notice_is_shown_to_unverified_users(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Resend verification email');
    }

    public function test_email_can_be_verified_with_a_signed_link(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get($this->verificationUrl($user))->assertRedirect(route('account.show'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_tampered_or_unsigned_links_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get($this->verificationUrl($user, sha1('wrong@example.test')))->assertForbidden();
        $this->actingAs($user)->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_link_for_another_user_is_rejected(): void
    {
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $this->actingAs($other)->get($this->verificationUrl($owner))->assertForbidden();
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
    }

    public function test_verification_email_can_be_resent(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))->assertSessionHas('status');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_unverified_users_cannot_enter_competitions_while_verification_is_required(): void
    {
        $user = User::factory()->unverified()->create();

        $this->assertFalse(Gate::forUser($user)->allows('enter-competitions'));

        config(['competition.accounts.email_verification' => false]);
        $this->assertTrue(Gate::forUser($user)->allows('enter-competitions'));
    }

    public function test_nothing_is_sent_when_verification_is_disabled(): void
    {
        Notification::fake();
        config(['competition.accounts.email_verification' => false]);
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('verification.notice'))->assertRedirect(route('account.show'));
        $this->actingAs($user)->post(route('verification.send'))->assertRedirect(route('account.show'));

        Notification::assertNothingSent();
    }
}
