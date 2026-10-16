# UI Design System

The UI is Blade components and Tailwind CSS 4 (tokens in `resources/css/app.css`), with small
progressive-enhancement scripts. Every page works without JavaScript except the live competition
screen, which says so in a `<noscript>` notice. The visual identity is a dark navy surface with
one restrained aqua accent. Colour is never the only carrier of meaning.

## Tokens

| Token | Use |
|---|---|
| `ink-950` / `ink-900` / `ink-850` / `ink-800` | page background, raised surfaces, cards, hover and inputs |
| `ink-700` / `ink-600` | borders and dividers; disabled outlines |
| `ink-500` | **decorative only** (below 4.5:1 contrast on dark surfaces) |
| `ink-400` | secondary text (≈ 7:1 on `ink-950`) |
| `ink-300` / `ink-200` / `ink-100`, `white` | body text, emphasis, headings |
| `accent-200…600` | links, primary actions, focus ring, live progress |
| emerald / amber / rose / sky (Tailwind) | success, warning, danger, info. **Always paired with an icon or words.** |

**Typography.** Instrument Sans (fonts.bunny.net, `display=swap`).
- Headings use `font-bold tracking-tight`: page 3xl–4xl, section xl–2xl.
- Body text is `text-sm` / `text-base`.
- Numbers that change (timers, countdowns) use `font-mono tabular-nums`, so they never shift.

**Spacing.**
- Page gutters: `px-4 sm:px-6 lg:px-8`.
- Content widths: `max-w-6xl` (site), `max-w-3xl` (live screen and reading pages).
- Gaps between sections: `space-y-6` / `gap-8`.

## Components (`resources/views/components`)

| Component | Notes |
|---|---|
| `x-button` | Variants `primary`, `secondary`, `ghost`, `danger`; sizes `sm`, `md`, `lg`; renders a link with `href`. **Loading:** in a `<form data-loading>` it disables itself after submit, shows its spinner and optional `data-loading-text`, so it cannot be submitted twice. |
| `x-form.input` / `select` / `textarea` / `checkbox` / `field` | Always labelled. Errors are linked with `aria-describedby` and `aria-invalid`. Passwords are never echoed back. |
| `x-card` | Raised surface (`padding` prop). |
| `x-badge`, `x-status-badge` | Text label with a tone; optional dot. Status is always readable as words. |
| `x-alert` | `success`, `error` (`role="alert"`), `warning`, `info` (`role="status"`), with an icon. |
| `x-modal` | Native `<dialog>` (focus handling, Escape), opened by `data-modal-open`. Its trigger is a real link, so it works without JS. Fits the viewport (`max-h: 100dvh − 2rem`, scrolling body). |
| Dropdowns | Native `<details data-mobile-menu>` (header account menu and mobile menu). Closed by Escape, clicking outside, or tabbing out. |
| `x-empty-state` | Dashed panel: title, text and optional action. |
| `x-pagination` | "Showing x–y of n", plus page links. |
| `x-page-header`, `x-account-layout`, `x-admin.layout` | Page shells. **Navigation:** `aria-current="page"` on the active link. Account tabs scroll horizontally on small screens, with an edge fade, and the active tab is scrolled into view. |
| `x-admin.table`, `x-admin.filters`, `x-admin.confirm-action` | Admin data tables (scroll horizontally inside their card on small screens); filter bars; two-step confirmations. |
| `x-error-page`, `errors/server.blade.php` | Branded error pages. 500 and 503 are self-contained: no layout, session or database. They never show exception details; 500 shows the request reference for support. |

### Responsive tables

Player-facing tables use `class="table-stack"`, with `data-label` on each `<td>` and
`data-primary` on the cell that should head the row. Below 640 px every row becomes a labelled
card, so there is no sideways scrolling for rankings, history, transactions, deposits or
withdrawals. Admin tables keep horizontal scrolling, which suits dense staff data.

## Live competition screen (`play/show.blade.php`, `resources/js/play.js`)

- **Header:** competition name and connection status, written as text with a dot ("Live",
  "Connecting…", "Reconnecting…").
- **Reconnect banner:** shown while WebSockets are down, explaining that the timer and answers are
  safe and the page is polling.
- **Sticky question bar:** stays under the site header.
  - "Question n of N", plus progress dots (up to 30 questions).
  - Timer pill: aqua normally, amber at ≤ 10 s, rose at ≤ 5 s, then "Time's up". Screen readers
    hear "10 seconds left" and "5 seconds left".
  - A thin time bar.
- **Options:** at least 56 px tall, full width on phones, letter chip plus text.
  - After you answer, the chosen option shows a lock icon and **"Your answer"**, and the others
    dim. Correctness is never shown, because the server does not send it until results are
    published.
  - Clicks are ignored while sending, and the server is idempotent, so a double tap sends one
    request.
- **Feedback line** (icon plus words): sending, locked in, time up, or the error message. Its
  height is reserved, and question images get a fixed-height box, to avoid layout shift.

## Accessibility checklist

- A skip link to `#main`, landmarks and one `h1` per page.
- A visible focus ring on everything (`:focus-visible`, `accent-300`).
- Live regions (`aria-live="polite"`) for the live screen's question changes, time warnings,
  answer status and connection changes. Flash messages use `role="status"` or `role="alert"`.
- `prefers-reduced-motion` disables animations and transitions.
- Tap targets are at least 44 px on the live screen and in the mobile menu; `md` buttons are about 40 px, `sm` buttons about 32 px (used for secondary actions).

## Performance

- `app.js` is about 2 KB: progressive enhancements only. The unused axios bootstrap was removed.
- Echo (WebSocket client, about 74 KB) loads only for signed-in users with broadcasting on, and on
  the live screen.
- **CSS:** Tailwind scans only `resources/**/*.blade.php`, `resources/**/*.js` and the pagination
  views, so the bundle no longer depends on the view cache (about 50 KB, 9.4 KB gzipped).
- **Question images** are re-encoded to WebP at upload and shown in a fixed-height box.
