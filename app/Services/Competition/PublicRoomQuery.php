<?php

namespace App\Services\Competition;

use App\Enums\RoomStatus;
use App\Models\Category;
use App\Models\ParticipantResult;
use App\Models\Room;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only queries behind the public pages. Selects only public columns
 * and never touches answer keys.
 */
class PublicRoomQuery
{
    public function paginate(CompetitionFilters $filters, int $perPage = 12): LengthAwarePaginator
    {
        $query = $this->baseQuery()->whereIn('status', $filters->statuses());

        if ($filters->fee === 'free') {
            $query->where('entry_fee_minor', 0);
        } elseif ($filters->fee === 'paid') {
            $query->where('entry_fee_minor', '>', 0);
        }

        if ($filters->capacity !== null) {
            [$min, $max] = CompetitionFilters::CAPACITY_BUCKETS[$filters->capacity];
            $query->where('max_participants', '>=', $min);

            if ($max !== null) {
                $query->where('max_participants', '<=', $max);
            }
        }

        if ($filters->categoryId !== null) {
            $query->whereHas('quizPaper.questions', fn (Builder $q) => $q->where('category_id', $filters->categoryId));
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @return EloquentCollection<int, Room>
     */
    public function featured(int $limit = 3): EloquentCollection
    {
        return $this->baseQuery()
            ->whereIn('status', [RoomStatus::Open, RoomStatus::Full, RoomStatus::Scheduled, RoomStatus::InProgress])
            ->limit($limit)
            ->get();
    }

    public function findPublic(int|string $id): Room
    {
        return Room::query()
            ->whereIn('status', RoomStatus::publicCases())
            ->with(['prizeTiers', 'session', 'category:id,name'])
            ->findOrFail($id);
    }

    /**
     * Category names per room, resolved from the room's quiz paper in one query.
     *
     * @param  iterable<Room>  $rooms
     * @return array<int, list<string>> keyed by room id
     */
    public function categoryNames(iterable $rooms): array
    {
        $rooms = collect($rooms);
        $paperIds = $rooms->pluck('quiz_paper_id')->unique()->values();

        if ($paperIds->isEmpty()) {
            return [];
        }

        $byPaper = DB::table('quiz_paper_questions')
            ->join('questions', 'questions.id', '=', 'quiz_paper_questions.question_id')
            ->join('categories', 'categories.id', '=', 'questions.category_id')
            ->whereIn('quiz_paper_questions.quiz_paper_id', $paperIds)
            ->whereNull('questions.deleted_at')
            ->select('quiz_paper_questions.quiz_paper_id', 'categories.name')
            ->distinct()
            ->orderBy('categories.name')
            ->get()
            ->groupBy('quiz_paper_id')
            ->map(fn (Collection $rows) => $rows->pluck('name')->all());

        return $rooms->mapWithKeys(fn (Room $room) => [
            $room->id => $byPaper[$room->quiz_paper_id] ?? [],
        ])->all();
    }

    public function questionCount(Room $room): int
    {
        return DB::table('quiz_paper_questions')
            ->join('questions', 'questions.id', '=', 'quiz_paper_questions.question_id')
            ->where('quiz_paper_questions.quiz_paper_id', $room->quiz_paper_id)
            ->whereNull('questions.deleted_at')
            ->count();
    }

    /**
     * @return EloquentCollection<int, Category>
     */
    public function filterCategories(): EloquentCollection
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Completed rooms whose results have been published, newest first,
     * with their top three results.
     */
    public function publishedResults(int $perPage = 10): LengthAwarePaginator
    {
        // Joined (not whereHas) so the list can be ordered by publication time;
        // both tables have a status column, so every column is qualified.
        return Room::query()
            ->select('rooms.*')
            ->join('competition_sessions', 'competition_sessions.room_id', '=', 'rooms.id')
            ->where('rooms.status', RoomStatus::Completed)
            ->whereNotNull('competition_sessions.results_published_at')
            ->orderByDesc('competition_sessions.results_published_at')
            ->orderByDesc('rooms.id')
            ->with([
                'session:id,room_id,results_published_at',
                'session.results' => fn ($q) => $q->whereNotNull('rank')->orderBy('rank')->orderBy('id')->limit(3),
                'session.results.participant:id,user_id',
                'session.results.participant.user:id,display_name',
            ])
            ->paginate($perPage);
    }

    /**
     * Full published ranking for one room, or null if results are not published.
     *
     * @return EloquentCollection<int, ParticipantResult>|null
     */
    public function publishedRanking(Room $room, int $limit = 50): ?EloquentCollection
    {
        if ($room->status !== RoomStatus::Completed || $room->session?->results_published_at === null) {
            return null;
        }

        return $room->session->results()
            ->whereNotNull('rank')
            ->orderBy('rank')
            ->orderBy('id')
            ->limit($limit)
            ->with('participant:id,user_id', 'participant.user:id,display_name')
            ->get();
    }

    protected function baseQuery(): Builder
    {
        return Room::query()
            ->select([
                'id', 'name', 'quiz_paper_id', 'type', 'status', 'entry_fee_minor', 'currency',
                'min_participants', 'max_participants', 'participants_count',
                'registration_opens_at', 'registration_closes_at', 'scheduled_start_at',
            ])
            ->with('prizeTiers')
            // Live first, then joinable, then upcoming; soonest start first; unscheduled last.
            ->orderByRaw("FIELD(status, 'in_progress', 'open', 'full', 'scheduled', 'completed', 'cancelled', 'refunded')")
            ->orderByRaw('scheduled_start_at IS NULL')
            ->orderBy('scheduled_start_at')
            ->orderByDesc('id');
    }
}
