<?php

namespace App\Http\Controllers\Account;

use App\Enums\NotificationCategory;
use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/**
 * The signed-in user's own in-app notifications. Every lookup goes through
 * $request->user()->notifications(), so another user's notification id is
 * simply not found.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $unreadOnly = $request->query('filter') === 'unread';

        return view('account.notifications', [
            'unreadOnly' => $unreadOnly,
            'unreadCount' => $request->user()->unreadNotificationCount(),
            'notifications' => ($unreadOnly ? $request->user()->unreadNotifications() : $request->user()->notifications())
                ->latest()->paginate(20)->withQueryString(),
        ]);
    }

    /**
     * Mark one as read and go where it points (only to this application's own URLs).
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $record = $this->find($request, $notification);
        $record->markAsRead();

        $url = $record->data['url'] ?? null;

        return $this->isInternal($url)
            ? redirect()->to($url)
            : redirect()->route('account.notifications');
    }

    /**
     * Only same-origin targets (scheme, host and port all equal). A prefix check would
     * accept "https://app.example.com.evil.test/".
     */
    protected function isInternal(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $target = parse_url($url);
        $app = parse_url(url('/'));
        if ($target === false || $app === false) {
            return false;
        }

        foreach (['scheme', 'host', 'port'] as $part) {
            if (($target[$part] ?? null) !== ($app[$part] ?? null)) {
                return false;
            }
        }

        return ! isset($target['user']) && ! isset($target['pass']);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $this->find($request, $notification)->markAsRead();

        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    public function preferences(Request $request, NotificationPreferences $preferences): View
    {
        return view('account.notification-preferences', [
            'categories' => NotificationCategory::cases(),
            'matrix' => $preferences->matrix($request->user()),
        ]);
    }

    public function updatePreferences(Request $request, NotificationPreferences $preferences): RedirectResponse
    {
        $data = $request->validate([
            'preferences' => ['nullable', 'array'],
            'preferences.*' => ['array'],
            'preferences.*.*' => ['boolean'],
        ]);

        $preferences->update($request->user(), $data['preferences'] ?? []);

        return back()->with('success', 'Notification preferences saved.');
    }

    protected function find(Request $request, string $id): DatabaseNotification
    {
        return $request->user()->notifications()->whereKey($id)->firstOrFail();
    }
}
