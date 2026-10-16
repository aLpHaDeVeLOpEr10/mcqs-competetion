/*
 * Progressive enhancements only. Every page works without JavaScript:
 * forms submit normally, the mobile menu and FAQs use <details>, and modal
 * triggers are real links to equivalent page content.
 */

// Ask before submitting forms marked [data-confirm]. The server still validates every action.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

// Loading state: disable the submit button and show its spinner while a [data-loading] form submits.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-loading]')) {
        return;
    }

    const button = event.submitter ?? form.querySelector('button[type="submit"]');
    if (!button) {
        return;
    }

    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
    }

    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');
    button.querySelector('[data-spinner]')?.classList.remove('hidden');

    const label = button.querySelector('[data-label]');
    if (label && button.dataset.loadingText) {
        label.dataset.original = label.textContent;
        label.textContent = button.dataset.loadingText;
    }

    // Disable after the submit event so the button's own name/value is still sent.
    setTimeout(() => button.setAttribute('disabled', ''), 0);
});

// Restore forms when the page is shown again from the back/forward cache.
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submitting="true"]').forEach((form) => {
        delete form.dataset.submitting;
        form.removeAttribute('aria-busy');
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.removeAttribute('disabled');
            button.querySelector('[data-spinner]')?.classList.add('hidden');
            const label = button.querySelector('[data-label]');
            if (label?.dataset.original) {
                label.textContent = label.dataset.original;
            }
        });
    });
});

// Modals: open native <dialog>s; clicking the backdrop closes them.
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-modal-open]');
    if (trigger) {
        const dialog = document.getElementById(trigger.dataset.modalOpen);
        if (dialog instanceof HTMLDialogElement) {
            event.preventDefault();
            dialog.showModal();
        }
        return;
    }

    if (event.target instanceof HTMLDialogElement && event.target.open) {
        const rect = event.target.getBoundingClientRect();
        const inside = event.clientX >= rect.left && event.clientX <= rect.right
            && event.clientY >= rect.top && event.clientY <= rect.bottom;
        if (!inside) {
            event.target.close();
        }
    }
});

// Menus (<details data-mobile-menu>): close when clicking or tabbing outside.
document.addEventListener('click', (event) => {
    document.querySelectorAll('details[data-mobile-menu][open]').forEach((menu) => {
        if (!menu.contains(event.target)) {
            menu.removeAttribute('open');
        }
    });
});
document.addEventListener('focusin', (event) => {
    document.querySelectorAll('details[data-mobile-menu][open]').forEach((menu) => {
        if (!menu.contains(event.target)) {
            menu.removeAttribute('open');
        }
    });
});

// Horizontally scrolling tab rows: bring the current tab into view on small screens.
document.querySelectorAll('[data-scroll-active]').forEach((list) => {
    const current = list.querySelector('[aria-current="page"]');
    if (current && list.scrollWidth > list.clientWidth) {
        list.scrollLeft = current.offsetLeft - (list.clientWidth - current.offsetWidth) / 2;
    }
});

// Close the mobile menu with Escape and return focus to its toggle.
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }
    const menu = document.querySelector('details[data-mobile-menu][open]');
    if (menu) {
        menu.removeAttribute('open');
        menu.querySelector('summary')?.focus();
    }
});
