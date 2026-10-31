import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';

/*
 * The mobile bundle. A plain Vite + Vue build rather than the root's vite-plus/Inertia/Tailwind
 * setup: nothing here is served by Laravel, there is no Blade entry point and no manifest for
 * the backend to read — the output is a folder of static files that Capacitor copies into the
 * iOS and Android projects.
 *
 * The two aliases are the whole point of the layout. `@shared` reaches *up* into the Laravel
 * app's `resources/js/shared/`, so the API client, the payload types and the brand tokens are
 * the same files the web app compiles — not a copy that drifts. `@lang` does the same for the
 * translation bundles, which are the product's own words and belong in one place (ADR 0007).
 */
export default defineConfig({
    plugins: [vue()],

    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
            '@shared': fileURLToPath(
                new URL('../resources/js/shared', import.meta.url),
            ),
            '@lang': fileURLToPath(new URL('../lang', import.meta.url)),
        },
    },

    server: {
        port: 5174,
        // The aliases resolve outside this project root, so the dev server has to be allowed to
        // serve from the repository above it. Scoped to the parent, not to `/`.
        fs: { allow: ['..'] },
    },

    build: {
        // Capacitor's `webDir`. Kept out of the Laravel public path on purpose: this bundle is
        // never served over HTTP by the app.
        outDir: 'dist',
        sourcemap: true,
        // A WebView on a three-year-old handset. Ionic 8 supports iOS 15+ / Chrome 89+, so the
        // target matches rather than defaulting to whatever the build machine's browserslist says.
        target: ['es2020', 'safari15'],
        // Ionic and Vue together put the main chunk over Rollup's 500 kB advice, and the advice
        // does not apply here: this bundle is copied *into* the app and read from local disk, so
        // its size is install size, not time-to-first-paint on someone's mobile data. The screens
        // are route-split anyway (and the Arabic bundle loads on demand), so raising the warning
        // is honest rather than a way of not looking at it.
        chunkSizeWarningLimit: 1200,
    },

    test: {
        environment: 'jsdom',
        include: ['src/**/*.test.ts'],
    },
});
