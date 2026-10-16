<?php

use App\Services\Payments\Gateways\DisabledPaymentGateway;
use App\Services\Payments\Gateways\FakePaymentGateway;
use App\Services\Withdrawals\Gateways\DisabledPayoutGateway;
use App\Services\Withdrawals\Gateways\FakePayoutGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | All monetary values are stored as unsigned integers in the currency's
    | minor unit (paisa for PKR). Never use floats for money.
    |
    */

    'currency' => [
        'code' => env('COMPETITION_CURRENCY', 'PKR'),
        'minor_units_per_major' => (int) env('COMPETITION_CURRENCY_MINOR_UNITS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored and compared in UTC (config('app.timezone')).
    | This timezone is used only when rendering times to users.
    |
    */

    'display_timezone' => env('COMPETITION_DISPLAY_TIMEZONE', 'Asia/Karachi'),

    /*
    |--------------------------------------------------------------------------
    | Paid Entries (real money)
    |--------------------------------------------------------------------------
    |
    | Disabled until legal/compliance requirements for Pakistan are confirmed.
    | While disabled, only free (zero-fee) rooms may be created or joined.
    |
    */

    'paid_entries' => [
        'enabled' => (bool) env('COMPETITION_PAID_ENTRIES_ENABLED', false),
    ],

    // Refund policy for captured entry fees (see App\Services\Payments\EntryRefundPolicy).
    // Cancellations before valid completion are always refunded in full.
    'entry_fees' => [
        'refund_on_withdrawal' => (bool) env('ENTRY_FEE_REFUND_ON_WITHDRAWAL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway
    |--------------------------------------------------------------------------
    |
    | Driver name resolved behind a PaymentGateway interface (later phase).
    | "disabled" refuses every payment operation; "fake" is test-mode only.
    |
    */

    'payments' => [
        'gateway' => env('PAYMENT_GATEWAY', 'disabled'),

        // Real-money deposits. Off until the provider and jurisdiction permit this
        // competition model. Also requires a working (non-"disabled") gateway.
        'deposits_enabled' => (bool) env('DEPOSITS_ENABLED', false),

        'deposit' => [
            'min_minor' => (int) env('DEPOSIT_MIN_MINOR', 10000),           // Rs 100
            'max_minor' => (int) env('DEPOSIT_MAX_MINOR', 2500000),         // Rs 25,000
            'daily_max_minor' => (int) env('DEPOSIT_DAILY_MAX_MINOR', 5000000), // Rs 50,000 per 24 h
            'max_open' => 3,                                                // unfinished deposits per player
            'expires_minutes' => (int) env('DEPOSIT_EXPIRES_MINUTES', 30),
        ],

        // Driver classes (App\Contracts\Payments\PaymentGateway). A real provider
        // is added here once its official integration documents are available.
        'drivers' => [
            'disabled' => DisabledPaymentGateway::class,
            'fake' => FakePaymentGateway::class,
        ],

        // Simulated provider for local development and automated tests ONLY. It is
        // refused unless APP_ENV is local/testing AND FAKE_GATEWAY_ENABLED=true AND
        // a secret is set. It never runs in production, whatever the settings.
        'fake' => [
            'enabled' => (bool) env('FAKE_GATEWAY_ENABLED', false),
            'secret' => env('FAKE_GATEWAY_SECRET'),
            'merchant_id' => env('FAKE_GATEWAY_MERCHANT_ID', 'FAKE-MERCHANT'),
            'cache_store' => env('FAKE_GATEWAY_CACHE_STORE'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Contact
    |--------------------------------------------------------------------------
    |
    | Where public contact-form messages are sent. When empty, the contact
    | form is shown as unavailable rather than pretending to send.
    |
    */

    'contact' => [
        'recipient' => env('CONTACT_RECIPIENT_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Question Bank
    |--------------------------------------------------------------------------
    |
    | Time limits must stay within the database CHECK range (5–600 s).
    | Images are re-encoded on upload and stored on the private disk.
    |
    */

    'questions' => [
        'min_options' => 2,
        'max_options' => 6,
        'default_options' => 4,
        'min_time_limit' => 5,
        'max_time_limit' => 600,
        'image_max_kb' => 2048,
        'image_max_dimension' => 4000,
        'image_disk' => 'local',
        'image_directory' => 'question-images',
        'import_max_rows' => 500,
        'import_max_kb' => 1024,
    ],

    'rooms' => [
        // How long a seat is held while an entry payment is pending.
        'reservation_minutes' => (int) env('ROOM_RESERVATION_MINUTES', 10),
        // Scheduled start / registration deadline must be at least this far ahead when publishing.
        'min_publish_lead_minutes' => 5,
        'max_capacity' => 10000,
        'max_prize_tiers' => 20,
    ],

    'engine' => [
        // Answers are accepted while server receipt time <= closes_at + grace,
        // absorbing network latency for answers sent just before the deadline.
        'answer_grace_ms' => (int) env('COMPETITION_ANSWER_GRACE_MS', 500),
        // How often browsers re-read state when the WebSocket is down / connected.
        'poll_seconds_disconnected' => 3,
        'poll_seconds_connected' => 15,
    ],

    'results' => [
        // Publish results as soon as they are finalized (otherwise an admin publishes them).
        'auto_publish' => (bool) env('RESULTS_AUTO_PUBLISH', true),
        // Players may dispute published results for this many days.
        'dispute_window_days' => 7,
    ],

    'prizes' => [
        // Minimum correct answers for a player to be prize-eligible.
        'min_correct_answers' => 1,
        // Entitlements are always recorded; while false they are marked "withheld"
        // (payment and compliance are handled in the wallet phase).
        'payouts_enabled' => (bool) env('PRIZE_PAYOUTS_ENABLED', false),
        // Hold every payout of a competition while any result dispute is open.
        'hold_while_disputed' => (bool) env('PRIZE_HOLD_WHILE_DISPUTED', true),
        // Withholding deducted from each prize, in basis points (100 = 1 %). 0 until a legal
        // requirement is confirmed; set by finance, never guessed. Rounded down per payout.
        'tax_withholding_bp' => (int) env('PRIZE_TAX_WITHHOLDING_BP', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Withdrawals
    |--------------------------------------------------------------------------
    |
    | Requests can be accepted and reviewed only when WITHDRAWALS_ENABLED=true.
    | Money leaves the platform only through a configured payout provider
    | (WITHDRAWAL_PAYOUT_GATEWAY); "disabled" never pays anything out.
    |
    */

    'withdrawals' => [
        'enabled' => (bool) env('WITHDRAWALS_ENABLED', false),
        'gateway' => env('WITHDRAWAL_PAYOUT_GATEWAY', 'disabled'),
        'min_minor' => (int) env('WITHDRAWAL_MIN_MINOR', 50000),           // Rs 500
        'max_minor' => (int) env('WITHDRAWAL_MAX_MINOR', 2500000),         // Rs 25,000
        'daily_max_minor' => (int) env('WITHDRAWAL_DAILY_MAX_MINOR', 5000000), // Rs 50,000 per 24 h
        'max_pending' => (int) env('WITHDRAWAL_MAX_PENDING', 1),
        // Disclosed fee per withdrawal: flat + basis points of the amount (both default 0).
        'fee_flat_minor' => (int) env('WITHDRAWAL_FEE_FLAT_MINOR', 0),
        'fee_bp' => (int) env('WITHDRAWAL_FEE_BP', 0),
        // A newly added payout destination cannot be used for this many hours.
        'destination_cooldown_hours' => (int) env('WITHDRAWAL_DESTINATION_COOLDOWN_HOURS', 24),
        'max_destinations' => 3,
        // Approved destination providers (codes are stored; names are shown).
        'providers' => [
            'bank_account' => [
                'HBL' => 'Habib Bank', 'UBL' => 'United Bank', 'MCB' => 'MCB Bank', 'ABL' => 'Allied Bank',
                'NBP' => 'National Bank of Pakistan', 'MEEZAN' => 'Meezan Bank', 'BAFL' => 'Bank Alfalah',
            ],
            'mobile_wallet' => ['JAZZCASH' => 'JazzCash', 'EASYPAISA' => 'Easypaisa'],
        ],
        'drivers' => [
            'disabled' => DisabledPayoutGateway::class,
            'fake' => FakePayoutGateway::class,
        ],
        // Simulated payout provider: local/testing only, same safeguards as the deposit fake.
        'fake' => [
            'enabled' => (bool) env('FAKE_PAYOUT_ENABLED', false),
            'secret' => env('FAKE_PAYOUT_SECRET'),
            'cache_store' => env('FAKE_PAYOUT_CACHE_STORE'),
        ],
    ],

    'wallet' => [
        // Administrative balance corrections (audited ledger adjustments). Off until
        // finance procedures are agreed; there is never a direct "edit balance".
        'adjustments_enabled' => (bool) env('WALLET_ADJUSTMENTS_ENABLED', false),
        // Largest single adjustment, in minor units (Rs 50,000).
        'max_adjustment_minor' => (int) env('WALLET_MAX_ADJUSTMENT_MINOR', 5000000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | In-app (database) notifications are always on. Email is queued; real-time
    | broadcasts go to the user's private channel and carry only an id, a title
    | and a link (never amounts, balances or answers). Extra channels (SMS,
    | push) are listed in extra_channels once a driver exists; see
    | docs/NOTIFICATIONS.md.
    |
    */

    'notifications' => [
        'mail' => (bool) env('NOTIFICATIONS_MAIL', true),
        'broadcast' => (bool) env('NOTIFICATIONS_BROADCAST', true),
        'extra_channels' => [],
        // Reminders before a competition starts, in minutes (each sent at most once per player).
        'reminder_minutes' => [15, 5],
    ],

    // Retention (docs/REPORTS.md). Audit logs and financial records are kept permanently.
    'retention' => [
        'read_notifications_days' => (int) env('RETENTION_READ_NOTIFICATIONS_DAYS', 180),
    ],

    'papers' => [
        'min_questions' => 5,
        'max_questions' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Player Accounts
    |--------------------------------------------------------------------------
    |
    | email_verification: when true, players must verify their email address
    | before entering competitions or transacting. Requires working mail
    | delivery (see docs/DEVELOPMENT.md). Signing in never requires it.
    |
    */

    'accounts' => [
        'email_verification' => (bool) env('ACCOUNT_EMAIL_VERIFICATION', true),
        'email_change_link_minutes' => 60,
        'reserved_display_names' => [
            'admin', 'administrator', 'moderator', 'mod', 'support', 'staff', 'system',
            'root', 'official', 'help', 'security', 'mcq', 'null', 'undefined',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | rapid_answer_ms: accepted answers faster than this (from question open)
    | are recorded as a low-severity risk SIGNAL for review, never rejected or
    | penalized (fast, honest players exist).
    |
    | headers: Content-Security-Policy (enforce | report-only | off), with
    | extra form-action origins for a real payment provider's hosted checkout
    | (comma separated). HSTS is sent only in production over HTTPS.
    |
    */

    'security' => [
        'rapid_answer_ms' => (int) env('SECURITY_RAPID_ANSWER_MS', 400),
        'headers' => [
            'enabled' => (bool) env('SECURITY_HEADERS', true),
            'csp' => env('SECURITY_CSP', 'enforce'),
            'csp_form_actions' => array_values(array_filter(array_map('trim', explode(',', (string) env('SECURITY_CSP_FORM_ACTIONS', ''))))),
            'csp_connect' => array_values(array_filter(array_map('trim', explode(',', (string) env('SECURITY_CSP_CONNECT', ''))))),
            'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues and operations (see docs/OPERATIONS.md)
    |--------------------------------------------------------------------------
    |
    | engine: AdvanceCompetition wake-ups (short, latency-sensitive; give it
    | its own worker or list it first). default: notifications, prize
    | payments. long_connection: exports and announcements (900 s timeout).
    | monitor_max: queue:monitor warns when a queue holds more jobs than this.
    | tick_stale_seconds: ops:status reports the scheduler as down when the
    | competitions:tick heartbeat is older than this.
    |
    */

    'queues' => [
        'engine' => env('QUEUE_ENGINE', 'competition'),
        'default' => env('QUEUE_DEFAULT', 'default'),
        'long_connection' => env('QUEUE_LONG_CONNECTION', 'database-long'),
        'long' => 'long',
        'monitor_max' => (int) env('QUEUE_MONITOR_MAX', 200),
        'tick_stale_seconds' => (int) env('OPS_TICK_STALE_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache (see docs/PERFORMANCE.md)
    |--------------------------------------------------------------------------
    |
    | dashboard_seconds: how long the admin dashboard's all-time totals are
    | reused (0 = always live). Display only; never used for decisions.
    |
    */

    'cache' => [
        'dashboard_seconds' => (int) env('CACHE_DASHBOARD_SECONDS', 60),
    ],

];
