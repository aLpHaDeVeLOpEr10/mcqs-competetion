<?php

namespace Tests\Feature\Public;

use App\Enums\RoomStatus;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesPublishedResults;
use Tests\TestCase;

class LeaderboardTest extends TestCase
{
    use CreatesPublishedResults, RefreshDatabase;

    public function test_empty_state_when_nothing_is_published(): void
    {
        Room::factory()->open()->create();

        $this->get(route('leaderboard'))->assertOk()->assertSee('No published results yet');
    }

    public function test_shows_only_completed_rooms_with_published_results(): void
    {
        $this->completedRoom([['alpha', 10, 1]], roomAttributes: ['name' => 'Published Cup']);
        $this->completedRoom([['beta', 10, 1]], published: false, roomAttributes: ['name' => 'Unpublished Cup']);

        $this->get(route('leaderboard'))
            ->assertOk()
            ->assertSee('Published Cup')
            ->assertSee('alpha')
            ->assertDontSee('Unpublished Cup')
            ->assertDontSee('beta');
    }

    public function test_shows_top_three_in_rank_order_without_private_data(): void
    {
        $room = $this->completedRoom([
            ['fourth', 3, 4], ['first', 9, 1], ['third', 5, 3], ['second', 7, 2],
        ]);
        $users = $room->participants()->with('user')->get()->pluck('user');

        $response = $this->get(route('leaderboard'))
            ->assertSeeInOrder(['#1', 'first', '9 pts', '#2', 'second', '#3', 'third'])
            ->assertDontSee('fourth');

        foreach ($users as $user) {
            $response->assertDontSee($user->email)->assertDontSee($user->name);
        }
    }

    public function test_tied_players_share_a_rank(): void
    {
        $this->completedRoom([['tiedA', 8, 1], ['tiedB', 8, 1], ['last', 2, 3]]);

        $this->get(route('leaderboard'))->assertSeeInOrder(['#1', 'tiedA', '#1', 'tiedB', '#3', 'last']);
    }

    public function test_a_completed_status_alone_is_not_enough(): void
    {
        Room::factory()->status(RoomStatus::Completed)->create(['name' => 'No Session Room']);

        $this->get(route('leaderboard'))->assertDontSee('No Session Room');
    }
}
