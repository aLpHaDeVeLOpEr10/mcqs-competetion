<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Models\LedgerTransaction;
use App\Models\PaymentTransaction;
use App\Models\PrizePayout;
use App\Models\RoomParticipant;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Read-only payment and ledger records. There are deliberately no routes
 * that update or delete ledger rows; corrections are new reversal entries.
 */
class FinanceController extends Controller
{
    public function payments(Request $request): View
    {
        $filters = QueryFilters::from($request, [
            'q' => ['string', 'max:191'],
            'type' => [Rule::enum(PaymentType::class)],
            'status' => [Rule::enum(PaymentStatus::class)],
        ]);

        $query = PaymentTransaction::query()->with('user:id,display_name,email')->withCount('refunds');
        if ($filters->has('q')) {
            // Internal reference, provider reference, or the player's email / display name.
            $term = '%'.addcslashes((string) $filters->get('q'), '\%_').'%';
            $query->where(fn ($q) => $q->where('reference', 'like', $term)->orWhere('gateway_reference', 'like', $term)
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $term)->orWhere('display_name', 'like', $term)));
        }
        $query->when($filters->get('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($filters->get('status'), fn ($q, $s) => $q->where('status', $s));

        return view('admin.finance.payments', [
            'payments' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    /**
     * Read-only. There is deliberately no action to mark a payment successful: only a
     * verified provider outcome (callback or reconciliation) can settle a payment.
     */
    public function payment(PaymentTransaction $payment): View
    {
        return view('admin.finance.payment', [
            'payment' => $payment->load(['user:id,display_name,email', 'ledgerTransaction:id,reference']),
            'callbacks' => $payment->callbacks()->latest('id')->limit(50)->get(),
        ]);
    }

    public function ledger(Request $request): View
    {
        $filters = QueryFilters::from($request, [
            'q' => ['string', 'max:191'],
            'type' => [Rule::enum(LedgerTransactionType::class)],
        ]);

        // Entries of a transaction sum to zero, so show the credited side as its size.
        $query = LedgerTransaction::query()
            ->withCount('entries')
            ->withExists('reversal')
            ->withSum(['entries as credits_minor' => fn ($q) => $q->where('amount_minor', '>', 0)], 'amount_minor');
        $filters->search($query, 'q', ['reference', 'idempotency_key', 'description']);
        $query->when($filters->get('type'), fn ($q, $t) => $q->where('type', $t));

        return view('admin.finance.ledger', [
            'transactions' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function ledgerTransaction(LedgerTransaction $transaction): View
    {
        return view('admin.finance.ledger-transaction', [
            'transaction' => $transaction->load(['entries.wallet.user:id,display_name', 'creator:id,name', 'reversedTransaction:id,reference', 'reversal:id,reverses_transaction_id,reference']),
            'related' => $this->related($transaction),
        ]);
    }

    /**
     * What the transaction settled (a competition seat, payment or prize), as a label and staff link.
     *
     * @return array{label: string, url: ?string}|null
     */
    protected function related(LedgerTransaction $transaction): ?array
    {
        $reference = $transaction->reference_type ? $transaction->related()->first() : null;

        return match (true) {
            $reference instanceof RoomParticipant => [
                'label' => 'Competition seat #'.$reference->id.' in '.($reference->room?->name ?? 'room #'.$reference->room_id),
                'url' => route('admin.rooms.show', $reference->room_id),
            ],
            $reference instanceof PaymentTransaction => [
                'label' => ucfirst($reference->type->value).' payment #'.$reference->id.' ('.$reference->status->value.')',
                'url' => route('admin.payments.index', ['q' => $reference->idempotency_key]),
            ],
            $reference instanceof PrizePayout => [
                'label' => 'Prize payout #'.$reference->id.' (rank '.$reference->rank.')',
                'url' => route('admin.results.show', $reference->distribution->competition_session_id),
            ],
            $reference !== null => ['label' => class_basename($reference).' #'.$reference->getKey(), 'url' => null],
            default => null,
        };
    }
}
