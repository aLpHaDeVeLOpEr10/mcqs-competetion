<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\AdministrativeActionException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\PaymentCallback;
use App\Models\PaymentTransaction;
use App\Models\Withdrawal;
use App\Services\Withdrawals\PayoutGatewayManager;
use App\Services\Withdrawals\WithdrawalService;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Finance review of withdrawals. Viewing needs withdrawals.review; decisions
 * and payouts need withdrawals.approve. Every action is audited, and staff can
 * never act on their own withdrawal.
 */
class WithdrawalController extends Controller
{
    public function index(Request $request): View
    {
        $filters = QueryFilters::from($request, [
            'q' => ['string', 'max:191'],
            'status' => [Rule::enum(WithdrawalStatus::class)],
        ]);

        $query = Withdrawal::query()->with('user:id,display_name,email')
            ->when($filters->get('status'), fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->orderByRaw("FIELD(status, 'requested', 'under_review', 'approved', 'processing') DESC"));
        if ($filters->has('q')) {
            $term = '%'.addcslashes((string) $filters->get('q'), '\\%_').'%';
            $query->where(fn ($q) => $q->where('reference', 'like', $term)->orWhere('provider_reference', 'like', $term)
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $term)->orWhere('display_name', 'like', $term)));
        }

        return view('admin.withdrawals.index', [
            'withdrawals' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'pendingCount' => Withdrawal::query()->whereIn('status', [WithdrawalStatus::Requested, WithdrawalStatus::UnderReview])->count(),
        ]);
    }

    public function show(Withdrawal $withdrawal, PayoutGatewayManager $gateways): View
    {
        $user = $withdrawal->user;

        return view('admin.withdrawals.show', [
            'withdrawal' => $withdrawal->load(['holdTransaction:id,reference', 'releaseTransaction:id,reference', 'reviewer:id,name', 'approver:id,name']),
            'user' => $user,
            'wallet' => $user->wallet,
            // Context for the decision: the requester's recent money movements and history.
            'entries' => $user->wallet ? LedgerEntry::query()->where('wallet_id', $user->wallet->id)->with('transaction:id,reference,type,description,created_at')->latest('id')->limit(15)->get() : collect(),
            'deposits' => PaymentTransaction::query()->where('user_id', $user->id)->where('type', PaymentType::Deposit)->latest('id')->limit(10)->get(),
            'others' => Withdrawal::query()->where('user_id', $user->id)->whereKeyNot($withdrawal->id)->latest('id')->limit(10)->get(),
            'history' => AuditLog::query()->where('auditable_type', $withdrawal->getMorphClass())->where('auditable_id', $withdrawal->id)->with('actor:id,name')->oldest('id')->get(),
            'callbacks' => PaymentCallback::query()->where('withdrawal_id', $withdrawal->id)->latest('id')->limit(20)->get(),
            'payoutsAvailable' => $gateways->payoutsAvailable(),
        ]);
    }

    public function startReview(Request $request, Withdrawal $withdrawal, WithdrawalService $withdrawals): RedirectResponse
    {
        return $this->run(fn () => $withdrawals->startReview($request->user(), $withdrawal), 'Review started.');
    }

    public function approve(Request $request, Withdrawal $withdrawal, WithdrawalService $withdrawals): RedirectResponse
    {
        $data = $request->validateWithBag('approve', ['reason' => ['required', 'string', 'min:5', 'max:255'], 'confirm' => ['accepted']]);

        return $this->run(fn () => $withdrawals->approve($request->user(), $withdrawal, $data['reason']), 'Withdrawal approved.');
    }

    public function reject(Request $request, Withdrawal $withdrawal, WithdrawalService $withdrawals): RedirectResponse
    {
        $data = $request->validateWithBag('reject', ['reason' => ['required', 'string', 'min:5', 'max:255'], 'confirm' => ['accepted']]);

        return $this->run(fn () => $withdrawals->reject($request->user(), $withdrawal, $data['reason']), 'Withdrawal rejected; the funds were returned to the player.');
    }

    public function initiate(Request $request, Withdrawal $withdrawal, WithdrawalService $withdrawals): RedirectResponse
    {
        $request->validateWithBag('payout', ['confirm' => ['accepted']]);

        return $this->run(fn () => $withdrawals->initiatePayout($request->user(), $withdrawal), 'Payout sent to the provider.');
    }

    public function refresh(Withdrawal $withdrawal, WithdrawalService $withdrawals): RedirectResponse
    {
        $after = $withdrawals->refresh($withdrawal);

        return back()->with('success', 'Checked with the provider: '.$after->status->label().'.');
    }

    protected function run(callable $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (AdministrativeActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $done);
    }
}
