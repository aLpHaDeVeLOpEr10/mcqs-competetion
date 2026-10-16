<?php

namespace App\Http\Controllers\Account;

use App\Enums\LedgerTransactionType;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\RoomParticipant;
use App\Models\Withdrawal;
use App\Services\Account\PlayerDashboard;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Read-only wallet views. Nothing here moves money: balances change only
 * through WalletService. The wallet always comes from the signed-in user,
 * never from the request.
 */
class WalletController extends Controller
{
    public function show(Request $request, PaymentGatewayManager $gateways, PlayerDashboard $dashboard): View
    {
        $wallet = $request->user()->wallet;

        return view('account.wallet', [
            'wallet' => $wallet,
            'breakdown' => $dashboard->walletBreakdown($request->user()),
            'withdrawals' => Withdrawal::query()->where('user_id', $request->user()->id)->latest('id')->limit(10)->get(),
            'withdrawalsAvailable' => (bool) config('competition.withdrawals.enabled') && $request->user()->can('transact'),
            'depositsAvailable' => $gateways->depositsAvailable() && $request->user()->can('transact'),
            'recent' => $wallet ? $this->entries($wallet->id)->limit(5)->get() : collect(),
        ]);
    }

    public function transactions(Request $request): View
    {
        $wallet = $request->user()->wallet;
        $filters = QueryFilters::from($request, ['type' => [Rule::enum(LedgerTransactionType::class)]]);

        return view('account.transactions', [
            'wallet' => $wallet,
            'filters' => $filters,
            'entries' => $wallet ? $this->entries($wallet->id)
                ->when($filters->get('type'), fn ($q, $type) => $q->whereHas('transaction', fn ($t) => $t->where('type', $type)))
                ->paginate(20)->withQueryString() : null,
        ]);
    }

    public function transaction(Request $request, LedgerTransaction $transaction): View
    {
        Gate::authorize('view', $transaction);

        // The player's own side of the transaction; staff use the admin ledger instead.
        $entry = $transaction->entries()->whereHas('wallet', fn ($q) => $q->where('user_id', $request->user()->id))->first();
        abort_if($entry === null, 404);

        $seat = $transaction->reference_type === (new RoomParticipant)->getMorphClass()
            ? RoomParticipant::query()->with('room:id,name,slug')->find($transaction->reference_id)
            : null;

        return view('account.transaction', [
            'transaction' => $transaction->loadExists('reversal')->load('reversedTransaction:id,reference'),
            'entry' => $entry,
            'room' => $seat?->room,
        ]);
    }

    /**
     * Ledger entries for one wallet, newest first.
     */
    protected function entries(int $walletId): Builder
    {
        return LedgerEntry::query()
            ->where('wallet_id', $walletId)
            ->with(['transaction' => fn ($q) => $q->select('id', 'reference', 'type', 'description', 'created_at')->withExists('reversal')])
            ->orderByDesc('id');
    }
}
