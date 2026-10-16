<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Administrative permissions. Each case is registered as a Gate ability with
 * the same name, so routes use e.g. `can:users.view`.
 */
enum Permission: string
{
    use HasValues;

    case AdminAccess = 'admin.access';

    case UsersView = 'users.view';
    case UsersManageStatus = 'users.manage_status';
    case UsersManageRoles = 'users.manage_roles';

    case ContentView = 'content.view';
    case ContentManage = 'content.manage';

    case RoomsView = 'rooms.view';
    case RoomsManage = 'rooms.manage';

    case ResultsView = 'results.view';
    case ResultsManage = 'results.manage';

    case FinanceView = 'finance.view';
    case FinanceAdjust = 'finance.adjust';
    case PrizesManage = 'prizes.manage';
    case WithdrawalsReview = 'withdrawals.review';
    case WithdrawalsApprove = 'withdrawals.approve';

    case AnnouncementsManage = 'announcements.manage';

    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    case RiskReview = 'risk.review';

    case AuditView = 'audit.view';
    case SettingsView = 'settings.view';

    public function label(): string
    {
        return match ($this) {
            self::AdminAccess => 'Open the admin dashboard',
            self::UsersView => 'View users and their contact details',
            self::UsersManageStatus => 'Restrict, suspend or reactivate users',
            self::UsersManageRoles => 'Assign administrative roles',
            self::ContentView => 'View categories, questions (with answer keys) and quiz papers',
            self::ContentManage => 'Create and edit question-bank content',
            self::RoomsView => 'View competition rooms',
            self::RoomsManage => 'Create and manage competition rooms',
            self::ResultsView => 'View competition results',
            self::ResultsManage => 'Finalize results, apply corrections and resolve disputes',
            self::FinanceView => 'View payments, refunds and the wallet ledger',
            self::FinanceAdjust => 'Post audited wallet adjustments (with a written reason)',
            self::WithdrawalsReview => 'View withdrawal requests and start reviewing them',
            self::WithdrawalsApprove => 'Approve, reject and pay out withdrawals',
            self::PrizesManage => 'Approve, hold, release, retry and reverse prize payouts',
            self::AnnouncementsManage => 'Send platform announcements to users',
            self::ReportsView => 'View analytics, competition and user reports',
            self::ReportsExport => 'Export reports as CSV',
            self::RiskReview => 'Review fraud and abuse signals (never proof; no automatic action)',
            self::AuditView => 'View the audit log',
            self::SettingsView => 'View platform settings',
        };
    }
}
