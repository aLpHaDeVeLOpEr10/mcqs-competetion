<?php

namespace App\Services\Notifications;

use App\Enums\AnnouncementAudience;
use App\Enums\ParticipantStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\AdministrativeActionException;
use App\Jobs\SendAnnouncement;
use App\Models\Announcement;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Platform announcements: created by authorized staff (recorded as author and
 * audited), then delivered to the audience by a queued job.
 */
class AnnouncementService
{
    public function __construct(protected AuditLogger $audit) {}

    /**
     * @param  array{title: string, body: string, audience: string, room_id?: ?int}  $data
     */
    public function publish(User $actor, array $data): Announcement
    {
        if (! $actor->hasPermission(Permission::AnnouncementsManage)) {
            throw new AdministrativeActionException('You are not allowed to send announcements.');
        }

        return DB::transaction(function () use ($actor, $data) {
            $audience = AnnouncementAudience::from($data['audience']);
            $announcement = tap((new Announcement)->forceFill([
                'title' => $data['title'],
                'body' => $data['body'],
                'audience' => $audience,
                'room_id' => $audience === AnnouncementAudience::Room ? $data['room_id'] : null,
                'created_by' => $actor->id,
            ]))->save();

            $this->audit->record('announcement.created', $announcement, null, [
                'title' => $announcement->title,
                'audience' => $audience->value,
                'room_id' => $announcement->room_id,
            ], actor: $actor);

            SendAnnouncement::dispatch($announcement->id)->afterCommit();

            return $announcement;
        });
    }

    /**
     * Who receives an announcement. Only accounts that can still sign in.
     *
     * @return Builder<User>
     */
    public function recipients(Announcement $announcement): Builder
    {
        $query = User::query()->whereIn('status', [UserStatus::Active, UserStatus::Restricted]);

        return match ($announcement->audience) {
            AnnouncementAudience::Everyone => $query,
            AnnouncementAudience::Players => $query->where('role', UserRole::Player),
            AnnouncementAudience::Staff => $query->where('role', '!=', UserRole::Player),
            AnnouncementAudience::Room => $query->whereIn('id', RoomParticipant::query()->where('room_id', $announcement->room_id)
                ->whereNotIn('status', [ParticipantStatus::Withdrawn, ParticipantStatus::Expired])->select('user_id')),
        };
    }
}
