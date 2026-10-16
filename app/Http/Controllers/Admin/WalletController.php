<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Exceptions\WalletOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WalletAdjustmentRequest;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\Wallet\WalletReconciler;
use App\Services\Wallet\WalletService;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Wallet accounts for finance staff. There is no "edit balance": the only
 * write is an audited adjustment posted through WalletService.
 */
class WalletController extends Controller
{
    public function index(Request $request): View
    {
        $filters = QueryFilters::from($request, [
            'q' => ['string', 'max:191'],
            'type' => [Rule::enum(WalletType::class)],
            'status' => [Rule::enum(WalletStatus::class)],
        ]);

        $query = Wallet::query()
            ->with('user:id,display_name,email')
            ->withCount('entries')
            // Ledger total next to the cached balance, so a mismatch is visible at a glance.
            ->addSelect(['ledger_sum_minor' => LedgerEntry::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('wallet_id', 'wallets.id')])
            ->when($filters->get('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($filters->get('status'), fn ($q, $s) => $q->where('status', $s));

        if ($filters->has('q')) {
            $term = '%'.addcslashes((string) $filters->get('q'), '\\%_').'%';
            $query->where(fn ($q) => $q->where('system_code', 'like', $term)
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $term)->orWhere('display_name', 'like', $term)));
        }

        return view('admin.wallets.index', [
            'wallets' => $query->orderBy('type')->orderBy('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function show(Wallet $wallet, WalletReconciler $reconciler): View
    {
        Gate::authorize('view', $wallet);

        return view('admin.wallets.show', [
            'wallet' => $wallet->load('user:id,display_name,email,status'),
            'entries' => $wallet->entries()
                ->with(['transaction' => fn ($q) => $q->select('id', 'reference', 'type', 'description', 'created_by', 'created_at')->withExists('reversal')])
                ->orderByDesc('id')->paginate(25),
            'issues' => $reconciler->check($wallet->id),
            'adjustmentsEnabled' => (bool) config('competition.wallet.adjustments_enabled'),
            'token' => (string) Str::uuid(),
        ]);
    }

    public function adjust(WalletAdjustmentRequest $request, Wallet $wallet, WalletService $wallets): RedirectResponse
    {
        try {
            $transaction = $wallets->adjust($request->user(), $wallet, $request->signedAmountMinor(), $request->validated('reason'), $request->validated('token'));
        } catch (WalletOperationException $e) {
            return back()->withInput($request->except('token'))->with('error', $e->getMessage());
        }

        return redirect()->route('admin.wallets.show', $wallet)->with('success', $transaction->wasRecentlyCreated
            ? "Adjustment posted as ledger transaction {$transaction->reference}."
            : "This adjustment was already posted ({$transaction->reference}); nothing was changed.");
    }
}
