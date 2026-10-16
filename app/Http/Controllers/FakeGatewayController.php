<?php

namespace App\Http\Controllers;

use App\Services\Payments\DepositService;
use App\Services\Payments\Gateways\FakePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The SIMULATED provider's hosted checkout page, for local development only.
 * "Approving" here records the outcome on the simulated provider side and then
 * delivers its signed notification to our callback handler, exactly as a real
 * provider's server would. The browser is then sent to the return URL, which
 * reads the result from the database (never from this redirect).
 */
class FakeGatewayController extends Controller
{
    public function __construct(protected PaymentGatewayManager $gateways) {}

    public function show(string $providerReference): View
    {
        $state = $this->gateway()->byProviderReference($providerReference);
        abort_if($state === null, 404);

        return view('dev.fake-gateway', [
            'state' => $state,
            'providerReference' => $providerReference,
            'amount' => Money::format((int) $state['amount'], $state['currency']),
        ]);
    }

    public function complete(Request $request, string $providerReference, DepositService $deposits): RedirectResponse
    {
        $data = $request->validate(['outcome' => ['required', 'in:succeeded,failed']]);
        $gateway = $this->gateway();
        $state = $gateway->byProviderReference($providerReference);
        abort_if($state === null, 404);

        $notification = $gateway->complete($providerReference, $data['outcome']);

        // Simulated server-to-server delivery of the provider's signed notification.
        $deposits->handleCallback('fake', Request::create(route('payments.callback', 'fake'), 'POST', $notification, server: ['REMOTE_ADDR' => '127.0.0.1']));

        return redirect()->to($state['return_url']);
    }

    protected function gateway(): FakePaymentGateway
    {
        abort_unless($this->gateways->fakeAllowed(), 404);

        return $this->gateways->driver('fake');
    }
}
