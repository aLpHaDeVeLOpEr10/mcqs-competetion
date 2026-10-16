<?php

namespace App\Services\Account;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lists and revokes a user's sessions. Requires the database session driver;
 * with any other driver, listing returns nothing and revocation relies on
 * AuthenticateSession alone.
 */
class SessionService
{
    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return Collection<int, object{id: string, ip_address: ?string, device: string, last_active: CarbonImmutable, is_current: bool}>
     */
    public function forUser(User $user, string $currentSessionId): Collection
    {
        if (! $this->supported()) {
            return collect();
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($row) => (object) [
                'id' => $row->id,
                'ip_address' => $row->ip_address,
                'device' => $this->describeAgent((string) $row->user_agent),
                'last_active' => CarbonImmutable::createFromTimestamp($row->last_activity),
                'is_current' => hash_equals($row->id, $currentSessionId),
            ]);
    }

    public function revokeOthers(User $user, string $currentSessionId): int
    {
        if (! $this->supported()) {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    public function revokeAll(User $user): int
    {
        if (! $this->supported()) {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
    }

    protected function describeAgent(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'unknown device',
        };

        return "{$browser} on {$platform}";
    }
}
