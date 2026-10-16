<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FoundationConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_entries_are_disabled_by_default(): void
    {
        $this->assertFalse(config('competition.paid_entries.enabled'));
    }

    public function test_payment_gateway_defaults_to_disabled(): void
    {
        $this->assertSame('disabled', config('competition.payments.gateway'));
    }

    public function test_currency_is_pkr_in_minor_units(): void
    {
        $this->assertSame('PKR', config('competition.currency.code'));
        $this->assertSame(100, config('competition.currency.minor_units_per_major'));
    }

    public function test_application_stores_time_in_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Karachi', config('competition.display_timezone'));
    }

    public function test_tests_run_against_mariadb_test_database(): void
    {
        $this->assertSame('mariadb', DB::connection()->getDriverName());
        $this->assertSame('mcq_competition_test', DB::connection()->getDatabaseName());
    }
}
