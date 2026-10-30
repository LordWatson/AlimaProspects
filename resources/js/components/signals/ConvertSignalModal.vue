<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { nextTick, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import type { ConvertSignalForm, PipelineStage, Signal } from '@/types';

/*
 * Turn a signal into a lead.
 *
 * The form is **prefilled from the signal and every field is editable**, because an article's
 * wording is not a company record. The one required field is the company name: a lead in this
 * product is a company, and a relocation signal often names a person and no company at all — so
 * the form asks for their business or holding entity rather than inventing one. The hint says why,
 * since a required field with no explanation reads as a bug.
 *
 * The UAE notice is not dismissible and not a warning the member can click past: it is the basis on
 * which this lead is allowed to exist (UAE PDPL, ADR 0007). It uses `warning`, never Signal Orange.
 */
const props = defineProps<{
    signal: Signal | null;
    stages: PipelineStage[];
}>();

const emit = defineEmits<{ close: []; converted: [] }>();

const { t } = useI18n();

const companyInput = ref<InstanceType<typeof Input> | null>(null);

const form = useForm<ConvertSignalForm>({
    company_name: '',
    website: '',
    person_name: '',
    person_role: '',
    locality: '',
    country: '',
    pipeline_stage_id: null,
    note: '',
});

/*
 * Refill whenever a different signal opens the modal. Watching the signal (rather than filling on
 * mount) is what lets one modal instance serve every card in the feed.
 */
watch(
    () => props.signal,
    (signal) => {
        if (!signal) return;

        form.clearErrors();
        form.defaults({
            company_name: signal.subject_company_name ?? '',
            website: '',
            person_name: signal.subject_person_name ?? '',
            person_role: signal.subject_person_role ?? '',
            locality: signal.location ?? '',
            country: 'AE',
            pipeline_stage_id:
                props.stages.find((stage) => stage.is_default)?.id ?? null,
            note: '',
        });
        form.reset();

        // Focus the company field only when it is the one thing still missing — a relocation
        // signal that named no company. Stealing focus when the form is already complete fights
        // the dialog's own initial focus and tells the member nothing.
        if (!signal.subject_company_name) {
            void nextTick(() => companyInput.value?.$el?.focus?.());
        }
    },
    { immediate: true },
);

function submit(): void {
    if (!props.signal) return;

    form.post(`/signals/${props.signal.id}/convert`, {
        preserveScroll: true,
        // Only closed on success: a validation error has to stay on screen next to the field.
        onSuccess: () => emit('converted'),
    });
}

function onOpenChange(open: boolean): void {
    if (!open) emit('close');
}
</script>

<template>
    <Dialog :open="signal !== null" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-xl" data-test="convert-modal">
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ t('Convert to lead') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            t(
                                'Check what Alima read from the article, then add it to your leads. You can change anything here.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <!-- The basis on which this lead may exist. Not dismissible. -->
                <div
                    class="flex items-start gap-3 rounded-2xl bg-warning/12 p-4"
                    role="note"
                    data-test="convert-consent-notice"
                >
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-full text-warning"
                        aria-hidden="true"
                    >
                        <ShieldCheck class="size-4" />
                    </span>
                    <div class="space-y-0.5">
                        <p
                            class="text-sm font-semibold text-foreground rtl:font-bold"
                        >
                            {{
                                t('Reach out on LinkedIn or through a referral')
                            }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                t(
                                    'Not cold email — UAE data law needs their say-so first.',
                                )
                            }}
                        </p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="space-y-2">
                        <Label for="convert-company">{{ t('Company') }}</Label>
                        <Input
                            id="convert-company"
                            ref="companyInput"
                            v-model="form.company_name"
                            name="company_name"
                            required
                            aria-describedby="convert-company-hint"
                            dir="auto"
                        />
                        <p
                            id="convert-company-hint"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t(
                                    'A lead is a company, so this one is required. If the article only named a person, use their business or holding company.',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.company_name" />
                    </div>

                    <div class="space-y-2">
                        <Label for="convert-website">{{
                            t('Website (optional)')
                        }}</Label>
                        <Input
                            id="convert-website"
                            v-model="form.website"
                            name="website"
                            type="url"
                            placeholder="https://"
                            dir="ltr"
                        />
                        <InputError :message="form.errors.website" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="convert-person">{{
                                t('Person (optional)')
                            }}</Label>
                            <Input
                                id="convert-person"
                                v-model="form.person_name"
                                name="person_name"
                                dir="auto"
                            />
                            <InputError :message="form.errors.person_name" />
                        </div>
                        <div class="space-y-2">
                            <Label for="convert-role">{{
                                t('Their role (optional)')
                            }}</Label>
                            <Input
                                id="convert-role"
                                v-model="form.person_role"
                                name="person_role"
                                dir="auto"
                            />
                            <InputError :message="form.errors.person_role" />
                        </div>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Alima stores a name and a public role only — no email, no phone number.',
                            )
                        }}
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="convert-locality">{{
                                t('Town or city')
                            }}</Label>
                            <Input
                                id="convert-locality"
                                v-model="form.locality"
                                name="locality"
                                dir="auto"
                            />
                            <InputError :message="form.errors.locality" />
                        </div>
                        <div class="space-y-2">
                            <Label for="convert-stage">{{
                                t('Pipeline stage')
                            }}</Label>
                            <NativeSelect
                                id="convert-stage"
                                v-model="form.pipeline_stage_id"
                                name="pipeline_stage_id"
                            >
                                <option
                                    v-for="stage in stages"
                                    :key="stage.id"
                                    :value="stage.id"
                                >
                                    {{ stage.name }}
                                </option>
                            </NativeSelect>
                            <InputError
                                :message="form.errors.pipeline_stage_id"
                            />
                        </div>
                    </div>

                    <div class="space-y-2">
                        <Label for="convert-note">{{
                            t('Note (optional)')
                        }}</Label>
                        <Textarea
                            id="convert-note"
                            v-model="form.note"
                            name="note"
                            rows="3"
                            :placeholder="
                                t('Anything you already know about them…')
                            "
                            dir="auto"
                        />
                        <InputError :message="form.errors.note" />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('close')"
                    >
                        {{ t('Cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="convert-submit"
                    >
                        {{
                            form.processing
                                ? t('Adding…')
                                : t('Add to my leads')
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
