import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'
// VitePWA is intentionally DISABLED. It was never actually wired up: the plugin's
// default injectRegister targets an index.html, and this is a Blade app, so nothing
// ever loaded the worker it built. app.blade.php has no <link rel="manifest"> and
// there is no serviceWorker.register anywhere in the repo, so the service worker was
// never installed on any device -- the ~5 minutes it added to every build (workbox
// reads and hashes every one of ~1,595 output chunks) produced four dead files.
//
// NOTE: the offline write queue is NOT affected -- resources/js/composables/
// useOfflineQueue.ts uses IndexedDB in the page and needs no service worker.
//
// To reinstate (all four are required, or it stays dead):
//   1. uncomment the import and the plugin block below
//   2. add <link rel="manifest" href="/build/manifest.webmanifest"> to app.blade.php
//   3. register the worker -- import { registerSW } from 'virtual:pwa-register' in
//      app.ts, ideally gated on Fleet/PM entitlement so only users who need it install
//   4. composer require minishlink/web-push  (GovernanceNotifier + FleetNotification
//      Service both class_exists()-guard on it and silently no-op without it)
// Keep workbox.globPatterns narrow when you do -- shell only, e.g.
// ['assets/app-*.{js,css}', '*.{ico,png,svg}', 'manifest.webmanifest'] -- and raise
// maximumFileSizeToCacheInBytes above the entry chunk (~5.5 MB) or it is skipped.
// import { VitePWA } from 'vite-plugin-pwa'

// Auto-import plugins
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'

export default defineConfig({
    plugins: [
        tailwindcss(),
        // VitePWA({
// registerType: 'autoUpdate',
// includeAssets: ['favicon.ico', 'apple-touch-icon.png'],
// manifest: {
// name: 'Mkhaya Hub',
// short_name: 'Mkhaya',
// description: 'Fleet & HR Management Platform',
// theme_color: '#1867C0',
// background_color: '#ffffff',
// display: 'standalone',
// orientation: 'portrait',
// start_url: '/fleet',
// icons: [
// { src: '/icons/pwa-192.png', sizes: '192x192', type: 'image/png' },
// { src: '/icons/pwa-512.png', sizes: '512x512', type: 'image/png', purpose: 'any maskable' },
// ],
// },
// workbox: {
// globPatterns: ['**/*.{js,css,html,ico,png,svg}'],
// // Exclude the one oversized main chunk (~17 MB — the 12-language i18n is
// // bundled into it) from precaching entirely. globIgnores keeps workbox
// // from even READING the file (a size check alone was enough to OOM the
// // build at ~8 GB heap); it's fetched over the network on first load and
// // covered by the runtime caches below. All smaller chunks still precache.
// globIgnores: ['**/app-*.js'],
// maximumFileSizeToCacheInBytes: 4 * 1024 * 1024, // 4 MB safety net
// navigateFallback: null,
// runtimeCaching: [
// {
// urlPattern: /^https?:\/\/.*\/fleet\/.*$/,
// handler: 'NetworkFirst',
// options: { cacheName: 'fleet-api', expiration: { maxEntries: 50, maxAgeSeconds: 300 } },
// },
// ],
// },
// }),
        laravel({
            input: [
                'resources/js/app.ts',
            ],
            refresh: true,
        }),

        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),

        AutoImport({
            imports: [
                'vue',
                'pinia',
                '@vueuse/core',
                {
                    'vue-i18n': ['useI18n'],
                },
            ],
            dts: 'resources/js/auto-imports.d.ts',
        }),

        Components({
            dirs: [
                // Capital "C" — the folder is resources/js/Components. A lowercase
                // path resolves on case-insensitive macOS but finds nothing on
                // case-sensitive Linux, so auto-imported components (e.g.
                // ConfirmDialog) silently fail to register in production.
                'resources/js/Components',
                'resources/js/@core/components',
                'resources/js/@layouts/components',
            ],
            dts: 'resources/js/components.d.ts',
        }),
    ],

    css: {
        preprocessorOptions: {
            scss: {
                silenceDeprecations: ['if-function'],
            },
        },
    },

    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            '~': path.resolve(__dirname, 'resources/js'),
            '@images': path.resolve(__dirname, 'resources/js/assets/images'),
            '@layouts': path.resolve(__dirname, 'resources/js/@layouts'),
            '@core': path.resolve(__dirname, 'resources/js/@core'),
            '@themeConfig': path.resolve(__dirname, 'resources/js/themeConfig.ts'),
            '@configured-variables': path.resolve(__dirname, 'resources/js/@core/scss/template/_variables.scss'),
            '@styles': path.resolve(__dirname, 'resources/js/assets/styles'),
        },
    },

    server: {
        cors: true,
        origin: 'http://localhost:5173',
        hmr: {
            host: 'localhost',
        },
    },
})
