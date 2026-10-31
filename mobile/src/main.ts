import { createApp } from 'vue';
import { IonicVue } from '@ionic/vue';

/* Ionic's own stylesheets, in the order its documentation requires: the core bundle, then the
   structural utilities, then the optional ones this app actually uses. */
import '@ionic/vue/css/core.css';
import '@ionic/vue/css/normalize.css';
import '@ionic/vue/css/structure.css';
import '@ionic/vue/css/typography.css';
import '@ionic/vue/css/padding.css';
import '@ionic/vue/css/flex-utils.css';
import '@ionic/vue/css/text-alignment.css';

/* Ionic's dark palette, which its own components read through `.ion-palette-dark`. The brand's
   dark values sit beside it in `theme/brand.css` under `.alima-dark`; `lib/appearance.ts` sets
   both classes together so neither half is left on the wrong theme. */
import '@ionic/vue/css/palettes/dark.class.css';

/* The brand, last, so it wins over Ionic's defaults. */
import '@/theme/brand.css';
import '@/theme/app.css';

import App from '@/App.vue';
import { router } from '@/router';
import { restoreApiUrl } from '@/lib/config';
import { restoreAppearance } from '@/lib/appearance';
import { restoreLocale } from '@/lib/i18n';
import { sessionReady } from '@/lib/session';

/**
 * Boot.
 *
 * Everything that decides what the **first frame** looks like is awaited before mounting: the
 * API origin, the language and its writing direction, the theme, and whether there is a session.
 * Resolving any of those after the mount means a visible flash — of English before Arabic, of
 * light before dark, or of a sign-in form on an app the member is already inside — and on a
 * phone, where launch is the most-seen moment in the app, that is the difference between a
 * native-feeling app and a web page in a box.
 */
async function boot(): Promise<void> {
    await Promise.all([
        restoreApiUrl(),
        restoreLocale(),
        restoreAppearance(),
        sessionReady(),
    ]);

    const app = createApp(App)
        .use(IonicVue, {
            // One set of transitions and controls on both platforms. Alima's own brand is the
            // design language here, and a product that looks like two different apps is harder
            // to support than one that looks like itself.
            mode: 'ios',
        })
        .use(router);

    await router.isReady();

    app.mount('#app');
}

// Not a top-level `await`, which the linter would prefer: the build targets es2020 and Safari 15
// (`vite.config.ts`), and esbuild refuses top-level await for either. `void` is the explicit
// "nothing awaits this" the codebase uses everywhere else.
// oxlint-disable-next-line unicorn/prefer-top-level-await
void boot();
