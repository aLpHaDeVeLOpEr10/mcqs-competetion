<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    /**
     * Players may view only their own account.
     */
    public function view(User $actor, User $target): bool
    {
        return $actor->is($target);
    }

    /**
     * Players may edit only their own profile fields. Role and status are
     * not editable through any player-facing request.
     */
    public function update(User $actor, User $target): bool
    {
        return $actor->is($target) && $actor->status->canSignIn();
    }

    /**
     * Change a user's account status. Never your own, and only for accounts
     * ranked strictly below yours (an admin cannot suspend another admin).
     */
    public function changeStatus(User $actor, User $target): bool
    {
        return $actor->hasPermission(Permission::UsersManageStatus)
            && ! $actor->is($target)
            && $actor->role->rank() > $target->role->rank();
    }

    /**
     * Assign roles. Never your own; the last super admin is protected by
     * UserAdministrationService.
     */
    public function changeRole(User $actor, User $target): bool
    {
        return $actor->hasPermission(Permission::UsersManageRoles)
            && ! $actor->is($target);
    }
}
