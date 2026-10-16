<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_super_admin_with_a_hashed_password_and_an_audit_record(): void
    {
        $this->artisan('users:create-super-admin')
            ->expectsQuestion('Full name', 'Site Owner')
            ->expectsQuestion('Public display name', 'siteowner')
            ->expectsQuestion('Email address', 'Owner@Example.com')
            ->expectsQuestion('Password (not shown)', 'Correct-Horse-9-Battery')
            ->expectsQuestion('Repeat the password', 'Correct-Horse-9-Battery')
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame([UserRole::SuperAdmin, 'owner@example.com'], [$user->role, $user->email]);
        $this->assertTrue(Hash::check('Correct-Horse-9-Battery', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('user.super_admin_created', AuditLog::query()->sole()->action);
    }

    public function test_it_refuses_weak_passwords_and_once_a_super_admin_exists(): void
    {
        $this->artisan('users:create-super-admin')
            ->expectsQuestion('Full name', 'Site Owner')
            ->expectsQuestion('Public display name', 'siteowner')
            ->expectsQuestion('Email address', 'owner@example.com')
            ->expectsQuestion('Password (not shown)', 'short')
            ->expectsQuestion('Repeat the password', 'short')
            ->assertFailed();
        $this->assertSame(0, User::query()->count());

        User::factory()->role(UserRole::SuperAdmin)->create();
        $this->artisan('users:create-super-admin')->expectsOutputToContain('already exists')->assertFailed();
        $this->assertSame(1, User::query()->count());
    }
}
