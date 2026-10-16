<?php

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use App\Exceptions\AdministrativeActionException;
use App\Models\PrizePayout;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Services\Competition\CompetitionFilters;
use App\Services\Competition\JoinEligibilityService;
use App\Services\Competition\PublicRoomQuery;
use App\Services\Competition\RoomEntryService;
use App\Services\Results\AnswerReview;
use App\Services\Results\DisputeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompetitionController extends Controller
{
    public function __construct(
        protected PublicRoomQuery $rooms,
        protected JoinEligibilityService $eligibility,
    ) {}

    public function index(Request $request): View
    {
        $filters = CompetitionFilters::fromRequest($request);
        $page = $this->rooms->paginate($filters);

        return view('competitions.index', [
            'rooms' => $page,
            'filters' => $filters,
            'categoryOptions' => $this->rooms->filterCategories(),
            'statusOptions' => RoomStatus::publicCases(),
            'categories' => $this->rooms->categoryNames($page->items()),
            'eligibility' => $this->eligibility->forRooms(collect($page->items()), $request->user()),
        ]);
    }

    /**
     * Published results. Correct answers become visible here, and only here,
     * once the results are published.
     */
    public function results(Request $request, Room $room): View
    {
        $session = $room->session;
        abort_if($session === null || $session->results_published_at === null, 404);

        $seat = $request->user()
            ? RoomParticipant::query()->where('room_id', $room->id)->where('user_id', $request->user()->id)
                ->with(['entryLedgerTransaction:id,reference', 'refundLedgerTransaction:id,reference'])->first()
            : null;

        $review = $seat !== null ? app(AnswerReview::class)->forSeat($seat, $session, reveal: true) : null;

        return view('competitions.results', [
            'room' => $room,
            'session' => $session,
            'version' => $session->resultVersions()->latest('version')->first(),
            'ranking' => $session->results()->with('participant.user:id,display_name')
                ->orderByRaw('`rank` IS NULL')->orderBy('rank')->orderBy('id')->get(),
            'seat' => $seat,
            'myResult' => $seat ? $session->results()->where('room_participant_id', $seat->id)->first() : null,
            'myPayout' => $seat ? PrizePayout::query()->where('room_participant_id', $seat->id)->with('ledgerTransaction:id,reference')->first() : null,
            'review' => $review,
            'openDispute' => $seat ? $session->disputes()->where('room_participant_id', $seat->id)->where('status', 'open')->exists() : false,
        ]);
    }

    public function dispute(Request $request, Room $room, DisputeService $disputes): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:10', 'max:2000']]);

        try {
            $disputes->raise($request->user(), $room, $data['message']);
        } catch (AdministrativeActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('competitions.results', $room)->with('success', 'Your dispute has been sent. Staff will review it and reply on this page.');
    }

    public function show(Request $request, string $room, RoomEntryService $entries): View
    {
        $room = $this->rooms->findPublic($room);
        $seat = $request->user()
            ? RoomParticipant::query()->where('room_id', $room->id)->where('user_id', $request->user()->id)
                ->with(['entryLedgerTransaction:id,reference', 'refundLedgerTransaction:id,reference'])->first()
            : null;

        return view('competitions.show', [
            'room' => $room,
            'categories' => $room->category ? [$room->category->name] : ($this->rooms->categoryNames([$room])[$room->id] ?? []),
            'questionCount' => $this->rooms->questionCount($room),
            'eligibility' => $this->eligibility->check($room, $request->user()),
            'seat' => $seat,
            'canWithdraw' => $seat !== null && $entries->withdrawalBlocker($room, $seat) === null,
            'ranking' => $this->rooms->publishedRanking($room),
        ]);
    }
}
