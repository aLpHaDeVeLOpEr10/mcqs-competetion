<?php

namespace App\Http\Controllers;

use App\Services\Competition\JoinEligibilityService;
use App\Services\Competition\PublicRoomQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Request $request, PublicRoomQuery $rooms, JoinEligibilityService $eligibility): View
    {
        $featured = $rooms->featured(3);

        return view('home', [
            'featured' => $featured,
            'categories' => $rooms->categoryNames($featured),
            'eligibility' => $eligibility->forRooms($featured, $request->user()),
        ]);
    }
}
