# PLACEHOLDER — replace before any TestFlight or App Store build

`AppIcon-512@2x.png` is Capacitor's own artwork, scaffolded by `npx cap add ios`. It is **not**
the Alima logo, and shipping it would put another product's mark on the home screen.

Replacing it needs a rasterised export of the signature, which is a design deliverable rather than
something to synthesise from the vector path — see `docs/modules/mobile-app.md` ("The asset pass")
for the sizes and the `@capacitor/assets` command that consumes them. The same export covers the
legacy Android launcher PNGs (API 23–25) and the iOS launch screen's mark.

Everything that *can* be generated from the master path already is: the Android adaptive icon, its
launch screen and its notification icon (`npm run artwork`).
