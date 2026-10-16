<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Risk signals for ADMINISTRATIVE REVIEW. A flag is never proof of fraud or
     * cheating and never triggers an automatic penalty: it is counted while
     * "watching", and becomes "open" for review once its threshold is reached.
     */
    public function up(): void
    {
        Schema::create('risk_flags', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60);
            $table->enum('severity', ['low', 'medium', 'high']);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->nullableMorphs('subject');
            $table->unsignedInteger('occurrences')->default(1);
            $table->unsignedInteger('threshold')->default(1);
            $table->json('details')->nullable();
            $table->enum('status', ['watching', 'open', 'reviewed', 'dismissed'])->default('watching');
            // One row per type / user / subject / day, so repeated signals are counted, not duplicated.
            $table->string('dedupe_key', 191)->unique();
            $table->dateTime('first_seen_at', 3);
            $table->dateTime('last_seen_at', 3);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at', 3)->nullable();
            $table->string('review_note', 500)->nullable();
            $table->datetimes();

            $table->index(['status', 'severity', 'last_seen_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_flags');
    }
};
