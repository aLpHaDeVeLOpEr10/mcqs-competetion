<?php

namespace App\Http\Controllers\Account;

use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Services\Account\PlayerDashboard;
use App\Services\Results\AnswerReview;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The player's own competitions. Seats are always looked up by
 * (room, signed-in user), so another player's history is unreachable.
 */
class CompetitionHistoryController extends Controller
{
    public function __construct(protected PlayerDashboard $dashboard) {}

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), PlayerDashboard::TABS, true) ? $request->query('tab') : 'upcoming';

        return view('account.competitions', [
            'tab' => $tab,
            'counts' => $this->dashboard->tabCounts($request->user()),
            'participations' => $this->dashboard->competitions($request->user(), $tab)->paginate(12)->withQueryString(),
        ]);
    }

    public function history(Request $request): View
    {
        return view('account.history', [
            'participations' => $this->dashboard->history($request->user())->paginate(20),
        ]);
    }

    public function show(Request $request, Room $room, AnswerReview $reviews): View
    {
        $seat = RoomParticipant::query()
            ->where('room_id', $room->id)
            ->where('user_id', $request->user()->id)
            ->with(['entryLedgerTransaction:id,reference', 'refundLedgerTransaction:id,reference', 'result',
                'prizePayout.ledgerTransaction:id,reference'])
            ->first();
        abort_if($seat === null, 404);

        $session = $room->session;
        $published = $session?->results_published_at !== null;
        // Answers are reviewed once the live part is over; correctness only after publication.
        $ended = $session !== null && in_array($room->status, [RoomStatus::InProgress, RoomStatus::Completed], true) && $session->ended_at !== null;

        return view('account.competition', [
            'room' => $room,
            'seat' => $seat,
            'session' => $session,
            'published' => $published,
            'result' => $published ? $seat->result : null,
            'payout' => $published ? $seat->prizePayout : null,
            'review' => $ended || $published ? $reviews->forSeat($seat, $session, reveal: $published) : null,
        ]);
    }
}
