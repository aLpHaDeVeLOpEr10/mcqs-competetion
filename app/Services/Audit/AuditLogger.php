<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SensitiveData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Writes append-only audit records (see the audit_logs triggers). Call it
 * inside the same transaction as the change it records, so either both are
 * persisted or neither is.
 *
 * Every record carries the correlation id of the request / command / job
 * that produced it (Context "request_id"). Values are sanitized: keys that
 * look like credentials (password, token, secret, card, account number…) are
 * replaced with "[removed]" before anything is stored.
 */
class AuditLogger
{
    public function __construct(protected Request $request) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?array $metadata = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= $this->request->user();
        $clean = fn (?array $values) => $values === null ? null : SensitiveData::strip($values, 2000, SensitiveData::CREDENTIALS);

        $log = new AuditLog;
        $log->forceFill([
            'actor_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $clean($old),
            'new_values' => $clean($new),
            'metadata' => $clean($metadata),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 250, ''),
            'request_id' => Context::get('request_id'),
        ])->save();

        return $log;
    }
}
