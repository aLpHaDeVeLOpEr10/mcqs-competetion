<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\CompetitionHistoryController;
use App\Http\Controllers\Account\DepositController;
use App\Http\Controllers\Account\EmailController;
use App\Http\Controllers\Account\NotificationController;
use App\Http\Controllers\Account\PayoutDestinationController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\Account\WalletController;
use App\Http\Controllers\Account\WithdrawalController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CompetitionController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\FakeGatewayController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\PaymentCallbackController;
use App\Http\Controllers\PlayController;
use App\Http\Controllers\RoomEntryController;
use App\Http\Controllers\WithdrawalCallbackController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/competitions', [CompetitionController::class, 'index'])->name('competitions.index');
Route::get('/competitions/{room}', [CompetitionController::class, 'show'])->whereNumber('room')->name('competitions.show');

Route::middleware(['auth', 'auth.session', 'throttle:room-entry'])->group(function () {
    Route::post('/competitions/{room}/join', [RoomEntryController::class, 'join'])->whereNumber('room')->name('competitions.join');
    Route::post('/competitions/{room}/withdraw', [RoomEntryController::class, 'withdraw'])->whereNumber('room')->name('competitions.withdraw');
});

// Provider-to-server payment notifications. No CSRF token (the provider cannot send one);
// authenticity comes from the driver's signature check and a server-side status query.
Route::post('/withdrawals/callback/{gateway}', WithdrawalCallbackController::class)
    ->where('gateway', '[a-z0-9_]{1,32}')
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->middleware('throttle:provider-callbacks')
    ->name('withdrawals.callback');

Route::post('/payments/callback/{gateway}', PaymentCallbackController::class)
    ->where('gateway', '[a-z0-9_]{1,32}')
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->middleware('throttle:provider-callbacks')
    ->name('payments.callback');

// Simulated provider checkout: registered only in local/testing; the controller also
// refuses unless the fake gateway is explicitly enabled (PaymentGatewayManager::fakeAllowed).
if (app()->environment(['local', 'testing'])) {
    Route::middleware(['auth', 'auth.session'])->prefix('fake-gateway')->name('fake-gateway.')->group(function () {
        Route::get('/{providerReference}', [FakeGatewayController::class, 'show'])->name('show');
        Route::post('/{providerReference}', [FakeGatewayController::class, 'complete'])->name('complete');
    });
}

// Live competition (seated participants only; checked in PlayController).
Route::middleware(['auth', 'auth.session'])->prefix('play')->name('play.')->group(function () {
    Route::get('/{room}', [PlayController::class, 'show'])->whereNumber('room')->name('show');
    Route::get('/{room}/state', [PlayController::class, 'state'])->whereNumber('room')->middleware('throttle:play-state')->name('state');
    Route::post('/{room}/answers', [PlayController::class, 'answer'])->whereNumber('room')->middleware('throttle:answers')->name('answer');
    Route::get('/questions/{question}/image', [PlayController::class, 'image'])->whereNumber('question')->name('image');
});

Route::get('/competitions/{room}/results', [CompetitionController::class, 'results'])->whereNumber('room')->name('competitions.results');
Route::post('/competitions/{room}/disputes', [CompetitionController::class, 'dispute'])->whereNumber('room')
    ->middleware(['auth', 'auth.session', 'throttle:disputes'])->name('competitions.disputes.store');

Route::get('/leaderboard', LeaderboardController::class)->name('leaderboard');

Route::view('/how-to-play', 'pages.how-to-play')->name('how-to-play');
Route::view('/terms', 'pages.terms')->name('terms');
Route::view('/privacy', 'pages.privacy')->name('privacy');
Route::view('/responsible-play', 'pages.responsible-play')->name('responsible-play');

Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:contact')->name('contact.store');

// Authentication
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:register');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    // Per email+IP limiting lives in LoginRequest; this caps attempts across many emails from one IP.
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:verification'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:verification')->name('verification.send');
});

// Admin sign-in. The admin area has its own guard and session cookie
// (UseAdminSession), separate from the player's site sign-in.
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [Admin\AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('/login', [Admin\AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    });
    Route::post('/logout', [Admin\AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');
});

// Administration. Every route checks a permission on the server; navigation
// visibility is a convenience only.
Route::middleware(['auth', 'auth.session', 'can:admin.access', 'throttle:writes'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\DashboardController::class)->name('dashboard');

    Route::middleware('can:users.view')->group(function () {
        Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [Admin\UserController::class, 'show'])->name('users.show');
    });
    Route::patch('/users/{user}/status', [Admin\UserController::class, 'updateStatus'])
        ->middleware(['can:users.manage_status', 'throttle:account-sensitive'])->name('users.status');
    Route::patch('/users/{user}/role', [Admin\UserController::class, 'updateRole'])
        ->middleware(['can:users.manage_roles', 'throttle:account-sensitive'])->name('users.role');

    Route::middleware('can:content.view')->group(function () {
        Route::get('/categories', [Admin\ContentController::class, 'categories'])->name('categories.index');
        Route::get('/questions', [Admin\ContentController::class, 'questions'])->name('questions.index');
        Route::get('/questions/{question}', [Admin\ContentController::class, 'question'])->whereNumber('question')->name('questions.show');
        Route::get('/questions/{question}/image', [Admin\QuestionController::class, 'image'])->whereNumber('question')->name('questions.image');
        Route::get('/papers', [Admin\ContentController::class, 'papers'])->name('papers.index');
        Route::get('/papers/{paper}', [Admin\ContentController::class, 'paper'])->whereNumber('paper')->name('papers.show');
        Route::get('/papers/{paper}/preview', [Admin\PaperController::class, 'preview'])->whereNumber('paper')->name('papers.preview');
    });

    // Question bank and paper builder writes.
    Route::middleware('can:content.manage')->group(function () {
        Route::post('/categories', [Admin\CategoryController::class, 'store'])->name('categories.store');
        Route::get('/categories/{category}/edit', [Admin\CategoryController::class, 'edit'])->whereNumber('category')->name('categories.edit');
        Route::put('/categories/{category}', [Admin\CategoryController::class, 'update'])->whereNumber('category')->name('categories.update');

        Route::get('/questions/create', [Admin\QuestionController::class, 'create'])->name('questions.create');
        Route::post('/questions', [Admin\QuestionController::class, 'store'])->name('questions.store');
        Route::get('/questions/{question}/edit', [Admin\QuestionController::class, 'edit'])->whereNumber('question')->name('questions.edit');
        Route::put('/questions/{question}', [Admin\QuestionController::class, 'update'])->whereNumber('question')->name('questions.update');
        Route::patch('/questions/{question}/archive', [Admin\QuestionController::class, 'archive'])->whereNumber('question')->name('questions.archive');
        Route::patch('/questions/{question}/restore', [Admin\QuestionController::class, 'restore'])->whereNumber('question')->name('questions.restore');

        Route::get('/questions/import', [Admin\QuestionImportController::class, 'create'])->name('questions.import');
        Route::post('/questions/import', [Admin\QuestionImportController::class, 'store'])->middleware('throttle:account-sensitive')->name('questions.import.store');
        Route::get('/questions/import/sample', [Admin\QuestionImportController::class, 'sample'])->name('questions.import.sample');
        Route::get('/questions/import/{token}', [Admin\QuestionImportController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->name('questions.import.show');
        Route::post('/questions/import/{token}', [Admin\QuestionImportController::class, 'commit'])->where('token', '[A-Za-z0-9]{40}')->name('questions.import.commit');

        Route::get('/papers/create', [Admin\PaperController::class, 'create'])->name('papers.create');
        Route::post('/papers', [Admin\PaperController::class, 'store'])->name('papers.store');
        Route::whereNumber(['paper', 'item'])->scopeBindings()->group(function () {
            Route::get('/papers/{paper}/edit', [Admin\PaperController::class, 'edit'])->name('papers.edit');
            Route::put('/papers/{paper}', [Admin\PaperController::class, 'update'])->name('papers.update');
            Route::post('/papers/{paper}/items', [Admin\PaperController::class, 'addQuestions'])->name('papers.items.store');
            Route::post('/papers/{paper}/items/random', [Admin\PaperController::class, 'addRandomQuestions'])->name('papers.items.random');
            Route::patch('/papers/{paper}/items/{item}', [Admin\PaperController::class, 'updateItem'])->name('papers.items.update');
            Route::delete('/papers/{paper}/items/{item}', [Admin\PaperController::class, 'removeItem'])->name('papers.items.destroy');
            Route::patch('/papers/{paper}/items/{item}/move', [Admin\PaperController::class, 'moveItem'])->name('papers.items.move');
            Route::patch('/papers/{paper}/publish', [Admin\PaperController::class, 'publish'])->name('papers.publish');
            Route::patch('/papers/{paper}/unpublish', [Admin\PaperController::class, 'unpublish'])->name('papers.unpublish');
            Route::patch('/papers/{paper}/archive', [Admin\PaperController::class, 'archive'])->name('papers.archive');
            Route::patch('/papers/{paper}/restore', [Admin\PaperController::class, 'restore'])->name('papers.restore');
            Route::post('/papers/{paper}/duplicate', [Admin\PaperController::class, 'duplicate'])->name('papers.duplicate');
        });
    });

    Route::middleware('can:rooms.view')->group(function () {
        Route::get('/rooms', [Admin\RoomController::class, 'index'])->name('rooms.index');
        Route::get('/rooms/{room}', [Admin\RoomController::class, 'show'])->whereNumber('room')->name('rooms.show');
    });

    Route::middleware('can:rooms.manage')->group(function () {
        Route::get('/rooms/create', [Admin\RoomController::class, 'create'])->name('rooms.create');
        Route::post('/rooms', [Admin\RoomController::class, 'store'])->name('rooms.store');
        Route::get('/rooms/{room}/edit', [Admin\RoomController::class, 'edit'])->whereNumber('room')->name('rooms.edit');
        Route::put('/rooms/{room}', [Admin\RoomController::class, 'update'])->whereNumber('room')->name('rooms.update');
        Route::patch('/rooms/{room}/publish', [Admin\RoomController::class, 'publish'])->whereNumber('room')->name('rooms.publish');
        Route::patch('/rooms/{room}/cancel', [Admin\RoomController::class, 'cancel'])->whereNumber('room')->middleware('throttle:account-sensitive')->name('rooms.cancel');
    });

    Route::get('/results', [Admin\ResultController::class, 'index'])->middleware('can:results.view')->name('results.index');
    Route::get('/results/{session}', [Admin\ResultController::class, 'show'])->whereNumber('session')->middleware('can:results.view')->name('results.show');

    Route::middleware(['can:results.manage', 'throttle:account-sensitive'])->group(function () {
        Route::post('/results/{session}/finalize', [Admin\ResultController::class, 'finalize'])->whereNumber('session')->name('results.finalize');
        Route::post('/results/{session}/publish', [Admin\ResultController::class, 'publish'])->whereNumber('session')->name('results.publish');
        Route::post('/results/{session}/void-question', [Admin\ResultController::class, 'voidQuestion'])->whereNumber('session')->name('results.void');
        Route::post('/results/{session}/disqualify', [Admin\ResultController::class, 'disqualify'])->whereNumber('session')->name('results.disqualify');
        Route::post('/disputes/{dispute}/reject', [Admin\ResultController::class, 'rejectDispute'])->whereNumber('dispute')->name('disputes.reject');
    });

    Route::middleware('can:finance.view')->group(function () {
        Route::get('/payments', [Admin\FinanceController::class, 'payments'])->name('payments.index');
        Route::get('/payments/{payment}', [Admin\FinanceController::class, 'payment'])->whereNumber('payment')->name('payments.show');
        Route::get('/ledger', [Admin\FinanceController::class, 'ledger'])->name('ledger.index');
        Route::get('/ledger/{transaction}', [Admin\FinanceController::class, 'ledgerTransaction'])->whereNumber('transaction')->name('ledger.show');
        Route::get('/wallets', [Admin\WalletController::class, 'index'])->name('wallets.index');
        Route::get('/wallets/{wallet}', [Admin\WalletController::class, 'show'])->whereNumber('wallet')->name('wallets.show');
    });

    // Reports. Financial reports additionally need finance.view; exports reports.export.
    Route::middleware('can:reports.view')->group(function () {
        Route::get('/reports', [Admin\ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/competitions', [Admin\ReportController::class, 'competitions'])->name('reports.competitions');
        Route::get('/reports/finance/{report?}', [Admin\ReportController::class, 'finance'])->where('report', '[a-z_]+')
            ->middleware('can:finance.view')->name('reports.finance');
    });
    Route::get('/users/{user}/report', [Admin\ReportController::class, 'user'])->middleware('can:users.view')->name('users.report');
    Route::middleware('can:reports.export')->group(function () {
        Route::post('/reports/export', [Admin\ReportController::class, 'export'])->middleware('throttle:account-sensitive')->name('reports.export');
        Route::get('/exports', [Admin\ReportController::class, 'exports'])->name('exports.index');
        Route::get('/exports/{export}/download', [Admin\ReportController::class, 'download'])->whereNumber('export')->name('exports.download');
    });

    // Withdrawals: viewing needs withdrawals.review; decisions and payouts withdrawals.approve.
    Route::middleware('can:withdrawals.review')->group(function () {
        Route::get('/withdrawals', [Admin\WithdrawalController::class, 'index'])->name('withdrawals.index');
        Route::get('/withdrawals/{withdrawal}', [Admin\WithdrawalController::class, 'show'])->whereNumber('withdrawal')->name('withdrawals.show');
        Route::post('/withdrawals/{withdrawal}/review', [Admin\WithdrawalController::class, 'startReview'])->whereNumber('withdrawal')
            ->middleware('throttle:account-sensitive')->name('withdrawals.review');
        Route::post('/withdrawals/{withdrawal}/refresh', [Admin\WithdrawalController::class, 'refresh'])->whereNumber('withdrawal')
            ->middleware('throttle:account-sensitive')->name('withdrawals.refresh');
    });
    Route::middleware(['can:withdrawals.approve', 'throttle:account-sensitive'])->group(function () {
        foreach (['approve', 'reject', 'initiate'] as $action) {
            Route::post("/withdrawals/{withdrawal}/{$action}", [Admin\WithdrawalController::class, $action])->whereNumber('withdrawal')->name("withdrawals.{$action}");
        }
    });

    Route::middleware('can:announcements.manage')->group(function () {
        Route::get('/announcements', [Admin\AnnouncementController::class, 'index'])->name('announcements.index');
        Route::post('/announcements', [Admin\AnnouncementController::class, 'store'])->middleware('throttle:account-sensitive')->name('announcements.store');
    });

    Route::middleware('can:finance.view')->group(function () {
        Route::get('/payouts', [Admin\PayoutController::class, 'index'])->name('payouts.index');
        Route::get('/payouts/competitions/{session}', [Admin\PayoutController::class, 'show'])->whereNumber('session')->name('payouts.show');
    });

    // Prize payout actions: approve, run, hold, release, retry, reverse (all audited, never edits amounts).
    Route::middleware(['can:prizes.manage', 'throttle:account-sensitive'])->group(function () {
        Route::post('/payouts/competitions/{session}/approve', [Admin\PayoutController::class, 'approve'])->whereNumber('session')->name('payouts.approve');
        Route::post('/payouts/competitions/{session}/distribute', [Admin\PayoutController::class, 'distribute'])->whereNumber('session')->name('payouts.distribute');
        foreach (['hold', 'release', 'retry', 'reverse'] as $action) {
            Route::post("/payouts/{payout}/{$action}", [Admin\PayoutController::class, $action])->whereNumber('payout')->name("payouts.{$action}");
        }
    });

    // The only finance write: an audited ledger adjustment (balances are never edited).
    Route::post('/wallets/{wallet}/adjustments', [Admin\WalletController::class, 'adjust'])->whereNumber('wallet')
        ->middleware(['can:finance.adjust', 'throttle:account-sensitive'])->name('wallets.adjust');

    // Risk review: signals only; reviewing never changes an account.
    Route::middleware('can:risk.review')->group(function () {
        Route::get('/risk', [Admin\RiskController::class, 'index'])->name('risk.index');
        Route::post('/risk/{flag}/review', [Admin\RiskController::class, 'review'])->whereNumber('flag')
            ->middleware('throttle:account-sensitive')->name('risk.review');
    });

    Route::middleware('can:audit.view')->group(function () {
        Route::get('/audit', [Admin\AuditLogController::class, 'index'])->name('audit.index');
        Route::get('/audit/{log}', [Admin\AuditLogController::class, 'show'])->name('audit.show');
    });

    Route::get('/settings', Admin\SettingsController::class)->middleware('can:settings.view')->name('settings');
});

// Player account area. auth.session invalidates sessions whose password hash is stale.
Route::middleware(['auth', 'auth.session', 'throttle:writes'])->prefix('account')->name('account.')->group(function () {
    Route::get('/', [AccountController::class, 'show'])->name('show');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::post('/email', [EmailController::class, 'store'])->middleware('throttle:account-sensitive')->name('email.update');
    Route::delete('/email/pending', [EmailController::class, 'cancel'])->name('email.cancel');
    Route::get('/email/confirm/{hash}', [EmailController::class, 'confirm'])->middleware(['signed', 'throttle:account-sensitive'])->name('email.confirm');

    Route::get('/security', [SecurityController::class, 'show'])->name('security');
    Route::put('/security/password', [SecurityController::class, 'updatePassword'])->middleware('throttle:account-sensitive')->name('password.update');
    Route::delete('/security/sessions', [SecurityController::class, 'destroyOtherSessions'])->middleware('throttle:account-sensitive')->name('sessions.destroy');

    Route::get('/competitions', [CompetitionHistoryController::class, 'index'])->name('competitions');
    Route::get('/competitions/history', [CompetitionHistoryController::class, 'history'])->name('competitions.history');
    Route::get('/competitions/{room}', [CompetitionHistoryController::class, 'show'])->whereNumber('room')->name('competitions.show');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read');
    Route::get('/notifications/preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences');
    Route::put('/notifications/preferences', [NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');
    Route::get('/notifications/{notification}', [NotificationController::class, 'open'])->whereUuid('notification')->name('notifications.open');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereUuid('notification')->name('notifications.mark');

    Route::get('/wallet', [WalletController::class, 'show'])->name('wallet');
    Route::get('/wallet/transactions', [WalletController::class, 'transactions'])->name('transactions');
    Route::get('/wallet/transactions/{transaction:reference}', [WalletController::class, 'transaction'])
        ->where('transaction', '[0-9A-HJKMNP-TV-Z]{26}')->name('transactions.show');

    Route::get('/payout-accounts', [PayoutDestinationController::class, 'index'])->name('payout-destinations');
    Route::post('/payout-accounts', [PayoutDestinationController::class, 'store'])->middleware('throttle:account-sensitive')->name('payout-destinations.store');
    Route::delete('/payout-accounts/{destination}', [PayoutDestinationController::class, 'destroy'])->whereNumber('destination')
        ->middleware('throttle:account-sensitive')->name('payout-destinations.destroy');

    Route::get('/withdrawals', [WithdrawalController::class, 'index'])->name('withdrawals.index');
    Route::post('/withdrawals/review', [WithdrawalController::class, 'review'])->middleware('throttle:account-sensitive')->name('withdrawals.review');
    Route::post('/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:account-sensitive')->name('withdrawals.store');
    Route::get('/withdrawals/{withdrawal:reference}', [WithdrawalController::class, 'show'])->where('withdrawal', '[0-9A-HJKMNP-TV-Z]{26}')->name('withdrawals.show');
    Route::post('/withdrawals/{withdrawal:reference}/cancel', [WithdrawalController::class, 'cancel'])->where('withdrawal', '[0-9A-HJKMNP-TV-Z]{26}')
        ->middleware('throttle:account-sensitive')->name('withdrawals.cancel');

    Route::get('/deposits', [DepositController::class, 'index'])->name('deposits.index');
    Route::post('/deposits', [DepositController::class, 'store'])->middleware('throttle:account-sensitive')->name('deposits.store');
    Route::get('/deposits/{payment:reference}', [DepositController::class, 'show'])
        ->where('payment', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:status-checks')->name('deposits.show');
});
