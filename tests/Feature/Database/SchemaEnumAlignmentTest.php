<?php

namespace Tests\Feature\Database;

use App\Enums\LedgerTransactionType;
use App\Enums\ParticipantStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayoutStatus;
use App\Enums\PrizeDistributionStatus;
use App\Enums\QuestionDifficulty;
use App\Enums\QuestionStatus;
use App\Enums\QuizPaperStatus;
use App\Enums\RefundStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\SessionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Migrations hard-code enum values; this keeps them in lockstep with the PHP enums.
 */
class SchemaEnumAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public static function enumColumns(): array
    {
        return [
            ['users', 'role', UserRole::class],
            ['users', 'status', UserStatus::class],
            ['questions', 'difficulty', QuestionDifficulty::class],
            ['questions', 'status', QuestionStatus::class],
            ['quiz_papers', 'status', QuizPaperStatus::class],
            ['rooms', 'type', RoomType::class],
            ['rooms', 'status', RoomStatus::class],
            ['room_participants', 'status', ParticipantStatus::class],
            ['competition_sessions', 'status', SessionStatus::class],
            ['wallets', 'type', WalletType::class],
            ['wallets', 'status', WalletStatus::class],
            ['ledger_transactions', 'type', LedgerTransactionType::class],
            ['payment_transactions', 'type', PaymentType::class],
            ['payment_transactions', 'status', PaymentStatus::class],
            ['payment_refunds', 'status', RefundStatus::class],
            ['prize_distributions', 'status', PrizeDistributionStatus::class],
            ['prize_payouts', 'status', PayoutStatus::class],
        ];
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    #[DataProvider('enumColumns')]
    public function test_database_enum_matches_php_enum(string $table, string $column, string $enum): void
    {
        $type = DB::scalar(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        $this->assertNotNull($type, "{$table}.{$column} does not exist");
        preg_match_all("/'([^']*)'/", $type, $matches);

        $this->assertSame($enum::values(), $matches[1], "{$table}.{$column} differs from {$enum}");
    }
}
