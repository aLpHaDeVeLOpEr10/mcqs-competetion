<?php

namespace App\Services\Competition;

use App\Exceptions\AdministrativeActionException;
use App\Models\Room;
use App\Models\RoomPrizeTier;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Creates and edits rooms. Drafts are fully editable; once published, only
 * presentation fields may change, because players joined under the
 * published terms.
 *
 * $tiers: list of ['rank_from' => int, 'rank_to' => int, 'amount_minor' => int].
 */
class RoomService
{
    public const PRESENTATION_ATTRIBUTES = ['name', 'description', 'category_id'];

    public function __construct(protected AuditLogger $audit) {}

    public function create(User $actor, array $data, array $tiers): Room
    {
        return DB::transaction(function () use ($actor, $data, $tiers) {
            $room = new Room($data);
            $room->forceFill(['created_by' => $actor->id])->save();
            $this->replaceTiers($room, $tiers);

            $this->audit->record('room.created', $room, null, $this->summary($room), actor: $actor);

            return $room;
        });
    }

    public function update(User $actor, Room $room, array $data, array $tiers): Room
    {
        return DB::transaction(function () use ($actor, $room, $data, $tiers) {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);
            $before = $this->summary($room);

            if (! $room->isDraft()) {
                $changed = collect(Room::CRITICAL_ATTRIBUTES)
                    ->filter(fn ($key) => array_key_exists($key, $data) && $this->differs($room, $key, $data[$key]))
                    ->values();

                if ($changed->isNotEmpty() || $this->tiersDiffer($room, $tiers)) {
                    throw new AdministrativeActionException('This room is published, so its competition settings are locked ('
                        .($changed->implode(', ') ?: 'prizes').'). Only the title, description and category can change.');
                }

                $data = array_intersect_key($data, array_flip(self::PRESENTATION_ATTRIBUTES));
            }

            $room->fill($data)->save();

            if ($room->isDraft()) {
                $this->replaceTiers($room, $tiers);
            }

            $after = $this->summary($room->refresh());
            if ($after !== $before) {
                $this->audit->record('room.updated', $room, $before, $after, actor: $actor);
            }

            return $room;
        });
    }

    protected function replaceTiers(Room $room, array $tiers): void
    {
        $room->prizeTiers()->delete();

        foreach ($tiers as $tier) {
            RoomPrizeTier::create(['room_id' => $room->id] + $tier);
        }
    }

    protected function tiersDiffer(Room $room, array $tiers): bool
    {
        $current = $room->prizeTiers()->orderBy('rank_from')->get(['rank_from', 'rank_to', 'amount_minor', 'percent_bp'])
            ->map(fn ($t) => [$t->rank_from, $t->rank_to, $t->amount_minor, $t->percent_bp])->all();
        $proposed = collect($tiers)->sortBy('rank_from')->map(fn ($t) => [$t['rank_from'], $t['rank_to'], $t['amount_minor'], $t['percent_bp'] ?? null])->values()->all();

        return $current !== $proposed;
    }

    protected function differs(Room $room, string $key, mixed $value): bool
    {
        $current = $room->getAttribute($key);

        if ($current instanceof \BackedEnum) {
            $current = $current->value;
        }
        if ($current instanceof \DateTimeInterface) {
            return $value === null || ! $current->equalTo($value);
        }

        return $value != $current;
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Room $room): array
    {
        $values = $room->only(['name', 'description', 'category_id', ...Room::CRITICAL_ATTRIBUTES]);

        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                default => $value,
            };
        }

        $values['prize_tiers'] = $room->prizeTiers()->orderBy('rank_from')->get(['rank_from', 'rank_to', 'amount_minor', 'percent_bp'])
            ->map(fn ($t) => [$t->rank_from, $t->rank_to, $t->amount_minor, $t->percent_bp])->all();

        return $values;
    }
}
