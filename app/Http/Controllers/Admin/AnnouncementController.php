<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnnouncementRequest;
use App\Models\Announcement;
use App\Models\Room;
use App\Services\Notifications\AnnouncementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('admin.announcements.index', [
            'announcements' => Announcement::query()->with(['author:id,name', 'room:id,name'])->latest('id')->paginate(25),
            'rooms' => Room::query()->where('status', '!=', RoomStatus::Draft)->latest('id')->limit(100)->pluck('name', 'id')->all(),
        ]);
    }

    public function store(AnnouncementRequest $request, AnnouncementService $announcements): RedirectResponse
    {
        $announcements->publish($request->user(), $request->safe()->only(['title', 'body', 'audience', 'room_id']));

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement queued for delivery.');
    }
}
