<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Rules\DisplayName;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the FIRST super admin on a fresh installation (production seeds no accounts).
 * Refuses once any super admin exists: later roles are granted in Admin → Users, which is
 * audited and protected by the role rules. The password is prompted without echo and
 * checked with the production password policy; it is never accepted as an argument.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'users:create-super-admin {--email= : Email address (prompted if omitted)}';

    protected $description = 'Create the first super admin account (only while none exists)';

    public function handle(AuditLogger $audit): int
    {
        if (User::query()->where('role', UserRole::SuperAdmin)->exists()) {
            $this->error('A super admin already exists. Grant roles in Admin → Users instead.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->ask('Full name'),
            'display_name' => $this->ask('Public display name'),
            'email' => mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Email address')))),
            'password' => $this->secret('Password (not shown)'),
            'password_confirmation' => $this->secret('Repeat the password'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'display_name' => ['required', 'string', new DisplayName, Rule::unique('users', 'display_name')],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($data, $audit) {
            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'display_name' => $data['display_name'],
                'email' => $data['email'],
                'password' => $data['password'],   // hashed by the model cast
                'role' => UserRole::SuperAdmin,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ])->save();
            $audit->record('user.super_admin_created', $user, null, ['role' => UserRole::SuperAdmin->value], ['via' => 'console']);

            return $user;
        });

        $this->info("Super admin created: {$user->email} (#{$user->id}). Sign in and review Admin → Settings.");

        return self::SUCCESS;
    }
}
