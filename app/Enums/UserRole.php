<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum UserRole: string
{
    use HasValues;

    case Player = 'player';
    case ContentManager = 'content_manager';
    case CompetitionManager = 'competition_manager';
    case FinanceManager = 'finance_manager';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    public function isStaff(): bool
    {
        return $this !== self::Player;
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
            default => ucfirst(str_replace('_', ' ', $this->value)),
        };
    }

    /**
     * Seniority used for "may act on" checks: an actor may only manage
     * accounts ranked strictly below their own.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Player => 0,
            self::ContentManager, self::CompetitionManager, self::FinanceManager => 1,
            self::Admin => 2,
            self::SuperAdmin => 3,
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => Permission::cases(),
            // Admins see finance but never move money (adjustments, prizes, withdrawals): finance-manager duties.
            self::Admin => array_values(array_filter(Permission::cases(), fn (Permission $p) => ! in_array($p, [Permission::UsersManageRoles, Permission::FinanceAdjust, Permission::PrizesManage, Permission::WithdrawalsReview, Permission::WithdrawalsApprove], true))),
            self::ContentManager => [
                Permission::AdminAccess,
                Permission::ContentView,
                Permission::ContentManage,
            ],
            self::CompetitionManager => [
                Permission::AdminAccess,
                Permission::ContentView,
                Permission::RoomsView,
                Permission::RoomsManage,
                Permission::ResultsView,
            ],
            self::FinanceManager => [
                Permission::AdminAccess,
                Permission::ResultsView,
                Permission::FinanceView,
                Permission::FinanceAdjust,
                Permission::PrizesManage,
                Permission::WithdrawalsReview,
                Permission::WithdrawalsApprove,
                Permission::ReportsView,
                Permission::ReportsExport,
                Permission::RiskReview,
            ],
            self::Player => [],
        };
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
