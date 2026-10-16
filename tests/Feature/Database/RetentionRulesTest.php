<?php

namespace Tests\Feature\Database;

use App\Models\Question;
use App\Models\QuizPaper;
use App\Models\QuizPaperQuestion;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainRecords;
use Tests\TestCase;

class RetentionRulesTest extends TestCase
{
    use CreatesDomainRecords, RefreshDatabase;

    public function test_a_room_with_participants_cannot_be_hard_deleted(): void
    {
        $participant = RoomParticipant::factory()->create();

        $this->expectException(QueryException::class);

        $participant->room->delete();
    }

    public function test_an_empty_draft_room_can_be_deleted_with_its_prize_tiers(): void
    {
        $room = Room::factory()->create();
        $room->prizeTiers()->create(['rank_from' => 1, 'rank_to' => 1, 'amount_minor' => 0]);

        $room->delete();

        $this->assertDatabaseCount('room_prize_tiers', 0);
    }

    public function test_a_user_with_a_wallet_cannot_be_hard_deleted(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->for($user)->create();

        $this->expectException(QueryException::class);

        $user->forceDelete();
    }

    public function test_user_delete_is_a_soft_delete_and_keeps_history(): void
    {
        $participant = RoomParticipant::factory()->create();

        $participant->user->delete();

        $this->assertSoftDeleted($participant->user);
        $this->assertDatabaseHas('room_participants', ['id' => $participant->id]);
    }

    public function test_a_wallet_with_ledger_entries_cannot_be_deleted(): void
    {
        $wallet = Wallet::factory()->create();
        $this->makeLedgerEntry($this->makeLedgerTransaction(), $wallet, 100);

        $this->expectException(QueryException::class);

        $wallet->delete();
    }

    public function test_a_question_used_in_a_paper_cannot_be_hard_deleted(): void
    {
        $paper = QuizPaper::factory()->withQuestions(1)->create();
        $question = $paper->questions()->first();

        $this->expectException(QueryException::class);

        $question->forceDelete();
    }

    public function test_session_snapshot_survives_soft_deletion_of_the_source_question(): void
    {
        $question = Question::factory()->withOptions()->create();
        // Snapshot rows are immutable, so the source is set when the row is created.
        $sessionQuestion = $this->makeSessionQuestion($this->makeSession(Room::factory()->create()), 1, ['source_question_id' => $question->id]);

        $question->delete();

        $this->assertSoftDeleted($question);
        $this->assertTrue($sessionQuestion->fresh()->sourceQuestion->is($question));
    }

    public function test_deleting_a_paper_removes_only_its_question_links(): void
    {
        $paper = QuizPaper::factory()->withQuestions(2)->create();
        $questionIds = $paper->items()->pluck('question_id');

        $paper->forceDelete();

        $this->assertSame(0, QuizPaperQuestion::count());
        $this->assertSame(2, Question::whereIn('id', $questionIds)->count());
    }
}
