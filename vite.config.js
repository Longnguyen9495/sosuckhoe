import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/tokens.css', 'resources/css/base.css', 'resources/css/components.css', 'resources/css/pages.css', 'resources/css/landing.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
});
