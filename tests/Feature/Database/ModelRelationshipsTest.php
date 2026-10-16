<?php

namespace Tests\Feature\Database;

use App\Enums\RoomStatus;
use App\Enums\UserRole;
use App\Models\Question;
use App\Models\QuizPaper;
use App\Models\QuizPaperQuestion;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainRecords;
use Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    use CreatesDomainRecords, RefreshDatabase;

    public function test_new_users_default_to_active_players(): void
    {
        $user = User::factory()->create();

        $this->assertSame(UserRole::Player, $user->role);
        $this->assertFalse($user->isStaff());
        $this->assertTrue(User::factory()->admin()->create()->isStaff());
    }

    public function test_role_cannot_be_mass_assigned(): void
    {
        $user = new User(['name' => 'x', 'email' => 'x@example.test', 'password' => 'secret', 'role' => 'super_admin']);

        $this->assertSame(UserRole::Player, $user->role);
    }

    public function test_wallet_balance_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new Wallet(['balance_minor' => 1_000_000]);
    }

    public function test_quiz_paper_orders_questions_by_position(): void
    {
        $paper = QuizPaper::factory()->create();
        $second = Question::factory()->create();
        $first = Question::factory()->create();

        QuizPaperQuestion::create(['quiz_paper_id' => $paper->id, 'question_id' => $second->id, 'position' => 2]);
        QuizPaperQuestion::create(['quiz_paper_id' => $paper->id, 'question_id' => $first->id, 'position' => 1]);

        $this->assertSame([$first->id, $second->id], $paper->questions->pluck('id')->all());
    }

    public function test_effective_time_limit_falls_back_from_item_to_question_to_paper(): void
    {
        $paper = QuizPaper::factory()->create(['default_time_limit_seconds' => 30]);
        $question = Question::factory()->create(['default_time_limit_seconds' => null]);
        $item = QuizPaperQuestion::create(['quiz_paper_id' => $paper->id, 'question_id' => $question->id, 'position' => 1]);

        $this->assertSame(30, $item->fresh()->effectiveTimeLimitSeconds());

        $question->update(['default_time_limit_seconds' => 15]);
        $this->assertSame(15, $item->fresh()->effectiveTimeLimitSeconds());

        $item->update(['time_limit_seconds' => 45]);
        $this->assertSame(45, $item->fresh()->effectiveTimeLimitSeconds());
    }

    public function test_answer_keys_are_hidden_from_serialization(): void
    {
        $question = Question::factory()->withOptions()->create();
        $sessionQuestion = $this->makeSessionQuestion($this->makeSession(Room::factory()->create()));
        $this->makeSessionOption($sessionQuestion, 1, true);

        foreach ($question->options->toArray() as $option) {
            $this->assertArrayNotHasKey('is_correct', $option);
            $this->assertArrayNotHasKey('correct_marker', $option);
        }

        $json = $sessionQuestion->load('options')->toJson();
        $this->assertStringNotContainsString('is_correct', $json);
        $this->assertStringNotContainsString('correct_marker', $json);
    }

    public function test_room_relationships_and_defaults(): void
    {
        $room = Room::factory()->open()->create(['max_participants' => 10]);
        RoomParticipant::factory()->count(3)->for($room)->create();
        $room->prizeTiers()->create(['rank_from' => 1, 'rank_to' => 3, 'amount_minor' => 0]);
        $session = $this->makeSession($room);

        $room->refresh();
        $this->assertSame(RoomStatus::Open, $room->status);
        $this->assertTrue($room->isFree());
        $this->assertCount(3, $room->participants);
        $this->assertTrue($room->session->is($session));
        $this->assertTrue($session->room->is($room));
        $this->assertSame(0, $room->prizeTiers->first()->totalAmountMinor());
        $this->assertTrue($room->quizPaper->is(QuizPaper::first()));
    }

    public function test_user_wallet_relationship_ignores_system_wallets(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->system(Wallet::SYSTEM_PRIZE_POOL)->create();
        $wallet = Wallet::factory()->for($user)->create();

        $this->assertTrue($user->wallet->is($wallet));
    }
}
