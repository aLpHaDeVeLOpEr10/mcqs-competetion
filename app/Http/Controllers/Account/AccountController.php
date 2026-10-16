<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Account\PlayerDashboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The player dashboard. Everything shown is derived on the server from the
 * signed-in user's own records (see PlayerDashboard).
 */
class AccountController extends Controller
{
    public function show(Request $request, PlayerDashboard $dashboard): View
    {
        return view('account.overview', ['user' => $request->user()] + $dashboard->overview($request->user()));
    }
}
