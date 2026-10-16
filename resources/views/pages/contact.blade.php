<x-layouts.app title="Contact">
    <x-page-header title="Contact us" eyebrow="Support">
        Questions about a competition, your account or a result? Send us a message.
    </x-page-header>

    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
        @unless ($available)
            <x-alert variant="warning" title="Contact form unavailable" class="mb-6">
                The contact form is not available right now. Please try again later.
            </x-alert>
        @endunless

        @if ($errors->any())
            <x-alert variant="error" title="Please check the form" class="mb-6">
                {{ $errors->count() === 1 ? 'One field needs' : $errors->count().' fields need' }} your attention.
            </x-alert>
        @endif

        <x-card>
            <form method="POST" action="{{ route('contact.store') }}" class="space-y-5" novalidate data-loading>
                @csrf
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-form.input name="name" label="Your name" autocomplete="name" maxlength="100" required :disabled="! $available" />
                    <x-form.input name="email" type="email" label="Email" autocomplete="email" maxlength="255" required :disabled="! $available" help="We reply to this address." />
                </div>
                <x-form.select name="topic" label="Topic" :options="$topics" placeholder="Choose a topic" required :disabled="! $available" />
                <x-form.textarea name="message" label="Message" rows="6" maxlength="2000" required :disabled="! $available" help="10–2000 characters." />

                {{-- Honeypot for bots; hidden from people and assistive technology --}}
                <div class="hidden" aria-hidden="true">
                    <label for="website">Website</label>
                    <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
                </div>

                <x-button type="submit" :disabled="! $available" data-loading-text="Sending…">Send message</x-button>
            </form>
        </x-card>
    </div>
</x-layouts.app>
