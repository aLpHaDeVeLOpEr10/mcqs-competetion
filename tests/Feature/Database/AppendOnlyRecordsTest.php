<?php

namespace Tests\Feature\Database;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesDomainRecords;
use Tests\TestCase;

class AppendOnlyRecordsTest extends TestCase
{
    use CreatesDomainRecords, RefreshDatabase;

    public function test_ledger_transactions_cannot_be_updated_through_eloquent(): void
    {
        $transaction = $this->makeLedgerTransaction();

        $this->expectException(ImmutableRecordException::class);

        $transaction->forceFill(['description' => 'changed'])->save();
    }

    public function test_ledger_entries_cannot_be_deleted_through_eloquent(): void
    {
        $entry = $this->makeLedgerEntry($this->makeLedgerTransaction(), Wallet::factory()->create(), 500);

        $this->expectException(ImmutableRecordException::class);

        $entry->delete();
    }

    public function test_database_rejects_raw_ledger_entry_updates(): void
    {
        $entry = $this->makeLedgerEntry($this->makeLedgerTransaction(), Wallet::factory()->create(), 500);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ledger_entries is append-only');

        DB::table('ledger_entries')->where('id', $entry->id)->update(['amount_minor' => 999]);
    }

    public function test_database_rejects_raw_ledger_transaction_deletes(): void
    {
        $transaction = $this->makeLedgerTransaction();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ledger_transactions is append-only');

        DB::table('ledger_transactions')->where('id', $transaction->id)->delete();
    }

    public function test_audit_logs_are_append_only(): void
    {
        $log = tap((new AuditLog)->forceFill(['action' => 'room.status_changed']))->save();

        $this->assertNotNull($log->created_at);
        $this->assertNull($log->getUpdatedAtColumn());

        try {
            DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
            $this->fail('Raw audit log update was not rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('audit_logs is append-only', $e->getMessage());
        }

        $this->expectException(ImmutableRecordException::class);
        $log->delete();
    }

    public function test_ledger_timestamps_keep_millisecond_precision(): void
    {
        $this->travelTo(now()->setTime(10, 0, 0, 123_000));

        $transaction = $this->makeLedgerTransaction();

        $this->assertSame('123', $transaction->fresh()->created_at->format('v'));
    }
}
