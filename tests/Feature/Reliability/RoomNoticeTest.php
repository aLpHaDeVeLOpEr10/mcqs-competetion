<?php

namespace Tests\Feature\Reliability;

use App\Enums\ParticipantStatus;
use App\Jobs\NotifyRoomPlayers;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Notifications\CompetitionStarted;
use App\Services\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RoomNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_notices_are_chunked_and_sent_once_per_player_even_when_retried(): void
    {
        Notification::fake();
        $room = Room::factory()->create(['max_participants' => 600]);
        $players = User::factory()->count(NotifyRoomPlayers::CHUNK + 1)->create();
        foreach ($players as $player) {
            RoomParticipant::factory()->create(['room_id' => $room->id, 'user_id' => $player->id, 'status' => ParticipantStatus::Playing]);
        }
        $withdrawn = User::factory()->create();
        RoomParticipant::factory()->create(['room_id' => $room->id, 'user_id' => $withdrawn->id, 'status' => ParticipantStatus::Withdrawn]);

        $notice = new CompetitionStarted($room);
        app(Notifier::class)->toRoom($room->id, [ParticipantStatus::Playing], $notice);      // 2 chunks (sync queue)
        (new NotifyRoomPlayers($room->id, ['playing'], $notice))->handle(app(Notifier::class)); // a retried first chunk

        Notification::assertCount(NotifyRoomPlayers::CHUNK + 1);
        Notification::assertSentTo($players->last(), CompetitionStarted::class);
        Notification::assertNotSentTo($withdrawn, CompetitionStarted::class);
        $this->assertSame(NotifyRoomPlayers::CHUNK + 1, DB::table('notification_dispatches')->where('key', 'like', 'room-notice:CompetitionStarted:'.$room->id.':%')->count());
    }

    public function test_with_a_queue_the_notice_leaves_the_engine_as_a_job_after_commit(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $room = Room::factory()->create();

        DB::transaction(function () use ($room) {
            app(Notifier::class)->toRoom($room->id, [ParticipantStatus::Playing], new CompetitionStarted($room));
            Queue::assertNothingPushed();
        });

        Queue::assertPushedOn('default', NotifyRoomPlayers::class);
    }
}
