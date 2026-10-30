<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { BookmarkPlus, Trash2, Users } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import type { LeadFilterState, SavedView } from '@/types';

/*
 * The leads list's saved views: a row of pills that open a stored filter set, plus a small form to
 * keep the current one. Each view is a real link (`?view=12`), so a view is shareable and the back
 * button works.
 */
const props = defineProps<{
    views: SavedView[];
    activeViewId: number | null;
    /** The filters on screen now — what "save this view" would keep. */
    filters: LeadFilterState;
    canSave: boolean;
    isFiltering: boolean;
}>();

const { t } = useI18n();

const isNaming = ref(false);

const form = useForm<{
    name: string;
    is_shared: boolean;
    filters: LeadFilterState;
}>({
    name: '',
    is_shared: false,
    filters: props.filters,
});

// Refs sit on the wrapping elements rather than on `Input`/`Button`: those are `<script setup>`
// components, so a ref to one is a closed instance proxy with no `focus()` on it.
const nameField = useTemplateRef<HTMLElement>('nameField');
const openSlot = useTemplateRef<HTMLElement>('openSlot');

async function openForm(): Promise<void> {
    isNaming.value = true;
    form.clearErrors();
    form.name = '';
    form.is_shared = false;

    // Focus follows the affordance the member just used, so a keyboard user lands in the field
    // rather than having focus dropped on the body.
    await nextTick();
    nameField.value?.querySelector('input')?.focus();
}

async function closeForm(): Promise<void> {
    isNaming.value = false;

    // …and goes back to the button it came from on cancel.
    await nextTick();
    openSlot.value?.querySelector('button')?.focus();
}

function save(): void {
    form
        // Always send the filters as they are *now*, not as they were when the form opened.
        .transform((data) => ({ ...data, filters: props.filters }))
        .post('/leads/views', {
            preserveScroll: true,
            onSuccess: () => {
                isNaming.value = false;
                form.reset();
            },
        });
}

function remove(view: SavedView): void {
    useForm({}).delete(`/leads/views/${view.id}`, { preserveScroll: true });
}

const hasViews = computed(() => props.views.length > 0);
</script>

<template>
    <section class="space-y-3" :aria-label="t('Saved views')">
        <div class="flex flex-wrap items-center gap-2">
            <p
                v-if="hasViews"
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                {{ t('Your views') }}
            </p>

            <ul v-if="hasViews" class="flex flex-wrap items-center gap-1.5">
                <li
                    v-for="view in props.views"
                    :key="view.id"
                    class="group flex items-center"
                >
                    <Link
                        :href="`/leads?view=${view.id}`"
                        preserve-scroll
                        class="rounded-full focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :aria-current="
                            view.id === props.activeViewId ? 'true' : undefined
                        "
                        :data-test="`saved-view-${view.id}`"
                    >
                        <Badge
                            :variant="
                                view.id === props.activeViewId
                                    ? 'brand'
                                    : 'outline'
                            "
                            class="gap-1.5"
                        >
                            <template v-if="view.is_shared">
                                <Users class="size-3" aria-hidden="true" />
                                <!-- The icon is decoration; the fact that the view is shared has
                                     to reach a screen reader as words. -->
                                <span class="sr-only">{{
                                    t('Shared with your team')
                                }}</span>
                            </template>
                            <span dir="auto">{{ view.name }}</span>
                            <span
                                v-if="!view.is_mine && view.owner"
                                class="opacity-70"
                                dir="auto"
                                >· {{ view.owner }}</span
                            >
                        </Badge>
                    </Link>
                    <!-- Always visible, and a 24px target: hiding it behind `group-hover` put it
                         out of reach on touch, and the icon alone was under the AA minimum. -->
                    <button
                        v-if="view.is_mine"
                        type="button"
                        class="ms-0.5 flex size-6 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :aria-label="
                            t('Remove the view “:name”', { name: view.name })
                        "
                        :data-test="`delete-view-${view.id}`"
                        @click="remove(view)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </li>
            </ul>

            <span class="flex-1" />

            <div
                v-if="props.canSave && !isNaming"
                ref="openSlot"
                class="flex items-center gap-2"
            >
                <!-- Why the button is disabled is said in text, not only in a `title` a screen
                     reader and a touch user never see. -->
                <p
                    v-if="!props.isFiltering"
                    id="save-view-hint"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('Filter the list first, then save it as a view.') }}
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    type="button"
                    :disabled="!props.isFiltering"
                    :aria-describedby="
                        props.isFiltering ? undefined : 'save-view-hint'
                    "
                    data-test="open-save-view"
                    @click="openForm"
                >
                    <BookmarkPlus class="size-4" />
                    {{ t('Save this view') }}
                </Button>
            </div>
        </div>

        <form
            v-if="isNaming"
            class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 sm:flex-row sm:items-end"
            @submit.prevent="save"
        >
            <div ref="nameField" class="grid flex-1 gap-2">
                <Label for="view-name">{{ t('Name this view') }}</Label>
                <Input
                    id="view-name"
                    v-model="form.name"
                    :placeholder="t('e.g. Grade A in London')"
                    autocomplete="off"
                    data-test="view-name"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="flex items-center gap-2 pb-2">
                <Checkbox
                    id="view-shared"
                    v-model="form.is_shared"
                    data-test="view-shared"
                />
                <Label for="view-shared" class="text-sm font-normal">{{
                    t('Share with my team')
                }}</Label>
            </div>

            <div class="flex gap-2">
                <Button
                    type="submit"
                    variant="brand"
                    size="sm"
                    :disabled="form.processing"
                    data-test="save-view"
                >
                    {{ t('Save') }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="closeForm"
                >
                    {{ t('Cancel') }}
                </Button>
            </div>
        </form>
    </section>
</template>
