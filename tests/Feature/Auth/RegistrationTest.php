<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletType;
use App\Models\User;
use App\Notifications\AccountAlreadyExists;
use App\Notifications\WelcomeToPlatform;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => '  Ayesha   Khan ',
            'display_name' => 'quizwhiz',
            'email' => '  Ayesha@Example.TEST ',
            'password' => 'Secret-pass1',
            'password_confirmation' => 'Secret-pass1',
            'terms' => '1',
        ];
    }

    public function test_registration_creates_a_normalized_player_with_hashed_password_and_zero_wallet(): void
    {
        $this->post(route('register'), $this->payload())
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $user = User::firstOrFail();
        $this->assertSame('Ayesha Khan', $user->name);
        $this->assertSame('ayesha@example.test', $user->email);
        $this->assertSame(UserRole::Player, $user->fresh()->role);
        $this->assertSame(UserStatus::Active, $user->fresh()->status);
        $this->assertNotSame('Secret-pass1', $user->password);
        $this->assertTrue(Hash::check('Secret-pass1', $user->password));
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNull($user->email_verified_at);

        $wallet = $user->wallet;
        $this->assertSame(WalletType::User, $wallet->type);
        $this->assertSame(0, $wallet->balance_minor);
        $this->assertDatabaseCount('ledger_entries', 0);

        $this->assertGuest();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_welcome_email_is_sent_instead_when_verification_is_disabled(): void
    {
        config(['competition.accounts.email_verification' => false]);

        $this->post(route('register'), $this->payload());

        $user = User::firstOrFail();
        Notification::assertSentTo($user, WelcomeToPlatform::class);
        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_privileged_fields_cannot_be_self_assigned(): void
    {
        $this->post(route('register'), $this->payload([
            'role' => 'super_admin',
            'status' => 'active',
            'email_verified_at' => now()->toDateTimeString(),
        ]));

        $user = User::firstOrFail();
        $this->assertSame(UserRole::Player, $user->role);
        $this->assertNull($user->email_verified_at);
    }

    public function test_existing_email_gets_the_same_response_and_no_second_account(): void
    {
        $existing = User::factory()->create(['email' => 'ayesha@example.test']);

        $new = $this->post(route('register'), $this->payload(['display_name' => 'someoneelse']));
        $fresh = $this->post(route('register'), $this->payload(['email' => 'fresh@example.test', 'display_name' => 'freshone']));

        // Identical outcome for a taken and an unused address.
        $new->assertRedirect(route('login'))->assertSessionHas('status');
        $fresh->assertRedirect(route('login'));
        $this->assertSame($new->getSession()->get('status'), $fresh->getSession()->get('status'));
        $new->assertSessionHasNoErrors();

        $this->assertSame(1, User::where('email', 'ayesha@example.test')->count());
        $this->assertSame($existing->password, $existing->fresh()->password);
        Notification::assertSentOnDemand(AccountAlreadyExists::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'ayesha@example.test');
    }

    public function test_validation_errors(): void
    {
        User::factory()->create(['display_name' => 'taken']);

        $this->from(route('register'))->post(route('register'), [
            'name' => 'x',
            'display_name' => 'TAKEN',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertRedirect(route('register'))
            ->assertSessionHasErrors(['name', 'display_name', 'email', 'password', 'terms']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_weak_passwords_are_rejected(): void
    {
        foreach (['alllowercase1', 'NoDigitsHere', 'Short1a'] as $weak) {
            $this->post(route('register'), $this->payload(['password' => $weak, 'password_confirmation' => $weak]))
                ->assertSessionHasErrors('password');
        }
    }

    public function test_display_name_format_and_reserved_names(): void
    {
        foreach (['ab', '-leading', 'has space', 'Admin', 'support_', 'mod'] as $i => $bad) {
            // A distinct client IP per attempt keeps the registration rate limit out of this test.
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->post(route('register'), $this->payload(['display_name' => $bad]))
                ->assertSessionHasErrors('display_name');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post(route('register'), $this->payload(['email' => "p{$i}@example.test", 'display_name' => "player{$i}x"]));
        }

        $this->post(route('register'), $this->payload(['email' => 'p6@example.test', 'display_name' => 'player6x']))
            ->assertStatus(429);
    }

    public function test_signed_in_users_cannot_open_registration(): void
    {
        $this->actingAs(User::factory()->create())->get(route('register'))->assertRedirect(route('account.show'));
    }
}
