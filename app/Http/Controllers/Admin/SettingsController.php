<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\Compliance\LaunchGate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Read-only view of effective platform settings. Values come from the
 * environment and are changed by deployment, not from this screen.
 */
class SettingsController extends Controller
{
    public function __invoke(): View
    {
        $contact = config('competition.contact.recipient');

        return view('admin.settings', [
            'settings' => [
                'Platform' => [
                    'Environment' => app()->environment(),
                    'Debug mode' => config('app.debug') ? 'On' : 'Off',
                    'Application URL' => config('app.url'),
                    'Storage timezone' => config('app.timezone'),
                    'Display timezone' => config('competition.display_timezone'),
                ],
                'Money' => [
                    'Currency' => config('competition.currency.code'),
                    'Minor units per major' => config('competition.currency.minor_units_per_major'),
                    'Paid entries' => config('competition.paid_entries.enabled') ? 'Enabled' : 'Disabled',
                    'Payment gateway' => config('competition.payments.gateway'),
                ],
                'Real-money launch gate' => $this->launchGate(),
                'Accounts & mail' => [
                    'Email verification required' => config('competition.accounts.email_verification') ? 'Yes' : 'No',
                    'Mailer' => config('mail.default'),
                    'Contact form recipient' => $contact ? Str::mask($contact, '*', 2, max(0, strpos($contact, '@') - 2)) : 'Not configured (form disabled)',
                    'Session driver' => config('session.driver'),
                    'Queue connection' => config('queue.default'),
                ],
            ],
            'roles' => collect(UserRole::cases())->filter->isStaff()->values(),
            'permissions' => Permission::cases(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function launchGate(): array
    {
        $gate = app(LaunchGate::class);
        $rows = [
            'Status' => $gate->approved() ? 'Approved: real-money switches may be used' : 'LOCKED: real-money features are off',
            'Approval reference' => config('compliance.approval_reference') ?: 'None recorded',
            'Permitted jurisdictions' => implode(', ', config('compliance.permitted_jurisdictions')) ?: 'None',
            'Minimum age (set by review)' => (string) config('compliance.minimum_age'),
            'Switches requested' => implode(', ', $gate->requested()) ?: 'None',
        ];
        foreach (config('compliance.requirements') as $requirement) {
            $rows[$requirement['label']] = $requirement['confirmed'] ? 'Confirmed' : 'Not confirmed';
        }

        return $rows;
    }
}
