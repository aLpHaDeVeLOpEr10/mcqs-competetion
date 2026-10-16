<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A queued CSV export. The file lives on the private disk and can be
 * downloaded only by the user who requested it, while they still hold the
 * report's permission, until it expires.
 */
class ReportExport extends Model
{
    public const EXPIRES_DAYS = 7;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'rows' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->created_at->lt(now()->subDays(self::EXPIRES_DAYS));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
