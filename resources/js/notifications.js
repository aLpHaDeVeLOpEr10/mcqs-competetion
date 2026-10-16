/*
 * Live notification bell. Listens on the signed-in user's PRIVATE channel
 * (authorised by routes/channels.php) and shows new notifications as they
 * arrive. The broadcast carries only an id, a title and a link; the full
 * notification is read from the server when it is opened.
 */
import './echo';

const bell = document.querySelector('[data-notification-bell]');

if (bell && window.Echo) {
    const count = bell.querySelector('[data-bell-count]');
    const list = bell.querySelector('[data-bell-list]');
    const summary = bell.querySelector('summary');
    let unread = Number(count.textContent.replace('+', '')) || 0;

    window.Echo.private(`App.Models.User.${bell.dataset.userId}`).notification((notification) => {
        unread += 1;
        count.textContent = unread > 99 ? '99+' : String(unread);
        count.classList.remove('hidden');
        summary.setAttribute('aria-label', `Notifications, ${unread} unread`);

        list.querySelector('[data-bell-empty]')?.remove();
        const item = document.createElement('li');
        item.className = 'flex items-start gap-3 bg-accent-400/5 px-4 py-3 text-sm';
        const link = document.createElement('a');
        link.href = `/account/notifications/${encodeURIComponent(notification.id)}`;
        link.className = 'font-medium text-white hover:text-accent-300';
        link.textContent = notification.title; // text only, never HTML
        item.append(link);
        list.prepend(item);
    });
}
