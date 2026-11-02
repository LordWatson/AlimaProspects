import { Capacitor } from '@capacitor/core';

/**
 * The name the token is issued under — what appears beside it on the web app's Settings → API
 * tokens screen, and the only thing that tells one handset from another when someone comes to
 * revoke "the phone I lost".
 *
 * So it has to be recognisable to a person, not merely unique. `@capacitor/device` would give a
 * model identifier (`iPhone15,2`), which is neither; the platform plus the browser's own idea of
 * the device gets closer, and a sensible fallback covers the rest.
 */
export const Device = {
    name(): string {
        const platform = Capacitor.getPlatform();

        if (platform === 'ios') {
            return 'Alima on iPhone';
        }

        if (platform === 'android') {
            return 'Alima on Android';
        }

        // The browser, during development. Naming it says which of several that someone is
        // looking at in the token list.
        const agent = globalThis.navigator?.userAgent ?? '';
        const browser = /edg/iu.test(agent)
            ? 'Edge'
            : /chrome/iu.test(agent)
              ? 'Chrome'
              : /firefox/iu.test(agent)
                ? 'Firefox'
                : /safari/iu.test(agent)
                  ? 'Safari'
                  : 'browser';

        return `Alima in ${browser}`;
    },
};
