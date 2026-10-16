<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Notifications\PasswordChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Session listing and revocation need the database driver (phpunit.xml defaults to array).
        config(['session.driver' => 'database']);
    }

    protected function otherSession(User $user, string $id = 'other-device'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'wrong',
            'password' => 'Another-pass77',
            'password_confirmation' => 'Another-pass77',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_change_hashes_and_signs_out_other_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->otherSession($user);

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'password',
            'password' => 'Another-pass77',
            'password_confirmation' => 'Another-pass77',
        ])->assertRedirect(route('account.security'))->assertSessionHas('success');

        $this->assertTrue(Hash::check('Another-pass77', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, PasswordChanged::class);
    }

    public function test_new_password_must_differ_and_meet_policy(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrorsIn('password', 'password');
    }

    public function test_sessions_are_listed_and_other_sessions_can_be_revoked(): void
    {
        $user = User::factory()->create();
        $this->otherSession($user);
        $stranger = User::factory()->create();
        $this->otherSession($stranger, 'stranger-device');

        $this->actingAs($user)->get(route('account.security'))
            ->assertOk()
            ->assertSee('Chrome on Windows')
            ->assertSee('203.0.113.9');

        $this->actingAs($user)->delete(route('account.sessions.destroy'), ['password' => 'wrong'])
            ->assertSessionHasErrorsIn('sessions', 'password');
        $this->assertDatabaseHas('sessions', ['id' => 'other-device']);

        $this->actingAs($user)->delete(route('account.sessions.destroy'), ['password' => 'password'])
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'stranger-device']);
    }

    public function test_sessions_of_other_users_are_not_listed(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'x', 'user_id' => $stranger->id, 'ip_address' => '198.51.100.77', 'user_agent' => 'Firefox/1', 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->get(route('account.security'))->assertDontSee('198.51.100.77');
    }
}
