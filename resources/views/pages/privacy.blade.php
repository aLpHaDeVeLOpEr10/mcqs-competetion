<x-layouts.app title="Privacy policy">
    <x-page-header title="Privacy policy" eyebrow="Legal" />

    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
        <x-draft-notice class="max-w-3xl" />

        <div class="prose-page mt-8">
            <h2>What we collect</h2>
            <ul>
                <li><strong>Account details:</strong> your name, email address and the display name you choose.</li>
                <li><strong>Competition activity:</strong> the rooms you join, your answers, response times, scores and rankings.</li>
                <li><strong>Contact messages:</strong> the name, email and message you send through the contact form.</li>
                <li><strong>Technical data:</strong> IP address and browser information used for security and fraud prevention.</li>
            </ul>

            <h2>What is public</h2>
            <p>Only your <strong>display name</strong>, rank and score are shown on public results and leaderboards. Your real name and email address are never shown to other players.</p>

            <h2>How we use it</h2>
            <ul>
                <li>To run competitions, calculate results and keep an auditable record of them.</li>
                <li>To protect the service against cheating and abuse.</li>
                <li>To reply when you contact us.</li>
            </ul>

            <h2>Retention</h2>
            <p>Competition and financial records are kept as required for auditing and legal obligations. If you close your account, your personal details can be anonymised while those records are retained.</p>

            <h2>Your choices</h2>
            <p>You can ask us about the data we hold about you through the <a href="{{ route('contact') }}">contact page</a>.</p>
        </div>
    </div>
</x-layouts.app>
