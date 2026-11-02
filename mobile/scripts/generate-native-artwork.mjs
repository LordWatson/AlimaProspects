#!/usr/bin/env node
/**
 * Regenerate the native projects' vector artwork from the master logo path.
 *
 * The brand book is explicit that the logo is never redrawn, retyped or approximated
 * (brand-tokens.md §4), which means the Android launcher icon, the launch screen and the
 * notification icon must come from the same path data the web app and the mobile screens draw —
 * `resources/js/shared/brand/artwork.ts`. This script is how: it reads that file and writes the
 * Android vector drawables, so the logo exists once and the native copies are derived.
 *
 * Run it after the artwork file changes:
 *
 *     npm run artwork
 *
 * What it does **not** do, and cannot: the raster assets. The legacy launcher PNGs (Android 23–25)
 * and the whole iOS AppIcon set need a rasterised export of the signature at a dozen sizes, which
 * is a design deliverable rather than something to synthesise — see `docs/modules/mobile-app.md`
 * for the step and the `@capacitor/assets` command that consumes it.
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const mobile = join(here, '..');
const artworkFile = join(
    mobile,
    '..',
    'resources',
    'js',
    'shared',
    'brand',
    'artwork.ts',
);
const drawable = join(mobile, 'android/app/src/main/res/drawable');

/** Pull one named export's viewBox and path out of the TypeScript source. */
function readArtwork(name) {
    const source = readFileSync(artworkFile, 'utf8');
    const match = new RegExp(
        `export const ${name}: Artwork = \\{\\s*viewBox: '([^']+)',\\s*d:\\s*'([^']+)',`,
    ).exec(source);

    if (match === null) {
        throw new Error(`Could not find \`${name}\` in ${artworkFile}`);
    }

    const [, viewBox, d] = match;
    const [, , width, height] = viewBox.split(' ').map(Number);

    return { viewBox, d, width, height };
}

const generated = (what) =>
    `<!--\n    ${what}\n\n    GENERATED from the master path data in \`resources/js/shared/brand/artwork.ts\`\n    by \`npm run artwork\`. Do not edit the path data here.\n-->`;

function write(file, contents) {
    mkdirSync(drawable, { recursive: true });
    writeFileSync(join(drawable, file), contents, 'utf8');
    process.stdout.write(`  wrote ${file}\n`);
}

const mark = readArtwork('markArtwork');

// --- The launcher icon's foreground layer ---------------------------------------------------
// 60dp across inside a 108dp canvas, which keeps it within the 72dp zone an adaptive icon
// guarantees. Anything outside that zone is cropped by whichever mask the launcher applies.
{
    const box = 108;
    const target = 60;
    const scale = target / mark.width;

    write(
        'ic_launcher_foreground.xml',
        `<?xml version="1.0" encoding="utf-8"?>
${generated('The Alima panda mark as the launcher icon’s foreground layer, in cream on the teal tile.')}
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="${box}dp"
    android:height="${box}dp"
    android:viewportWidth="${box}"
    android:viewportHeight="${box}">
    <group
        android:scaleX="${scale.toFixed(6)}"
        android:scaleY="${scale.toFixed(6)}"
        android:translateX="${((box - target) / 2).toFixed(4)}"
        android:translateY="${((box - mark.height * scale) / 2).toFixed(4)}">
        <path
            android:fillColor="@color/alima_cream"
            android:pathData="${mark.d}" />
    </group>
</vector>
`,
    );
}

// --- The launch screen's mark ----------------------------------------------------------------
{
    const dp = 120;

    write(
        'splash_mark.xml',
        `<?xml version="1.0" encoding="utf-8"?>
${generated('The panda mark for the launch screen — Teal on cream by day, cream on Ink by night.')}
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="${dp}dp"
    android:height="${Math.round((dp * mark.height) / mark.width)}dp"
    android:viewportWidth="${mark.width}"
    android:viewportHeight="${mark.height}">
    <path
        android:fillColor="@color/alima_splash_mark"
        android:pathData="${mark.d}" />
</vector>
`,
    );
}

// --- The notification small icon -------------------------------------------------------------
// Full bleed in its 24dp box and white on transparency: Android masks a small icon to a
// silhouette and tints it, so a tile behind the mark would mask to a solid square.
{
    const box = 24;
    const scale = box / mark.width;

    write(
        'ic_notification.xml',
        `<?xml version="1.0" encoding="utf-8"?>
${generated('The notification small icon: the panda mark alone, on transparency, masked and tinted by Android.')}
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="${box}dp"
    android:height="${box}dp"
    android:viewportWidth="${box}"
    android:viewportHeight="${box}">
    <group
        android:scaleX="${scale.toFixed(6)}"
        android:scaleY="${scale.toFixed(6)}"
        android:translateY="${((box - mark.height * scale) / 2).toFixed(4)}">
        <path
            android:fillColor="#FFFFFFFF"
            android:pathData="${mark.d}" />
    </group>
</vector>
`,
    );
}

process.stdout.write('Native artwork regenerated from the master logo path.\n');
