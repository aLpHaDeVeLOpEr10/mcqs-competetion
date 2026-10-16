import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/play.js', 'resources/js/room.js', 'resources/js/notifications.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // IPv4 on purpose: Chrome ignores IPv6 literals like [::1] in CSP source lists,
        // so a dev server on [::1] gets its CSS/JS blocked by SecurityHeaders.
        host: '127.0.0.1',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
