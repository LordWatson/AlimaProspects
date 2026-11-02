/*
 * Alima's form furniture.
 *
 * The product's long forms — add a lead, the ideal-customer editor — were each four or five
 * sections of hand-wired fields, and they shared every weakness: no `aria-invalid` anywhere, no
 * summary of what the server rejected, no focus moved to the thing that failed, a save button
 * below the fold, and no guard at all on navigating away mid-draft. These are those five fixes,
 * once, so the next form starts with them rather than without them.
 *
 *  - `FormSection` — a numbered part of a form, on its own card.
 *  - `FormField` — label, hint, error, and the `for`/`id`/`aria-describedby`/`aria-invalid`
 *    wiring that no copy had got all of.
 *  - `FormErrorSummary` — one announced panel at the top, each failure a link into its field.
 *  - `FormActions` — the save bar, kept within reach, saying whether anything is unsaved.
 *  - `UnsavedChangesDialog` — the prompt for `useUnsavedGuard`, in the brand's own words.
 */
export { default as FormActions } from './FormActions.vue';
export { default as FormErrorSummary } from './FormErrorSummary.vue';
export { default as FormField } from './FormField.vue';
export { default as FormSection } from './FormSection.vue';
export { default as UnsavedChangesDialog } from './UnsavedChangesDialog.vue';
