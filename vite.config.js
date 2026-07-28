import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
    server: {
        watch: {
            // Only `resources/` is ever hot-reloadable, but Vite's watcher
            // defaults to the whole project root. On macOS a recursive FSEvents
            // watch is nearly free; on Windows it's a per-directory
            // ReadDirectoryChangesW handle plus a Defender scan on every hit,
            // which is enough to hold a core busy on its own.
            //
            // `vendor/` is the big one (tens of thousands of files), and the
            // SQLite files churn on literally every request — WAL is on, and
            // the app writes sessions/cache/settings constantly.
            ignored: [
                '**/vendor/**',
                '**/storage/**',
                '**/database/*.sqlite*',
                '**/public/build/**',
                '**/.git/**',
            ],
        },
    },
});
