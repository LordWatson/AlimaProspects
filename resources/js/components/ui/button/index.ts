import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

/*
 * Alima buttons — chips/buttons are pill-shaped (brand-tokens.md §2.2). Main
 * buttons are Midnight Ink (`default`); `brand` is Deep Teal for structural
 * actions; links use the Teal brand token. One primary CTA per view. Signal
 * Orange is never a button state.
 */
export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full text-sm font-semibold transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:ring-ring focus-visible:ring-[3px] focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-sm hover:bg-primary/90",
        brand:
          "bg-brand text-brand-foreground shadow-sm hover:bg-brand-hover",
        inverse:
          "bg-cream text-ink shadow-sm hover:bg-cream/90 focus-visible:ring-blue",
        lime:
          "bg-lime text-ink shadow-sm hover:bg-lime-80 focus-visible:ring-teal",
        // The secondary action inside a `PageHero` — outline, but on Midnight Ink. `outline` uses
        // `bg-background`/`border-input`, which is a cream chip on a dark panel; this is the same
        // shape in the hero's own colours. Cream/40 ring clears 3:1 on Ink, and the focus ring
        // offsets against Ink rather than the page background.
        hero:
          "bg-transparent text-cream ring-1 ring-cream/40 ring-inset hover:bg-cream/10 focus-visible:ring-cream focus-visible:ring-offset-ink",
        destructive:
          "bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90 focus-visible:ring-destructive/40",
        outline:
          "border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-secondary/80",
        ghost:
          "hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50",
        link: "text-brand underline-offset-4 hover:underline rounded-sm",
      },
      size: {
        "default": "h-10 px-5 py-2 has-[>svg]:px-4",
        "sm": "h-8 gap-1.5 px-4 has-[>svg]:px-3",
        "lg": "h-12 px-7 text-base has-[>svg]:px-5",
        "icon": "size-10 rounded-full",
        "icon-sm": "size-8 rounded-full",
        "icon-lg": "size-12 rounded-full",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
