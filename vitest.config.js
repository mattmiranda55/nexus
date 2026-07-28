import { defineConfig } from 'vitest/config';

// Deliberately separate from vite.config.js. That config loads the Laravel and
// Tailwind plugins, which expect a real build context (an entry list, a
// manifest to write) and have nothing to offer a test run. A dedicated config
// takes precedence, so the tests boot with none of it.
export default defineConfig({
    test: {
        // The suites here cover pure parsing/scoring helpers, no DOM.
        environment: 'node',
        include: ['resources/js/**/*.test.js'],
    },
});
