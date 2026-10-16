<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PayoutStatus;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\WalletOperationException;
use App\Http\Controllers\Controller;
use App\Models\CompetitionSession;
use App\Models\PrizeDistribution;
use App\Models\PrizePayout;
use App\Services\Prizes\PrizeDistributionService;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Prize payout management. Viewing needs finance.view; every action needs
 * prizes.manage, a written reason and a confirmation, and is audited. There
 * is no way to edit an amount or mark a prize paid by hand.
 */
class PayoutController extends Controller
{
    public function index(Request $request): View
    {
        $filters = QueryFilters::from($request, [
            'q' => ['string', 'max:191'],
            'status' => [Rule::enum(PayoutStatus::class)],
        ]);

        $query = PrizePayout::query()
            ->with(['participant.user:id,display_name,email', 'participant.room:id,name,currency', 'distribution:id,competition_session_id', 'ledgerTransaction:id,reference'])
            ->when($filters->get('status'), fn ($q, $s) => $q->where('status', $s));

        if ($filters->has('q')) {
            $term = '%'.addcslashes((string) $filters->get('q'), '\\%_').'%';
            $query->where(fn ($q) => $q
                ->whereHas('participant.user', fn ($u) => $u->where('email', 'like', $term)->orWhere('display_name', 'like', $term))
                ->orWhereHas('participant.room', fn ($r) => $r->where('name', 'like', $term))
                ->orWhereHas('ledgerTransaction', fn ($t) => $t->where('reference', 'like', $term)));
        }

        return view('admin.payouts.index', [
            'payouts' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function show(CompetitionSession $session, PrizeDistributionService $prizes): View
    {
        $distribution = PrizeDistribution::query()->where('competition_session_id', $session->id)
            ->with(['payouts' => fn ($q) => $q->orderBy('rank')->orderBy('id'), 'payouts.participant.user:id,display_name,email',
                'payouts.ledgerTransaction:id,reference', 'payouts.reversalLedgerTransaction:id,reference', 'payouts.approver:id,name'])
            ->firstOrFail();
        $session->load('room');

        return view('admin.payouts.show', [
            'session' => $session,
            'room' => $session->room,
            'distribution' => $distribution,
            'blocker' => $prizes->blocker($session->room, $session, $distribution),
        ]);
    }

    public function approve(Request $request, CompetitionSession $session, PrizeDistributionService $prizes): RedirectResponse
    {
        $data = $request->validateWithBag('approve', ['reason' => ['required', 'string', 'min:5', 'max:255'], 'confirm' => ['accepted']]);
        $distribution = PrizeDistribution::query()->where('competition_session_id', $session->id)->firstOrFail();

        return $this->run(fn () => $prizes->approve($request->user(), $distribution, $data['reason']), 'Prizes approved.');
    }

    public function distribute(Request $request, CompetitionSession $session, PrizeDistributionService $prizes): RedirectResponse
    {
        return $this->run(fn () => $prizes->distribute($session->id, $request->user()), 'Distribution run.');
    }

    public function hold(Request $request, PrizePayout $payout, PrizeDistributionService $prizes): RedirectResponse
    {
        $data = $this->reason($request, 'payout-'.$payout->id);

        return $this->run(fn () => $prizes->hold($request->user(), $payout, $data['reason']), 'The payout is on hold.');
    }

    public function release(Request $request, PrizePayout $payout, PrizeDistributionService $prizes): RedirectResponse
    {
        $data = $this->reason($request, 'payout-'.$payout->id);

        return $this->run(fn () => $prizes->release($request->user(), $payout, $data['reason']), 'The hold was released.');
    }

    public function retry(Request $request, PrizePayout $payout, PrizeDistributionService $prizes): RedirectResponse
    {
        $data = $this->reason($request, 'payout-'.$payout->id);

        return $this->run(fn () => $prizes->retry($request->user(), $payout, $data['reason']), 'The payout was retried.');
    }

    public function reverse(Request $request, PrizePayout $payout, PrizeDistributionService $prizes): RedirectResponse
    {
        $data = $this->reason($request, 'payout-'.$payout->id);

        return $this->run(fn () => $prizes->reverse($request->user(), $payout, $data['reason']), 'The prize was reversed and the amount taken back from the wallet.');
    }

    /**
     * @return array{reason: string}
     */
    protected function reason(Request $request, string $bag): array
    {
        return $request->validateWithBag($bag, ['reason' => ['required', 'string', 'min:5', 'max:255'], 'confirm' => ['accepted']]);
    }

    protected function run(callable $action, string $done): RedirectResponse
    {
        try {
            $result = $action();
        } catch (AdministrativeActionException|WalletOperationException $e) {
            return back()->with('error', $e->getMessage());
        }

        if (is_array($result) && $result['status'] === 'held') {
            return back()->with('error', 'Prizes are on hold: '.$result['reason']);
        }

        $summary = is_array($result) ? " {$result['paid']} paid, {$result['failed']} failed." : '';

        return back()->with('success', $done.$summary);
    }
}
