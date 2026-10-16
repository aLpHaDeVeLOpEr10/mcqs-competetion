<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How a room's prizes are defined (frozen into the session when the competition starts).
        Schema::table('rooms', function (Blueprint $table) {
            // fixed: tier amounts in paisa. percentage: tier shares of an explicitly defined pool.
            $table->enum('prize_mode', ['fixed', 'percentage'])->default('fixed')->after('allow_withdrawal');
            // percentage only: guaranteed = prize_pool_minor; entry_fees = net entry fees minus the platform fee.
            $table->enum('prize_pool_basis', ['guaranteed', 'entry_fees'])->nullable()->after('prize_mode');
            $table->unsignedBigInteger('prize_pool_minor')->nullable()->after('prize_pool_basis');
            // Share of net entry fees kept by the platform, in basis points (100 = 1 %).
            $table->unsignedSmallInteger('platform_fee_bp')->default(0)->after('prize_pool_minor');
            // Payouts wait for a finance approval.
            $table->boolean('prize_review_required')->default(false)->after('platform_fee_bp');
        });
        SchemaConstraints::check('rooms', 'rooms_platform_fee_range', 'platform_fee_bp <= 10000');

        Schema::table('room_prize_tiers', function (Blueprint $table) {
            // percentage mode: share of the pool per position, in basis points.
            $table->unsignedSmallInteger('percent_bp')->nullable()->after('amount_minor');
        });
        SchemaConstraints::check('room_prize_tiers', 'room_prize_tiers_percent_range', 'percent_bp IS NULL OR (percent_bp BETWEEN 1 AND 10000)');

        // Pool accounting, kept separately from the prizes themselves.
        Schema::table('prize_distributions', function (Blueprint $table) {
            $table->string('prize_mode', 20)->default('fixed')->after('currency');
            $table->bigInteger('gross_entry_fees_minor')->default(0)->after('prize_mode');
            $table->bigInteger('refunds_minor')->default(0)->after('gross_entry_fees_minor');
            $table->bigInteger('net_entry_fees_minor')->default(0)->after('refunds_minor');
            $table->bigInteger('platform_amount_minor')->default(0)->after('net_entry_fees_minor');
            $table->bigInteger('prize_pool_minor')->default(0)->after('platform_amount_minor');
            $table->bigInteger('unallocated_minor')->default(0)->after('prize_pool_minor');
            $table->foreignId('platform_ledger_transaction_id')->nullable()->unique()->after('unallocated_minor')->constrained('ledger_transactions')->restrictOnDelete();
            $table->string('hold_reason', 191)->nullable()->after('approved_at');
            $table->dateTime('paid_at', 3)->nullable()->after('hold_reason');
        });
        DB::statement("ALTER TABLE prize_distributions MODIFY status ENUM('calculated','held','approved','paid','cancelled') NOT NULL DEFAULT 'calculated'");

        // Payout lifecycle: pending → (approved) → processing → paid; held / failed / reversed / cancelled.
        DB::statement("ALTER TABLE prize_payouts MODIFY status ENUM('pending','approved','processing','paid','held','failed','reversed','cancelled','withheld') NOT NULL DEFAULT 'pending'");
        DB::table('prize_payouts')->where('status', 'withheld')->update(['status' => 'held']);
        DB::statement("ALTER TABLE prize_payouts MODIFY status ENUM('pending','approved','processing','paid','held','failed','reversed','cancelled') NOT NULL DEFAULT 'pending'");

        Schema::table('prize_payouts', function (Blueprint $table) {
            // Audit snapshot of the result the prize was awarded for.
            $table->unsignedInteger('result_version')->nullable()->after('rank');
            $table->integer('score')->nullable()->after('result_version');
            $table->unsignedInteger('correct_count')->nullable()->after('score');
            $table->unsignedBigInteger('timing_ms')->nullable()->after('correct_count');
            // The prize rule applied: positions covered, tier values, tie group, rounding.
            $table->json('rule')->nullable()->after('timing_ms');
            // amount_minor = calculated (gross) prize; net = credited amount.
            $table->unsignedBigInteger('tax_withheld_minor')->default(0)->after('amount_minor');
            $table->unsignedBigInteger('net_amount_minor')->nullable()->after('tax_withheld_minor');
            $table->foreignId('approved_by')->nullable()->after('status_reason')->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at', 3)->nullable()->after('approved_by');
            $table->dateTime('held_at', 3)->nullable()->after('approved_at');
            $table->dateTime('processing_at', 3)->nullable()->after('held_at');
            $table->dateTime('failed_at', 3)->nullable()->after('paid_at');
            $table->foreignId('reversal_ledger_transaction_id')->nullable()->unique()->after('failed_at')->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->after('reversal_ledger_transaction_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('reversed_at', 3)->nullable()->after('reversed_by');
        });

        // Completed payouts cannot be silently edited or deleted: a paid payout may only
        // become "reversed" (its amounts and ledger link unchanged), and then nothing more.
        if (SchemaConstraints::supported()) {
            DB::unprepared('DROP TRIGGER IF EXISTS `prize_payouts_settled_immutable`');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER `prize_payouts_settled_immutable` BEFORE UPDATE ON `prize_payouts` FOR EACH ROW
                BEGIN
                    IF OLD.status = 'reversed' OR (OLD.status = 'paid' AND NOT (
                        NEW.status IN ('paid', 'reversed')
                        AND NEW.amount_minor = OLD.amount_minor
                        AND NEW.net_amount_minor <=> OLD.net_amount_minor
                        AND NEW.tax_withheld_minor = OLD.tax_withheld_minor
                        AND NEW.ledger_transaction_id <=> OLD.ledger_transaction_id
                        AND NEW.room_participant_id = OLD.room_participant_id
                        AND NEW.rank = OLD.rank
                        AND NEW.paid_at <=> OLD.paid_at
                        AND (NEW.status = 'reversed' OR NEW.reversal_ledger_transaction_id IS NULL)
                    )) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'prize_payouts: settled payouts cannot be changed';
                    END IF;
                END
                SQL);
            DB::unprepared('DROP TRIGGER IF EXISTS `prize_payouts_no_delete`');
            DB::unprepared("CREATE TRIGGER `prize_payouts_no_delete` BEFORE DELETE ON `prize_payouts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'prize_payouts is append-only; delete is not allowed'");
        }

        DB::statement("ALTER TABLE ledger_transactions MODIFY type ENUM('deposit','withdrawal','entry_fee','entry_refund','prize_payout','adjustment','reversal','platform_fee') NOT NULL");
    }

    public function down(): void
    {
        if (DB::table('ledger_transactions')->where('type', 'platform_fee')->exists()
            || DB::table('prize_payouts')->whereIn('status', ['approved', 'processing', 'reversed'])->exists()) {
            throw new RuntimeException('Platform-fee ledger rows or new payout states exist; cannot roll back.');
        }

        DB::statement("ALTER TABLE ledger_transactions MODIFY type ENUM('deposit','withdrawal','entry_fee','entry_refund','prize_payout','adjustment','reversal') NOT NULL");

        DB::unprepared('DROP TRIGGER IF EXISTS `prize_payouts_settled_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `prize_payouts_no_delete`');

        Schema::table('prize_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_ledger_transaction_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['result_version', 'score', 'correct_count', 'timing_ms', 'rule', 'tax_withheld_minor', 'net_amount_minor',
                'approved_at', 'held_at', 'processing_at', 'failed_at', 'reversed_at']);
        });
        DB::statement("ALTER TABLE prize_payouts MODIFY status ENUM('pending','paid','failed','cancelled','withheld','held') NOT NULL DEFAULT 'pending'");
        DB::table('prize_payouts')->where('status', 'held')->update(['status' => 'withheld']);
        DB::statement("ALTER TABLE prize_payouts MODIFY status ENUM('pending','paid','failed','cancelled','withheld') NOT NULL DEFAULT 'pending'");

        DB::table('prize_distributions')->where('status', 'held')->update(['status' => 'calculated']);
        DB::statement("ALTER TABLE prize_distributions MODIFY status ENUM('calculated','approved','paid','cancelled') NOT NULL DEFAULT 'calculated'");
        Schema::table('prize_distributions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('platform_ledger_transaction_id');
            $table->dropColumn(['prize_mode', 'gross_entry_fees_minor', 'refunds_minor', 'net_entry_fees_minor', 'platform_amount_minor',
                'prize_pool_minor', 'unallocated_minor', 'hold_reason', 'paid_at']);
        });

        SchemaConstraints::dropCheck('room_prize_tiers', 'room_prize_tiers_percent_range');
        Schema::table('room_prize_tiers', fn (Blueprint $table) => $table->dropColumn('percent_bp'));
        SchemaConstraints::dropCheck('rooms', 'rooms_platform_fee_range');
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn(['prize_mode', 'prize_pool_basis', 'prize_pool_minor', 'platform_fee_bp', 'prize_review_required']));
    }
};
