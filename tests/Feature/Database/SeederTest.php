<?php

namespace Tests\Feature\Database;

use App\Enums\WalletType;
use App\Models\Room;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\SystemWalletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_seed_creates_no_money_or_prizes(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['ledger_transactions', 'ledger_entries', 'payment_transactions', 'payment_refunds', 'prize_distributions', 'prize_payouts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        $this->assertSame(0, (int) Wallet::sum('balance_minor'));
        $this->assertSame(0, Room::where('entry_fee_minor', '>', 0)->count());
        $this->assertSame(0, (int) DB::table('room_prize_tiers')->sum('amount_minor'));
    }

    public function test_development_seed_builds_a_consistent_dataset(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(User::where('email', DevelopmentSeeder::ADMIN_EMAIL)->first()->isStaff());
        $this->assertSame(User::count(), Wallet::where('type', WalletType::User)->count());
        $this->assertSame(count(Wallet::systemCodes()), Wallet::where('type', WalletType::System)->count());

        Room::all()->each(function (Room $room) {
            $this->assertSame($room->participants()->count(), $room->participants_count, $room->name);
        });
    }

    public function test_seeders_are_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $users = User::count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($users, User::count());
        $this->assertSame(count(Wallet::systemCodes()), Wallet::where('type', WalletType::System)->count());
    }

    public function test_development_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must never run in production');

        // Called directly: `db:seed` would stop at its own production confirmation prompt first.
        $this->app->make(DevelopmentSeeder::class)->run();
    }

    public function test_system_wallet_seeder_is_safe_without_development_data(): void
    {
        $this->seed(SystemWalletSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertSame(count(Wallet::systemCodes()), Wallet::count());
    }
}
