<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\AdministrativeActionException;
use App\Models\User;
use App\Notifications\AccountStatusChanged;
use App\Services\Account\SessionService;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Status and role changes made by staff. Authorization is checked by the
 * caller (UserPolicy); this class enforces state rules, locks the row and
 * writes the audit record in the same transaction.
 */
class UserAdministrationService
{
    public function __construct(
        protected AuditLogger $audit,
        protected SessionService $sessions,
    ) {}

    public function changeStatus(User $actor, User $target, UserStatus $status, string $reason): User
    {
        $user = DB::transaction(function () use ($actor, $target, $status, $reason) {
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $previous = $user->status;

            if ($previous === $status) {
                throw new AdministrativeActionException("This account is already {$status->value}.");
            }

            $user->forceFill([
                'status' => $status,
                'status_reason' => $status === UserStatus::Active ? null : $reason,
            ])->save();

            $this->audit->record('user.status_changed', $user,
                ['status' => $previous->value],
                ['status' => $status->value],
                ['reason' => $reason],
                $actor,
            );

            return $user;
        });

        // Security notice; always delivered (it cannot be switched off).
        app(Notifier::class)->send($user, new AccountStatusChanged($status));

        // Sign the user out everywhere when they may no longer hold a session.
        if (! $status->canSignIn()) {
            $this->sessions->revokeAll($user);
        }

        return $user;
    }

    public function changeRole(User $actor, User $target, UserRole $role, string $reason): User
    {
        return DB::transaction(function () use ($actor, $target, $role, $reason) {
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $previous = $user->role;

            if ($previous === $role) {
                throw new AdministrativeActionException("This account already has the {$role->label()} role.");
            }

            if ($previous === UserRole::SuperAdmin) {
                // Lock every super admin row so two concurrent demotions cannot both pass.
                $superAdmins = User::query()->where('role', UserRole::SuperAdmin)->lockForUpdate()->count();

                if ($superAdmins <= 1) {
                    throw new AdministrativeActionException('The last super admin cannot be demoted.');
                }
            }

            $user->forceFill(['role' => $role])->save();

            $this->audit->record('user.role_changed', $user,
                ['role' => $previous->value],
                ['role' => $role->value],
                ['reason' => $reason],
                $actor,
            );

            return $user;
        });
    }
}
