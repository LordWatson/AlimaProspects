import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Badge } from "./Badge.vue"

/*
 * Alima badges. Status variants (`success`/`warning`/`error`/`info`) use the
 * semantic status tokens — Signal Orange is DECORATIVE ONLY and is never a
 * status colour (brand-tokens.md §2.1). Accent variants exist for decorative
 * taxonomy (tags, categories), not state.
 */
export const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-full border px-2.5 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary/90",
        brand:
          "border-transparent bg-brand text-brand-foreground [a&]:hover:bg-brand-hover",
        secondary:
          "border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/90",
        outline:
          "text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground",
        success:
          "border-transparent bg-success/12 text-success",
        warning:
          "border-transparent bg-warning/12 text-warning",
        error:
          "border-transparent bg-destructive/12 text-destructive",
        info:
          "border-transparent bg-info/12 text-info",
        // Decorative accent chips — taxonomy only, never state.
        lilac: "border-transparent bg-lilac-40 text-ink",
        lime: "border-transparent bg-lime-40 text-ink",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)
export type BadgeVariants = VariantProps<typeof badgeVariants>
