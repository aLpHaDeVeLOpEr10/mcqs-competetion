<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\AdministrativeActionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\UserAdministrationService;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(protected UserAdministrationService $administration) {}

    public function index(Request $request): View
    {
        $filters = QueryFilters::from($request, [
            'q' => ['string', 'max:100'],
            'role' => [Rule::enum(UserRole::class)],
            'status' => [Rule::enum(UserStatus::class)],
        ]);

        $query = User::query()->select(['id', 'name', 'display_name', 'email', 'role', 'status', 'email_verified_at', 'created_at']);
        $filters->search($query, 'q', ['name', 'display_name', 'email']);
        $query->when($filters->get('role'), fn ($q, $role) => $q->where('role', $role))
            ->when($filters->get('status'), fn ($q, $status) => $q->where('status', $status));

        return view('admin.users.index', [
            'users' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function show(User $user): View
    {
        return view('admin.users.show', [
            'user' => $user->loadCount('roomParticipations'),
            'wallet' => $user->wallet,
            'history' => AuditLog::query()
                ->where('auditable_type', $user->getMorphClass())
                ->where('auditable_id', $user->id)
                ->with('actor:id,name,display_name,deleted_at')
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): RedirectResponse
    {
        try {
            $this->administration->changeStatus($request->user(), $user, UserStatus::from($request->validated('status')), $request->validated('reason'));
        } catch (AdministrativeActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.users.show', $user)->with('success', 'Account status updated.');
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user): RedirectResponse
    {
        try {
            $this->administration->changeRole($request->user(), $user, UserRole::from($request->validated('role')), $request->validated('reason'));
        } catch (AdministrativeActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.users.show', $user)->with('success', 'Role updated.');
    }
}
