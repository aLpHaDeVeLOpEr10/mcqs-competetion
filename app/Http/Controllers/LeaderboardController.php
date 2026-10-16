<?php

namespace App\Http\Controllers;

use App\Services\Competition\PublicRoomQuery;
use Illuminate\View\View;

class LeaderboardController extends Controller
{
    public function __invoke(PublicRoomQuery $rooms): View
    {
        return view('leaderboard.index', [
            'rooms' => $rooms->publishedResults(),
        ]);
    }
}
