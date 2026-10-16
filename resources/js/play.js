/*
 * Live competition screen. Presentation only: the server owns every deadline
 * and state change. This script displays the server's state, keeps a clock
 * offset to the server, listens for real-time events and falls back to
 * polling GET /play/{room}/state whenever the WebSocket is unavailable.
 * It never advances the competition and never decides correctness.
 */
import './echo';

const root = document.querySelector('[data-play]');

if (root) {
    const $ = (selector) => root.querySelector(selector);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const pollConnected = Number(root.dataset.pollConnected) * 1000;
    const pollDisconnected = Number(root.dataset.pollDisconnected) * 1000;

    let state = JSON.parse(root.dataset.initialState);
    let offsetMs = 0; // server clock minus browser clock
    let connected = false;
    let subscribedChannel = null;
    let pollTimer = null;
    let submitting = false;
    let lastAutoFetch = 0;
    let progress = null;
    let warnedFor = null; // question id whose "seconds left" warnings were announced
    let warned = new Set();

    const ICON_LOCK = '<svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></svg>';
    const ICON_CLOCK = '<svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>';
    const ICON_ALERT = '<svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 9v4m0 4h.01M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z" /></svg>';

    // Automatic resyncs (deadline passed, waiting target reached) at most every 2 s.
    const autoFetch = () => {
        if (Date.now() - lastAutoFetch < 2000) return;
        lastAutoFetch = Date.now();
        fetchState();
    };

    const serverNow = () => Date.now() + offsetMs;
    const syncClock = (iso) => { if (iso) offsetMs = Date.parse(iso) - Date.now(); };
    const announce = (text) => { $('[data-status]').textContent = text; };

    // Feedback line under the options: an icon plus words, never colour alone.
    const feedback = (text, icon = '', tone = 'text-ink-300') => {
        const el = $('[data-q-feedback]');
        if (el.dataset.text === text) return;
        el.dataset.text = text;
        el.className = `mt-4 flex min-h-6 items-center gap-2 text-sm ${tone}`;
        el.innerHTML = text ? icon : '';
        if (text) el.append(document.createTextNode(text));
    };

    const formatSeconds = (ms) => {
        const total = Math.max(0, Math.ceil(ms / 1000));
        const m = Math.floor(total / 60);
        const s = total % 60;
        return m > 0 ? `${m}:${String(s).padStart(2, '0')}` : String(s);
    };

    async function fetchState() {
        try {
            const response = await fetch(root.dataset.stateUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            if (response.status === 401 || response.status === 403) {
                window.location.reload();
                return;
            }
            if (response.ok) {
                applyState(await response.json());
            }
        } catch {
            // Network hiccup: the next poll retries.
        }
    }

    function applyState(next) {
        state = next;
        syncClock(state.server_now);
        subscribe();
        render();
    }

    // Hidden tabs do not poll (a player can't answer from them); showing the tab resyncs at once.
    function schedulePoll() {
        clearTimeout(pollTimer);
        if (document.hidden) return;
        pollTimer = setTimeout(async () => { await fetchState(); schedulePoll(); }, connected ? pollConnected : pollDisconnected);
    }

    function setConnection(isConnected, label, showBanner = !isConnected) {
        const changed = connected !== isConnected;
        connected = isConnected;
        $('[data-connection-dot]').className = `size-2 rounded-full ${isConnected ? 'bg-emerald-400' : 'bg-amber-400 motion-safe:animate-pulse'}`;
        $('[data-connection-label]').textContent = label;
        $('[data-reconnect]').classList.toggle('hidden', !showBanner);
        if (changed) announce(isConnected ? 'Live updates connected.' : 'Live updates interrupted. Still checking for updates every few seconds.');
        schedulePoll();
    }

    function subscribe() {
        const channel = state.session?.channel;
        if (!channel || channel === subscribedChannel || !window.Echo) return;
        subscribedChannel = channel;

        window.Echo.private(channel)
            .listen('.competition.starting', (e) => { syncClock(e.server_now); fetchState(); })
            .listen('.question.opened', (e) => {
                syncClock(e.server_now);
                state.question = { ...e.question, closed: false };
                state.my_answer = null;
                state.session = { ...state.session, status: 'in_progress', position: e.question.position };
                render();
                announce(`Question ${e.question.position} of ${e.question.total}`);
            })
            .listen('.question.closed', (e) => { syncClock(e.server_now); fetchState(); })
            .listen('.competition.progress', (e) => { progress = e; render(); })
            .listen('.competition.completed', () => fetchState())
            .listen('.results.published', () => fetchState());
    }

    async function submitAnswer(optionId, button) {
        if (submitting || state.my_answer || !state.question) return;
        submitting = true;
        const question = state.question;
        root.querySelectorAll('[data-option]').forEach((b) => { b.disabled = true; });
        button.setAttribute('aria-busy', 'true');
        button.querySelector('[data-option-status]').textContent = 'Sending…';
        feedback('Sending your answer…', ICON_CLOCK);

        const send = () => fetch(root.dataset.answerUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ question_id: question.id, option_id: optionId }),
        });

        try {
            let response;
            try {
                response = await send();
            } catch {
                response = await send(); // one retry; the server makes it idempotent
            }
            const body = await response.json().catch(() => ({}));

            if (body.my_answer) {
                state.my_answer = body.my_answer;
            }
            if (response.ok) {
                feedback('Answer locked in. Waiting for the question to close.', ICON_LOCK, 'text-accent-200');
                announce('Answer locked in.');
            } else {
                const message = response.status === 429 ? 'Too many attempts. Please wait a moment.' : (body.message || 'Your answer could not be accepted.');
                feedback(message, ICON_ALERT, 'text-amber-200');
                announce(message);
            }
        } catch {
            feedback('Connection problem. Checking your answer with the server…', ICON_ALERT, 'text-amber-200');
            await fetchState();
        } finally {
            submitting = false;
            button.removeAttribute('aria-busy');
            render();
        }
    }

    function renderOptions(question) {
        const container = $('[data-q-options]');
        if (container.dataset.questionId === String(question.id)) return;
        container.dataset.questionId = String(question.id);
        container.replaceChildren();

        for (const option of question.options) {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.option = String(option.id);
            button.className = 'group flex min-h-14 w-full items-center gap-3 rounded-xl border border-ink-600 bg-ink-900 px-4 py-3 text-left text-base text-ink-100 transition-colors hover:border-accent-400 hover:bg-ink-800 disabled:cursor-not-allowed disabled:hover:border-ink-600 disabled:hover:bg-ink-900 aria-pressed:border-accent-400 aria-pressed:bg-accent-400/10 sm:text-sm';
            const label = document.createElement('span');
            label.className = 'grid size-8 shrink-0 place-items-center rounded-lg bg-ink-800 text-sm font-bold text-ink-100 group-aria-pressed:bg-accent-400 group-aria-pressed:text-ink-950';
            label.textContent = option.label;
            label.setAttribute('aria-hidden', 'true');
            const text = document.createElement('span');
            text.className = 'min-w-0 flex-1 break-words';
            text.textContent = option.body;
            const status = document.createElement('span');
            status.dataset.optionStatus = '';
            status.className = 'shrink-0 text-xs font-semibold text-accent-200';
            button.setAttribute('aria-label', `${option.label}: ${option.body}`);
            button.append(label, text, status);
            button.addEventListener('click', () => submitAnswer(option.id, button));
            container.append(button);
        }
    }

    function render() {
        const session = state.session;
        const question = state.question;
        const showQuestion = session?.status === 'in_progress' && question;

        $('[data-panel="question"]').classList.toggle('hidden', !showQuestion);
        $('[data-panel="waiting"]').classList.toggle('hidden', !!showQuestion);

        if (showQuestion) {
            $('[data-q-progress]').textContent = `Question ${question.position} of ${question.total}`;
            renderSteps(question.position, question.total);
            $('[data-q-body]').textContent = question.body;
            const image = $('[data-q-image]');
            image.classList.toggle('hidden', !question.image_url);
            if (question.image_url && image.getAttribute('src') !== question.image_url) image.src = question.image_url;
            renderOptions(question);
            updateTimer();

            const mine = state.my_answer?.option_id;
            const closed = question.closed || serverNow() > Date.parse(question.closes_at);
            root.querySelectorAll('[data-option]').forEach((b) => {
                const chosen = Number(b.dataset.option) === mine;
                b.disabled = !!mine || closed || submitting;
                b.setAttribute('aria-pressed', chosen ? 'true' : 'false');
                b.classList.toggle('opacity-50', b.disabled && !chosen);
                const status = b.querySelector('[data-option-status]');
                if (chosen) status.innerHTML = `<span class="inline-flex items-center gap-1">${ICON_LOCK}Your answer</span>`;
                else if (!b.hasAttribute('aria-busy')) status.textContent = '';
            });
            // Correctness is never shown here: the server does not send it until results are published.
            if (mine && !submitting) feedback('Answer locked in. Waiting for the question to close.', ICON_LOCK, 'text-accent-200');
            else if (closed && !mine) feedback('Time is up for this question. No answer was recorded.', ICON_CLOCK, 'text-ink-300');
            else if (!mine && !submitting) feedback('');
            return;
        }

        const resultsLink = $('[data-results-link]');
        resultsLink.classList.toggle('hidden', !state.results_url);
        resultsLink.classList.toggle('inline-block', !!state.results_url);
        if (state.results_url) resultsLink.href = state.results_url;

        const title = $('[data-waiting-title]');
        const detail = $('[data-waiting-detail]');
        $('[data-waiting-countdown]').textContent = '';
        const waitingLabel = $('[data-waiting-label]');
        const counting = session?.status === 'countdown' || (session?.status === 'in_progress' && state.next_opens_at);
        waitingLabel.classList.toggle('hidden', !counting);
        waitingLabel.textContent = session?.status === 'countdown' ? 'Starts in' : 'Next question in';

        if (!session || session.status === 'pending') {
            title.textContent = 'Waiting for the competition to start';
            detail.textContent = 'You will be taken to the first question automatically.';
        } else if (session.status === 'countdown') {
            title.textContent = 'Starting soon';
            detail.textContent = `${session.total} questions. Stay on this page.`;
        } else if (session.status === 'in_progress') {
            title.textContent = session.position > 1 ? 'Next question coming up' : 'Get ready';
            detail.textContent = `Question ${session.position} of ${session.total}`
                + (progress && progress.position === session.position - 1 ? ` · ${progress.answered} of ${progress.players} players answered the last question` : '');
        } else if (session.status === 'scoring' || session.status === 'completed') {
            title.textContent = state.results_url ? 'Results are published' : 'Competition finished';
            detail.textContent = state.results_url
                ? `You answered ${state.answered_count} of ${session.total} questions.`
                : `You answered ${state.answered_count} of ${session.total} questions. Results will be published soon.`;
        } else if (session.status === 'aborted') {
            title.textContent = 'This competition was cancelled';
            detail.textContent = 'Any entry fee will be refunded.';
        }
    }

    // Question progress dots (one per question; hidden for very long papers, the text says it all).
    function renderSteps(position, total) {
        const list = $('[data-q-steps]');
        const key = `${position}/${total}`;
        if (list.dataset.key === key) return;
        list.dataset.key = key;
        list.replaceChildren();
        if (total > 30) return;
        for (let i = 1; i <= total; i++) {
            const dot = document.createElement('li');
            dot.className = `h-1.5 w-3 rounded-full ${i < position ? 'bg-accent-600' : i === position ? 'bg-accent-300' : 'bg-ink-700'}`;
            list.append(dot);
        }
    }

    function updateTimer() {
        const question = state.question;
        const remaining = Date.parse(question.closes_at) - serverNow();
        const limit = question.time_limit_seconds * 1000;
        const seconds = Math.ceil(remaining / 1000);

        $('[data-q-timer]').textContent = remaining > 0 ? formatSeconds(remaining) : "Time's up";
        $('[data-q-bar]').style.width = `${Math.max(0, Math.min(100, (remaining / limit) * 100))}%`;

        // Urgency is shown by wording and the screen-reader announcements too, not only colour.
        const tone = remaining <= 0 ? 'border-ink-600 bg-ink-800 text-ink-300'
            : seconds <= 5 ? 'border-rose-400/40 bg-rose-500/15 text-rose-200'
                : seconds <= 10 ? 'border-amber-400/40 bg-amber-400/10 text-amber-200'
                    : 'border-accent-400/30 bg-accent-400/10 text-accent-200';
        $('[data-q-timer-wrap]').className = `inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 ${tone}`;
        $('[data-q-bar]').className = `h-full rounded-full transition-[width] duration-200 ease-linear ${seconds <= 5 ? 'bg-rose-400' : seconds <= 10 ? 'bg-amber-400' : 'bg-accent-400'}`;

        if (warnedFor !== question.id) {
            warnedFor = question.id;
            warned = new Set();
        }
        for (const mark of [10, 5]) {
            if (seconds === mark && !warned.has(mark) && !state.my_answer && question.time_limit_seconds > mark) {
                warned.add(mark);
                announce(`${mark} seconds left.`);
            }
        }

        return remaining;
    }

    // Presentation-only countdown, recomputed from server timestamps every frame.
    function tick() {
        const session = state.session;
        if (state.question && session?.status === 'in_progress') {
            const remaining = updateTimer();
            if (remaining <= 0 && !state.question.closed) {
                state.question.closed = true;
                render();
                setTimeout(autoFetch, 1200); // let the server close it, then resync
            }
        } else {
            const target = session?.status === 'countdown' ? session.starts_at : state.next_opens_at;
            const remaining = target ? Date.parse(target) - serverNow() : null;
            $('[data-waiting-countdown]').textContent = remaining !== null && remaining > 0 ? formatSeconds(remaining) : '';
            if (remaining !== null && remaining <= -1500) autoFetch();
        }
        setTimeout(tick, 250);
    }

    // Connection status from the WebSocket client; polling covers any outage.
    const pusher = window.Echo?.connector?.pusher;
    if (pusher) {
        pusher.connection.bind('state_change', ({ current }) => {
            const ok = current === 'connected';
            setConnection(ok, ok ? 'Live' : 'Reconnecting…', !ok && current !== 'initialized' && current !== 'connecting');
            if (ok) fetchState(); // resync after any reconnect
        });
    } else {
        setConnection(false, 'Updating every few seconds', false);
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearTimeout(pollTimer);
        } else {
            fetchState();
            schedulePoll();
        }
    });
    window.addEventListener('online', fetchState);

    applyState(state);
    schedulePoll();
    tick();
}
