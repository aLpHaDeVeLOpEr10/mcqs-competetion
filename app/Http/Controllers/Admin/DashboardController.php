<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardMetrics;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetrics $metrics): View
    {
        $user = $request->user();

        return view('admin.dashboard', [
            'users' => $metrics->users(),
            'rooms' => $metrics->rooms(),
            'participation' => $metrics->participation(),
            // Sensitive sections are computed only for staff allowed to see them.
            'finance' => $user->hasPermission(Permission::FinanceView) ? $metrics->finance() : null,
            'recentActions' => $user->hasPermission(Permission::AuditView) ? $metrics->recentActions() : null,
            'alerts' => $metrics->alerts(),
            'figuresAsOf' => $metrics->figuresAsOf(),
        ]);
    }
}
