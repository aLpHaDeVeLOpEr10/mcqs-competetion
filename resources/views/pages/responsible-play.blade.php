<x-layouts.app title="Responsible play">
    <x-page-header title="Responsible play" eyebrow="Wellbeing">
        Competitions should stay fun. Here is how to keep them that way.
    </x-page-header>

    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="prose-page">
            <h2>Play for the challenge</h2>
            <p>{{ config('app.name') }} is a test of knowledge and speed. Treat it as entertainment and learning, not as a way to earn money.</p>

            <h2>Healthy habits</h2>
            <ul>
                <li>Decide in advance how much time you will spend playing, and stick to it.</li>
                <li>Take regular breaks, especially between back-to-back competitions.</li>
                <li>Never let competitions get in the way of study, work, sleep or family.</li>
                <li>If paid entry is ever offered, only spend what you can comfortably afford to lose, and never try to win back what you spent.</li>
            </ul>

            <h2>Warning signs</h2>
            <ul>
                <li>Feeling restless or irritable when you are not playing.</li>
                <li>Playing longer than you meant to, or hiding how much you play.</li>
                <li>Borrowing money or missing commitments because of playing.</li>
            </ul>

            <h2>Getting help</h2>
            <p>If you are worried about your own play or someone else's, talk to someone you trust and reach out to us through the <a href="{{ route('contact') }}">contact page</a> choosing “Responsible play”. Self-exclusion and account limits will be available from your account settings once player accounts launch.</p>
        </div>
    </div>
</x-layouts.app>
