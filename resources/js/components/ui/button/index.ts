import type { VariantProps } from "class-variance-authority";
import { cva } from "class-variance-authority";

export { default as Button } from "./Button.vue";

/*
 * Alima buttons — chips/buttons are pill-shaped (brand-tokens.md §2.2). Main
 * buttons are Midnight Ink (`default`); `brand` is Deep Teal for structural
 * actions; links use the Teal brand token. One primary CTA per view. Signal
 * Orange is never a button state.
 */
export const buttonVariants = cva(
    "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full text-sm font-semibold transition-[background-color,color,transform,box-shadow,border-color] duration-150 ease-brand active:translate-y-0 active:scale-[0.98] disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:ring-ring focus-visible:ring-[3px] focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
    {
        variants: {
            variant: {
                default:
                    "bg-primary text-primary-foreground hover:bg-ink-hover dark:hover:bg-primary/90",
                brand: "bg-brand text-brand-foreground hover:bg-brand-hover",
                inverse:
                    "bg-cream text-ink shadow-sm hover:bg-cream/90 focus-visible:ring-blue",
                lime: "bg-lime text-ink hover:-translate-y-0.5 hover:bg-lime-hover focus-visible:ring-teal",
                // The secondary action inside a `PageHero` — outline, but on Midnight Ink. `outline` uses
                // `bg-background`/`border-input`, which is a cream chip on a dark panel; this is the same
                // shape in the hero's own colours. Cream/40 ring clears 3:1 on Ink, and the focus ring
                // offsets against Ink rather than the page background.
                hero: "border-[1.5px] border-ink-60 bg-transparent text-cream hover:border-cream hover:bg-cream hover:text-ink focus-visible:ring-cream focus-visible:ring-offset-ink",
                destructive:
                    "bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90 focus-visible:ring-destructive/40",
                outline:
                    "border-[1.5px] border-foreground bg-transparent text-foreground hover:bg-foreground hover:text-background",
                secondary:
                    "bg-secondary text-secondary-foreground hover:bg-secondary/80",
                ghost: "hover:bg-muted hover:text-foreground dark:hover:bg-accent/50",
                link: "text-brand underline-offset-4 hover:underline rounded-sm",
            },
            size: {
                default: "h-10 px-5 py-2 has-[>svg]:px-4",
                sm: "h-[34px] gap-1.5 px-3.5 text-xs has-[>svg]:px-3",
                lg: "h-12 px-7 text-base has-[>svg]:px-6",
                icon: "size-10 rounded-full",
                "icon-sm": "size-8 rounded-full",
                "icon-lg": "size-12 rounded-full",
            },
        },
        defaultVariants: {
            variant: "default",
            size: "default",
        },
    },
);
export type ButtonVariants = VariantProps<typeof buttonVariants>;
