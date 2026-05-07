import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { compression } from 'vite-plugin-compression2';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        VitePWA({
            registerType: 'autoUpdate',
            injectRegister: false,
            manifest: false,
            devOptions: { enabled: false },
            workbox: {
                // Precache only content-hashed static assets — never HTML
                globPatterns: ['**/*.{js,css,woff2,woff}'],
                navigateFallback: null,
                runtimeCaching: [
                    {
                        // Immutable build assets (content-hashed): serve from cache instantly
                        urlPattern: ({ url }) => url.pathname.startsWith('/build/'),
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'gwm-assets-v1',
                            expiration: { maxAgeSeconds: 60 * 60 * 24 * 365 },
                        },
                    },
                    {
                        // Favicons: long-lived, rarely change
                        urlPattern: /\/favicons\//,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'gwm-icons-v1',
                            expiration: { maxAgeSeconds: 60 * 60 * 24 * 30 },
                        },
                    },
                ],
            },
        }),
        compression({ algorithm: 'brotliCompress' }),
        compression({ algorithm: 'gzip' }),
    ],
});
