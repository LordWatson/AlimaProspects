import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
            // The mobile app (Phase 18) is its own Vite project with its own oxlint and oxfmt
            // configuration — `mobile/.oxlintrc.json` and `mobile/.oxfmtrc.json`, set to the same
            // rules and the same style as these. It is excluded here rather than linted twice,
            // because this pass would also reach its committed native projects: Gradle files,
            // Xcode asset catalogues and a `project.pbxproj`, none of which are ours to format.
            'mobile/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
            // Generated whole on every graph build (and by the post-commit hook), so formatting it
            // is a fight the generator always wins — and `composer ci:check` loses.
            'graphify-out/**',
            // Same bargain for the OpenAPI specification: Scramble writes it whole on every
            // `composer api:docs`, so a formatter that reflows it only makes the next export a
            // diff nobody asked for.
            'docs/api/openapi.json',
            // Published vendor views: Laravel's own markup, deliberately kept diffable against
            // upstream. The brand lives in the theme stylesheet beside them.
            'resources/views/vendor/mail/**',
            // See the lint note above: `mobile/` formats itself, and its native projects are
            // written by Xcode and the Capacitor CLI.
            'mobile/**',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
