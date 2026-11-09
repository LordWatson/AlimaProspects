<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { UserRoundPlus } from '@lucide/vue';
import { ref } from 'vue';
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
 */
const props = defineProps<{ leadId: number }>();

const { t } = useI18n();

const open = ref(false);

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
    <div data-test="add-contact">
        <Button
            v-if="!open"
            variant="outline"
            size="sm"
            data-test="add-contact-open"
            @click="open = true"
        >
            <UserRoundPlus class="size-3.5" />
            {{ t('Add a contact') }}
        </Button>

        <form
            v-else
            class="space-y-3 rounded-2xl border border-border bg-card p-4"
            @submit.prevent="save"
        >
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-1">
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
    </div>
</template>
