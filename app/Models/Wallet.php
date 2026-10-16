<?php

namespace App\Models;

use App\Enums\WalletStatus;
use App\Enums\WalletType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory;

    public const SYSTEM_PRIZE_POOL = 'prize_pool';

    public const SYSTEM_PLATFORM_REVENUE = 'platform_revenue';

    public const SYSTEM_GATEWAY_CLEARING = 'gateway_clearing';

    /** Counterparty for controlled administrative adjustments and reversals. */
    public const SYSTEM_ADJUSTMENTS = 'adjustments';

    /** Tax withheld from prizes where legally required (rate: competition.prizes.tax_withholding_bp). */
    public const SYSTEM_TAX_WITHHOLDING = 'tax_withholding';

    /**
     * Balances change only through ledger entries; nothing is mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected $attributes = [
        'currency' => 'PKR',
        'balance_minor' => 0,
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'type' => WalletType::class,
            'status' => WalletStatus::class,
            'balance_minor' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public static function systemCodes(): array
    {
        return [self::SYSTEM_PRIZE_POOL, self::SYSTEM_PLATFORM_REVENUE, self::SYSTEM_GATEWAY_CLEARING, self::SYSTEM_ADJUSTMENTS, self::SYSTEM_TAX_WITHHOLDING];
    }

    /**
     * Who the wallet belongs to, for staff screens.
     */
    public function ownerLabel(): string
    {
        return $this->type === WalletType::System
            ? 'Platform: '.str_replace('_', ' ', (string) $this->system_code)
            : ($this->user?->display_name ?: 'Player #'.$this->user_id);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
