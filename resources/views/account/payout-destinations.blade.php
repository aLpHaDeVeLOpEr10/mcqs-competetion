<x-account-layout title="Payout accounts">
    <p class="text-sm text-ink-400">Withdrawals are paid only to accounts saved here. We store the account number encrypted and only ever show its last four digits. For your security a new account can be used after {{ config('competition.withdrawals.destination_cooldown_hours') }} hours, and changes need your password.</p>

    <section aria-labelledby="saved-heading">
        <h2 id="saved-heading" class="text-lg font-semibold text-white">Saved accounts</h2>
        @if ($destinations->isEmpty())
            <x-empty-state title="No payout accounts" class="mt-3">Add a bank account or mobile wallet below.</x-empty-state>
        @else
            <ul class="mt-3 space-y-3">
                @foreach ($destinations as $destination)
                    <li>
                        <x-card padding="p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-white">{{ $destination->maskedLabel() }}</p>
                                    <p class="text-sm text-ink-400">{{ $destination->method->label() }} · {{ $destination->account_title }}</p>
                                    @unless ($destination->isUsable())
                                        <p class="mt-1 text-xs text-amber-300">Usable from <x-datetime :value="$destination->usable_after" format="j M, g:i A" /></p>
                                    @endunless
                                </div>
                                <details class="w-full sm:w-auto" @if ($errors->getBag('remove-'.$destination->id)->any()) open @endif>
                                    <summary class="cursor-pointer text-sm text-rose-300 hover:text-rose-200">Remove</summary>
                                    <form method="POST" action="{{ route('account.payout-destinations.destroy', $destination) }}" class="mt-3 space-y-3" data-loading>
                                        @csrf
                                        @method('DELETE')
                                        <x-form.input name="current_password" :id="'remove-password-'.$destination->id" type="password" label="Your password" autocomplete="current-password" required :bag="'remove-'.$destination->id" />
                                        <x-button type="submit" variant="danger" size="sm" data-loading-text="Removing…">Remove account</x-button>
                                    </form>
                                </details>
                            </div>
                        </x-card>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <x-card as="section" aria-labelledby="add-heading">
        <h2 id="add-heading" class="text-lg font-semibold text-white">Add an account</h2>
        <form method="POST" action="{{ route('account.payout-destinations.store') }}" class="mt-4 space-y-5" data-loading novalidate autocomplete="off">
            @csrf
            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.select name="method" label="Type" required bag="destination" :selected="old('method', 'mobile_wallet')"
                    :options="collect($methods)->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all()" />
                <x-form.select name="provider" label="Bank or wallet" required bag="destination" :selected="old('provider')" placeholder="Choose…"
                    :options="collect($methods)->flatMap(fn ($m) => collect($m->providers())->mapWithKeys(fn ($name, $code) => [$code => $name.' ('.($m === \App\Enums\PayoutMethod::BankAccount ? 'bank' : 'wallet').')']))->all()" />
            </div>
            <x-form.input name="account_title" label="Account holder name" maxlength="100" required bag="destination" :value="old('account_title')" help="As registered with the bank or wallet." />
            <x-form.input name="account_number" label="Account number" maxlength="40" required bag="destination" help="Mobile wallet: 03XXXXXXXXX. Bank: your IBAN (PK…, 24 characters)." />
            <x-form.input name="current_password" type="password" label="Your password" autocomplete="current-password" required bag="destination" />
            <x-button type="submit" data-loading-text="Saving…">Save account</x-button>
        </form>
    </x-card>
</x-account-layout>
