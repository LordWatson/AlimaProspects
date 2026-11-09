<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { UserRoundPlus } from '@lucide/vue';
import { nextTick, useTemplateRef, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';

/*
 * Adding a contact by hand — the way out of a Contacts panel that is empty because nothing this
 * company publishes is on a page Alima could read.
 *
 * Collapsed until asked for, because the panel's first answer should still be "let Alima look":
 * what a member types is unverified and uncorroborated, and a form sitting open invites typing
 * over looking.
 *
 * The trigger is the **contacts panel's header action**, not this component: every panel on the
 * record puts its add action in the same place, and owning a second one here is how this one came
 * to sit under the list while the tasks panel's sat above it. So `open` is the caller's state and
 * this file is the form.
 */
const props = defineProps<{ leadId: number }>();

const open = defineModel<boolean>('open', { required: true });

const { t } = useI18n();

// The trigger is in the panel header, so opening the form without moving focus leaves a keyboard
// user tabbing towards something they already asked for. The ref sits on the wrapping element
// rather than on `Input`: that is a `<script setup>` component, so a ref to one is a closed
// instance proxy with no `focus()` on it (same note as `SavedViewBar`).
const nameField = useTemplateRef<HTMLElement>('nameField');

watch(open, async (isOpen) => {
    if (!isOpen) {
        return;
    }

    await nextTick();
    nameField.value?.querySelector('input')?.focus();
});

const form = useForm({ name: '', title: '', email: '', phone: '' });

function save(): void {
    form.post(`/leads/${props.leadId}/contacts`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <form
        v-if="open"
        class="space-y-3 rounded-2xl border border-border bg-background p-4"
        data-test="add-contact"
        @submit.prevent="save"
    >
        <div class="grid gap-3 sm:grid-cols-2">
            <div ref="nameField" class="space-y-1">
                <Label for="contact-name">{{ t('Name') }}</Label>
                <Input
                    id="contact-name"
                    v-model="form.name"
                    maxlength="255"
                    autocomplete="off"
                    data-test="contact-name"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="space-y-1">
                <Label for="contact-title">{{ t('Job title') }}</Label>
                <Input
                    id="contact-title"
                    v-model="form.title"
                    maxlength="255"
                    autocomplete="off"
                    data-test="contact-title"
                />
                <InputError :message="form.errors.title" />
            </div>

            <div class="space-y-1">
                <Label for="contact-email">{{ t('Email') }}</Label>
                <Input
                    id="contact-email"
                    v-model="form.email"
                    type="email"
                    maxlength="255"
                    autocomplete="off"
                    dir="ltr"
                    data-test="contact-email"
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="space-y-1">
                <Label for="contact-phone">{{ t('Phone') }}</Label>
                <Input
                    id="contact-phone"
                    v-model="form.phone"
                    type="tel"
                    maxlength="64"
                    autocomplete="off"
                    dir="ltr"
                    data-test="contact-phone"
                />
                <InputError :message="form.errors.phone" />
            </div>
        </div>

        <p class="text-xs text-muted-foreground">
            {{
                t(
                    'An email address or a phone number is enough. Alima won’t check what you add here.',
                )
            }}
        </p>

        <div class="flex justify-end gap-2">
            <Button
                type="button"
                variant="ghost"
                size="sm"
                data-test="add-contact-cancel"
                @click="
                    open = false;
                    form.reset();
                    form.clearErrors();
                "
            >
                {{ t('Cancel') }}
            </Button>
            <Button
                type="submit"
                variant="brand"
                size="sm"
                :disabled="
                    form.processing ||
                    (form.email.trim() === '' && form.phone.trim() === '')
                "
                data-test="save-contact"
            >
                <UserRoundPlus class="size-4" />
                {{ t('Save contact') }}
            </Button>
        </div>
    </form>
</template>
