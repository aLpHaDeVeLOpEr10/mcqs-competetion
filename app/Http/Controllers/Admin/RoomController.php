<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuizPaperStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\ContentValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoomRequest;
use App\Models\Category;
use App\Models\QuizPaper;
use App\Models\Room;
use App\Services\Competition\RoomConfigValidator;
use App\Services\Competition\RoomLifecycle;
use App\Services\Competition\RoomService;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoomController extends Controller
{
    public function __construct(
        protected RoomService $rooms,
        protected RoomLifecycle $lifecycle,
        protected RoomConfigValidator $validator,
    ) {}

    public function index(Request $request): View
    {
        $filters = QueryFilters::from($request, [
            'q' => ['string', 'max:100'],
            'status' => [Rule::enum(RoomStatus::class)],
            'type' => [Rule::enum(RoomType::class)],
        ]);

        $query = Room::query()->with('quizPaper:id,title');
        $filters->search($query, 'q', ['name']);
        $query->when($filters->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($filters->get('type'), fn ($q, $t) => $q->where('type', $t));

        return view('admin.rooms.index', [
            'rooms' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function show(Room $room): View
    {
        return view('admin.rooms.show', [
            'room' => $room->load(['quizPaper:id,title', 'category:id,name', 'prizeTiers', 'session', 'creator:id,name']),
            'participants' => $room->participants()->with(['user:id,display_name', 'entryLedgerTransaction:id,reference', 'refundLedgerTransaction:id,reference'])->latest('joined_at')->paginate(25),
            'problems' => $room->isDraft() ? $this->validator->problems($room) : [],
        ]);
    }

    public function create(): View
    {
        return view('admin.rooms.form', $this->formData(new Room([
            'type' => RoomType::Scheduled->value,
            'min_participants' => 2,
            'max_participants' => 50,
            'countdown_seconds' => 10,
            'question_gap_seconds' => 3,
        ])));
    }

    public function store(RoomRequest $request): RedirectResponse
    {
        $room = $this->rooms->create($request->user(), $request->roomData(), $request->prizeTiers());

        return redirect()->route('admin.rooms.show', $room)->with('success', 'Draft room saved. Review the checks, then publish.');
    }

    public function edit(Room $room): View
    {
        return view('admin.rooms.form', $this->formData($room->load('prizeTiers')));
    }

    public function update(RoomRequest $request, Room $room): RedirectResponse
    {
        try {
            $this->rooms->update($request->user(), $room, $request->roomData(), $request->prizeTiers());
        } catch (AdministrativeActionException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.rooms.show', $room)->with('success', 'Room saved.');
    }

    public function publish(Request $request, Room $room): RedirectResponse
    {
        try {
            $this->lifecycle->publish($request->user(), $room);
        } catch (ContentValidationException $e) {
            return back()->with('error', $e->getMessage())->with('problems', $e->problems);
        } catch (AdministrativeActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.rooms.show', $room)->with('success', 'Room published. Its questions are now frozen in a competition snapshot.');
    }

    public function cancel(Request $request, Room $room): RedirectResponse
    {
        $data = $request->validateWithBag('cancel', [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'confirm' => ['accepted'],
        ]);

        try {
            $this->lifecycle->cancel($room, $request->user(), $data['reason']);
        } catch (AdministrativeActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.rooms.show', $room)->with('success', 'Room cancelled. All seats were released.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(Room $room): array
    {
        return [
            'room' => $room,
            'papers' => QuizPaper::query()->where('status', QuizPaperStatus::Published)->orderBy('title')->pluck('title', 'id')->all(),
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }
}
