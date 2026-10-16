<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participant_results', function (Blueprint $table) {
            // Milliseconds from the competition start to the last counted correct answer (tie-break 3).
            $table->unsignedBigInteger('last_correct_elapsed_ms')->nullable()->after('correct_response_time_ms');
            $table->boolean('is_winner')->default(false)->after('rank');
            $table->boolean('prize_eligible')->default(false)->after('is_winner');
            $table->unsignedBigInteger('prize_amount_minor')->default(0)->after('prize_eligible');
            $table->string('excluded_reason', 255)->nullable()->after('prize_amount_minor');
            $table->unsignedInteger('result_version')->default(1)->after('excluded_reason');
        });

        // Engine/results bookkeeping, not snapshot content (the content trigger allows these).
        Schema::table('session_questions', function (Blueprint $table) {
            $table->dateTime('voided_at', 3)->nullable()->after('closed_at');
            $table->string('void_reason', 255)->nullable()->after('voided_at');
        });

        // Every calculation (initial and each correction) is kept, append-only.
        Schema::create('result_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_session_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('scoring_policy', 50);
            $table->string('tie_break_policy', 50);
            $table->string('reason', 255);
            // sha256 of the canonical inputs, so a recalculation can prove it used the same data.
            $table->char('inputs_hash', 64);
            $table->json('inputs');
            $table->json('results');
            $table->json('prizes');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at', 3);

            $table->unique(['competition_session_id', 'version']);
        });
        SchemaConstraints::appendOnly('result_versions');

        Schema::create('result_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_participant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('raised_by')->constrained('users')->restrictOnDelete();
            $table->text('message');
            $table->enum('status', ['open', 'rejected', 'resolved'])->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('resolved_at', 3)->nullable();
            $table->unsignedInteger('result_version_before')->nullable();
            $table->unsignedInteger('result_version_after')->nullable();
            $table->datetimes();

            $table->index(['status', 'created_at']);
            $table->index(['competition_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_disputes');
        SchemaConstraints::dropAppendOnly('result_versions');
        Schema::dropIfExists('result_versions');
        Schema::table('session_questions', fn (Blueprint $table) => $table->dropColumn(['voided_at', 'void_reason']));
        Schema::table('participant_results', fn (Blueprint $table) => $table->dropColumn([
            'last_correct_elapsed_ms', 'is_winner', 'prize_eligible', 'prize_amount_minor', 'excluded_reason', 'result_version',
        ]));
    }
};
