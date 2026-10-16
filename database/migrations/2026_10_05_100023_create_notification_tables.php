<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-category channel choices, e.g. {"competition": {"database": true, "mail": false}}.
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('status_reason');
        });

        // "Send once" ledger: a notification whose key is already here is not sent again
        // (competition reminders, and any other notification that must not repeat).
        Schema::create('notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('created_at', 3);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->enum('audience', ['everyone', 'players', 'staff', 'room']);
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->dateTime('sent_at', 3)->nullable();
            $table->datetimes();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('notification_dispatches');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notification_preferences'));
    }
};
