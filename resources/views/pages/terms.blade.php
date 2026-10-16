<x-layouts.app title="Terms of use">
    <x-page-header title="Terms of use" eyebrow="Legal" />

    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
        <x-draft-notice class="max-w-3xl" />

        <div class="prose-page mt-8">
            <h2>1. About these terms</h2>
            <p>These terms govern your use of {{ config('app.name') }} and your participation in any competition on it. By creating an account or joining a competition you agree to them.</p>

            <h2>2. Eligibility</h2>
            <ul>
                <li>You must meet the minimum age and residency requirements that will be stated here once confirmed.</li>
                <li>You may hold only one account, and the information you provide must be accurate.</li>
            </ul>

            <h2>3. Competitions</h2>
            <ul>
                <li>Each competition's entry fee, capacity, schedule and prize structure are shown on its page before you join.</li>
                <li>Timing, answer acceptance, scoring and ranking are determined solely by our servers as described on the <a href="{{ route('how-to-play') }}">How to play</a> page.</li>
                <li>We may cancel a competition that does not reach its minimum players or cannot be run fairly. Entry fees for cancelled competitions are refunded.</li>
            </ul>

            <h2>4. Entry fees and prizes</h2>
            <p>Paid entry is currently disabled. Only free-entry competitions are offered. Terms covering paid entry, prize payment, verification and taxes will be added before any such feature is enabled.</p>

            <h2>5. Fair play</h2>
            <p>Use of bots, scripts, multiple accounts, collusion or any attempt to interfere with the service may lead to disqualification and account suspension.</p>

            <h2>6. Changes</h2>
            <p>We may update these terms. Material changes will be announced on this site before they take effect.</p>

            <h2>7. Contact</h2>
            <p>Questions about these terms can be sent through the <a href="{{ route('contact') }}">contact page</a>.</p>
        </div>
    </div>
</x-layouts.app>
