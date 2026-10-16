<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->foreignId('quiz_paper_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['scheduled', 'fill']);
            $table->enum('status', ['draft', 'scheduled', 'open', 'full', 'in_progress', 'completed', 'cancelled', 'refunded'])
                ->default('draft');

            // Money in minor units (paisa). 0 = free room.
            $table->unsignedBigInteger('entry_fee_minor')->default(0);
            $table->char('currency', 3)->default('PKR');

            $table->unsignedInteger('min_participants')->default(2);
            $table->unsignedInteger('max_participants');
            // Maintained under row lock by the entry service; the CHECK below is the last line of defence.
            $table->unsignedInteger('participants_count')->default(0);

            $table->dateTime('registration_opens_at')->nullable();
            $table->dateTime('registration_closes_at')->nullable();
            $table->dateTime('scheduled_start_at')->nullable();
            $table->unsignedSmallInteger('countdown_seconds')->default(10);
            $table->unsignedSmallInteger('question_gap_seconds')->default(3);

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->datetimes();

            $table->index(['status', 'scheduled_start_at']);
            $table->index(['type', 'status']);
        });
        SchemaConstraints::check('rooms', 'rooms_capacity_valid',
            'min_participants >= 1 AND max_participants >= min_participants AND participants_count <= max_participants');
        SchemaConstraints::check('rooms', 'rooms_registration_window_valid',
            'registration_opens_at IS NULL OR registration_closes_at IS NULL OR registration_closes_at > registration_opens_at');

        Schema::create('room_prize_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('rank_from');
            $table->unsignedInteger('rank_to');
            $table->unsignedBigInteger('amount_minor');
            $table->datetimes();

            $table->unique(['room_id', 'rank_from']);
        });
        SchemaConstraints::check('room_prize_tiers', 'room_prize_tiers_rank_range', 'rank_from >= 1 AND rank_to >= rank_from');
    }

    public function down(): void
    {
        Schema::dropIfExists('room_prize_tiers');
        Schema::dropIfExists('rooms');
    }
};
