/*
 * Room page: live "seats taken" updates over a public channel. Display only;
 * joining is always decided by the server under a row lock, never by this count.
 */
import './echo';

const root = document.querySelector('[data-room-live]');

if (root && window.Echo) {
    const initialStatus = root.dataset.roomStatus;
    const meter = document.querySelector('[data-seat-meter]');
    const notice = document.querySelector('[data-room-changed]');

    window.Echo.channel(`rooms.${root.dataset.roomId}`).listen('.room.occupancy', (event) => {
        if (meter) {
            const current = Number(event.participants_count);
            const max = Number(event.max_participants);
            const percent = max > 0 ? Math.min(100, Math.floor((current * 100) / max)) : 0;

            meter.querySelector('[data-seat-count]').textContent = current.toLocaleString();
            meter.querySelector('[data-seat-max]').textContent = ` / ${max.toLocaleString()} players`;
            const bar = meter.querySelector('[data-seat-bar]');
            bar.style.width = `${percent}%`;
            bar.classList.toggle('bg-amber-400', percent >= 100);
            bar.classList.toggle('bg-accent-400', percent < 100);
            meter.querySelector('[role="img"]').setAttribute('aria-label', `${current} of ${max} seats taken`);
        }

        if (notice && event.status !== initialStatus) {
            notice.hidden = false;
        }
    });
}
