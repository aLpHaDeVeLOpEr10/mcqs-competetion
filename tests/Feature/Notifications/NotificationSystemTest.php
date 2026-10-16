<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\SendAnnouncement;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\DepositConfirmed;
use App\Notifications\ResultsReady;
use App\Notifications\RoomJoined;
use App\Services\Admin\UserAdministrationService;
use App\Services\Competition\RoomEntryService;
use App\Services\Notifications\AnnouncementService;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\Notifier;
use App\Services\Payments\DepositService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PlaysCompetitions;
use Tests\Support\UsesFakeGateway;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
{
    use PlaysCompetitions, RefreshDatabase, UsesFakeGateway;

    protected function types(User $user): array
    {
        // Sorted: notifications created in the same second have no defined order.
        return $user->notifications()->get()->map(fn ($n) => $n->data['type'])->sort()->values()->all();
    }

    public function test_competition_lifecycle_events_create_notifications(): void
    {
        $this->competitionWithPrizes(2, [], ['name' => 'Notify Cup']);
        $this->playOut([1 => [0 => [1000, true]]]);

        $this->assertSame(['competition_started', 'results_ready', 'room_joined'], $this->types($this->players[0]));
        $joined = $this->players[0]->notifications()->where('data->type', 'room_joined')->sole();
        $this->assertSame("You're registered for Notify Cup", $joined->data['title']);
        $this->assertSame('competition', $joined->data['category']);
        $this->assertStringContainsString('/account/competitions/'.$this->room->id, $joined->data['url']);
    }

    public function test_money_and_account_events_create_notifications(): void
    {
        // Deposit confirmed / failed.
        $this->enableFakeGateway();
        $user = User::factory()->create();
        $ok = app(DepositService::class)->initiate($user, 50_000, (string) Str::uuid());
        $this->post(route('payments.callback', 'fake'), $this->fake()->complete($ok->gateway_reference, 'succeeded'));
        $bad = app(DepositService::class)->initiate($user, 20_000, (string) Str::uuid());
        $this->post(route('payments.callback', 'fake'), $this->fake()->complete($bad->gateway_reference, 'failed'));

        // Entry fee refunded when the player withdraws.
        config(['competition.paid_entries.enabled' => true]);
        $room = $this->openRoom(['entry_fee_minor' => 10_000, 'min_participants' => 2, 'max_participants' => 10]);
        app(RoomEntryService::class)->join($user, $room);
        app(RoomEntryService::class)->withdraw($user, $room);

        // Account status changed by staff (a security notice).
        app(UserAdministrationService::class)->changeStatus(User::factory()->role(UserRole::Admin)->create(), $user, UserStatus::Restricted, 'Document check');

        $this->assertSame(['account_status_changed', 'deposit_confirmed', 'deposit_failed', 'entry_fee_refunded', 'room_joined'], $this->types($user));
        $this->assertSame('Rs 500 was added to your wallet (reference '.$ok->reference.').',
            $user->notifications()->where('data->type', 'deposit_confirmed')->sole()->data['body']);
    }

    public function test_notifications_are_not_sent_for_rolled_back_work(): void
    {
        $user = User::factory()->create();
        $room = $this->openRoom();

        try {
            DB::transaction(function () use ($user, $room) {
                app(Notifier::class)->afterCommit($user, new RoomJoined($room, $room->participants()->make(['entry_fee_minor' => 0])));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_users_only_see_and_change_their_own_notifications(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        $room = $this->openRoom(['name' => 'Alice Only Room']);
        app(Notifier::class)->send($alice, new ResultsReady($room));
        $notification = $alice->notifications()->sole();

        $this->actingAs($bob)->get(route('account.notifications'))->assertOk()->assertDontSee('Alice Only Room');
        $this->actingAs($bob)->get(route('account.show'))->assertDontSee('Alice Only Room');
        $this->actingAs($bob)->get(route('account.notifications.open', $notification->id))->assertNotFound();
        $this->actingAs($bob)->post(route('account.notifications.mark', $notification->id))->assertNotFound();
        $this->actingAs($bob)->post(route('account.notifications.read'));
        $this->assertNull($notification->fresh()->read_at);

        $this->actingAs($alice)->get(route('account.notifications'))->assertSee('Results for Alice Only Room are published');
    }

    public function test_unread_counts_and_marking_as_read(): void
    {
        $user = User::factory()->create();
        foreach (['First Room', 'Second Room', 'Third Room'] as $name) {
            app(Notifier::class)->send($user, new ResultsReady($this->openRoom(['name' => $name])));
            $this->travel(1)->seconds(); // timestamps have second precision
        }
        // notifications() is ordered newest first by Laravel; reorder to oldest first.
        [$first, $second] = $user->notifications()->reorder()->oldest()->get();

        $this->actingAs($user)->get(route('account.show'))
            ->assertSee('Notifications, 3 unread')->assertSee('3 new');
        $this->actingAs($user)->get(route('account.notifications', ['filter' => 'unread']))->assertSee('Unread (3)');

        // Opening one marks it read and follows its link.
        $this->actingAs($user)->get(route('account.notifications.open', $first->id))
            ->assertRedirect(route('competitions.results', $first->data['room_id']));
        $this->actingAs($user)->post(route('account.notifications.mark', $second->id))->assertSessionHas('success');
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertSame(1, $user->unreadNotifications()->count());
        $unreadPage = str($this->actingAs($user)->get(route('account.notifications', ['filter' => 'unread']))->getContent())->after('<main');
        $this->assertTrue($unreadPage->contains('Third Room'));
        $this->assertFalse($unreadPage->contains('First Room'), 'Read notifications are not in the unread list.');

        $this->actingAs($user)->post(route('account.notifications.read'));
        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->actingAs($user)->get(route('account.notifications', ['filter' => 'unread']))->assertSee('No unread notifications');
    }

    public function test_the_notifications_page_is_paginated(): void
    {
        $user = User::factory()->create();
        $room = $this->openRoom();
        foreach (range(1, 23) as $i) {
            app(Notifier::class)->send($user, new ResultsReady($room));
        }

        $count = fn (int $page) => preg_match_all('#/account/notifications/[0-9a-f-]{36}"#',
            str($this->actingAs($user)->get(route('account.notifications', ['page' => $page]))->getContent())->after('<main')->toString());
        $this->assertSame(20, $count(1));
        $this->assertSame(3, $count(2));
    }

    public function test_reminders_are_sent_once_per_offset_and_never_duplicated(): void
    {
        $room = $this->openRoom(['min_participants' => 2, 'max_participants' => 10, 'scheduled_start_at' => now()->addMinutes(30)]);
        [$a, $b, $left] = User::factory()->count(3)->create();
        foreach ([$a, $b, $left] as $user) {
            app(RoomEntryService::class)->join($user, $room);
        }
        app(RoomEntryService::class)->withdraw($left, $room);
        $reminders = fn (User $u) => $u->notifications()->where('data->type', 'competition_reminder')->count();

        $this->artisan('notifications:reminders');
        $this->assertSame(0, $reminders($a), 'Too early for any reminder.');

        $this->travel(16)->minutes(); // 14 minutes left
        $this->artisan('notifications:reminders');
        $this->artisan('notifications:reminders');
        $this->assertSame(1, $reminders($a));
        $this->assertSame("{$room->name} starts in 14 minutes", $a->notifications()->where('data->type', 'competition_reminder')->sole()->data['title']);

        $this->travel(10)->minutes(); // 4 minutes left
        $this->artisan('notifications:reminders');
        $this->artisan('notifications:reminders');
        $this->assertSame([2, 2, 0], [$reminders($a), $reminders($b), $reminders($left)]);
        $this->assertSame(4, DB::table('notification_dispatches')->where('key', 'like', 'reminder:%')->count());
    }

    public function test_a_late_scheduler_sends_only_the_nearest_reminder(): void
    {
        $room = $this->openRoom(['min_participants' => 2, 'max_participants' => 10, 'scheduled_start_at' => now()->addMinutes(30)]);
        $user = User::factory()->create();
        app(RoomEntryService::class)->join($user, $room);

        $this->travel(27)->minutes(); // the 15-minute reminder was missed; 3 minutes left
        $this->artisan('notifications:reminders');

        $titles = $user->notifications()->where('data->type', 'competition_reminder')->pluck('data')->pluck('title')->all();
        $this->assertSame(["{$room->name} starts in 3 minutes"], $titles);
    }

    public function test_in_app_delivery_is_immediate_while_email_is_queued(): void
    {
        config(['queue.default' => 'database', 'broadcasting.default' => 'null']);
        $user = User::factory()->create(); // verified, results e-mail on by default

        app(Notifier::class)->send($user, new ResultsReady($this->openRoom()));

        $this->assertSame(1, $user->notifications()->count(), 'The in-app record does not wait for a worker.');
        $jobs = DB::table('jobs')->pluck('payload');
        $this->assertCount(1, $jobs, 'Only the e-mail is queued.');
        $this->assertStringContainsString('mail', $jobs[0]);
        $this->assertStringContainsString('ResultsReady', $jobs[0]);
    }

    public function test_a_failing_email_never_undoes_the_operation(): void
    {
        // Mail is sent synchronously here and the SMTP server is unreachable.
        config(['queue.default' => 'sync', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        $user = User::factory()->create();

        app(UserAdministrationService::class)->changeStatus(User::factory()->role(UserRole::Admin)->create(), $user, UserStatus::Restricted, 'Document check');

        $this->assertSame(UserStatus::Restricted, $user->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'user.status_changed')->count());
        $this->assertSame(1, $user->notifications()->count(), 'The in-app notice was still recorded.');
    }

    public function test_preferences_control_optional_channels_but_not_security_notices(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('account.notifications.preferences'))->assertOk()->assertSee('Account and security');

        $this->actingAs($user)->put(route('account.notifications.preferences.update'), ['preferences' => [
            'competition' => ['database' => '0', 'mail' => '0'],
            'security' => ['database' => '0', 'mail' => '0'],
            'wallet' => ['database' => '0', 'mail' => '0'],
        ]])->assertSessionHas('success');

        $preferences = app(NotificationPreferences::class);
        $user->refresh();
        $this->assertFalse($preferences->wants($user, NotificationCategory::Competition, 'database'));
        $this->assertTrue($preferences->wants($user, NotificationCategory::Security, 'database'), 'Security notices cannot be switched off.');
        $this->assertTrue($preferences->wants($user, NotificationCategory::Security, 'mail'));
        $this->assertTrue($preferences->wants($user, NotificationCategory::Wallet, 'database'), 'Money movements always appear in the app.');
        $this->assertFalse($preferences->wants($user, NotificationCategory::Wallet, 'mail'));

        app(RoomEntryService::class)->join($user, $this->openRoom());
        app(UserAdministrationService::class)->changeStatus(User::factory()->role(UserRole::Admin)->create(), $user, UserStatus::Restricted, 'Check');
        $this->assertSame(['account_status_changed'], $this->types($user), 'The opted-out join notice was not stored; the security notice was.');
    }

    public function test_broadcasts_carry_no_sensitive_data(): void
    {
        $this->enableFakeGateway();
        $user = User::factory()->create();
        $payment = app(DepositService::class)->initiate($user, 50_000, (string) Str::uuid());
        $notification = new DepositConfirmed($payment);
        $notification->id = (string) Str::uuid();

        $data = $notification->toBroadcast($user)->data;

        $this->assertSame(['id', 'category', 'title', 'url'], array_keys($data));
        $this->assertSame('Deposit confirmed', $data['title']);
        $this->assertStringNotContainsString('500', json_encode($data));
    }

    public function test_users_cannot_subscribe_to_another_users_notification_channel(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');
        [$alice, $bob] = User::factory()->count(2)->create();
        $auth = fn (User $as, User $channelOf) => $this->actingAs($as)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$channelOf->id,
        ]);

        $auth($alice, $alice)->assertOk()->assertJsonStructure(['auth']);
        $auth($bob, $alice)->assertForbidden();
        $this->signOut();
        $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$alice->id])->assertForbidden();
    }

    public function test_admins_send_announcements_to_the_chosen_audience(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $players = User::factory()->count(3)->create();
        $staff = User::factory()->role(UserRole::ContentManager)->create();
        $suspended = User::factory()->create(['status' => UserStatus::Suspended]);

        $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => 'Maintenance tonight', 'body' => 'The site is down from 2:00 to 2:30 AM PKT.', 'audience' => 'players', 'confirm' => '1',
        ])->assertRedirect(route('admin.announcements.index'))->assertSessionHas('success');

        $announcement = Announcement::query()->sole();
        $this->assertSame($admin->id, $announcement->created_by);
        $this->assertNotNull($announcement->sent_at);
        $this->assertSame(3, $announcement->recipients_count);
        foreach ($players as $player) {
            $this->assertSame('Maintenance tonight', $player->notifications()->sole()->data['title']);
        }
        $this->assertSame(0, $staff->notifications()->count());
        $this->assertSame(0, $suspended->notifications()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'announcement.created')->where('actor_id', $admin->id)->count());

        // A retried delivery does not notify anyone twice.
        $announcement->forceFill(['sent_at' => null])->save();
        (new SendAnnouncement($announcement->id))->handle(app(AnnouncementService::class), app(Notifier::class));
        $this->assertSame(1, $players[0]->notifications()->count());

        $this->actingAs($admin)->get(route('admin.announcements.index'))->assertOk()->assertSee('Maintenance tonight')->assertSee($admin->name);
    }

    public function test_announcements_are_validated_and_restricted(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $room = $this->openRoom();
        $member = User::factory()->create();
        app(RoomEntryService::class)->join($member, $room);
        $outsider = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.announcements.store'), ['title' => 'Hi', 'body' => 'short', 'audience' => 'room'])
            ->assertSessionHasErrors(['title', 'body', 'room_id', 'confirm']);

        $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => 'Room update', 'body' => 'Question 3 will be reviewed after the game.', 'audience' => 'room', 'room_id' => $room->id, 'confirm' => '1',
        ])->assertSessionHas('success');
        $announcements = fn (User $u) => $u->notifications()->where('data->type', 'announcement_published')->count();
        $this->assertSame(1, $announcements($member));
        $this->assertSame(0, $announcements($outsider));

        foreach ([UserRole::Player, UserRole::ContentManager, UserRole::FinanceManager] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->post(route('admin.announcements.store'), [
                'title' => 'Not allowed', 'body' => 'This must not be sent.', 'audience' => 'everyone', 'confirm' => '1',
            ])->assertForbidden();
        }
        $this->assertSame(1, Announcement::query()->count());
    }
}
