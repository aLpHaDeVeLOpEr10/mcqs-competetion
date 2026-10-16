<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One provider notification as received, including rejected ones. Append-only;
 * the payload is sanitized (no signatures or secrets) before it is stored.
 */
class PaymentCallback extends Model
{
    use Immutable;

    public const APPLIED = 'applied';

    public const DUPLICATE = 'duplicate';

    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }
}
