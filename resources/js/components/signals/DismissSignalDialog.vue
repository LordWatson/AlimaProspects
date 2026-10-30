<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import type { Signal, SignalDismissReasonOption } from '@/types';

/*
 * Dismiss a signal, optionally saying why.
 *
 * The reason is **optional and the dialog says so** — a member who just wants the card gone
 * shouldn't have to justify it, and a required reason would only teach them to pick the first
 * option. When they do give one it is genuinely useful: "not an event" across a week says the
 * classifier is too loose, "not relevant" says the queries or the relevance floor are, and that is
 * how the thresholds get tuned on evidence rather than on feel.
 *
 * Nothing is destroyed. The copy says where the card goes, because "dismiss" sounds final.
 */
const props = defineProps<{
    signal: Signal | null;
    reasons: SignalDismissReasonOption[];
}>();

const emit = defineEmits<{ close: []; dismissed: [] }>();

const { t } = useI18n();

const form = useForm<{ reason: string | null }>({ reason: null });

function submit(): void {
    if (!props.signal) return;

    form.post(`/signals/${props.signal.id}/dismiss`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('dismissed');
        },
    });
}

function onOpenChange(open: boolean): void {
    if (!open) emit('close');
}
</script>

<template>
    <Dialog :open="signal !== null" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-md" data-test="dismiss-modal">
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ t('Dismiss this signal?') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            t(
                                'It moves to your Dismissed tab — nothing is deleted, and you can bring it back any time.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <fieldset class="space-y-3">
                    <legend class="text-sm font-medium text-foreground">
                        {{ t('Why? (optional)') }}
                    </legend>
                    <p class="-mt-1 text-xs text-muted-foreground">
                        {{
                            t(
                                'Telling us helps Alima find better signals next week.',
                            )
                        }}
                    </p>

                    <div class="space-y-2">
                        <Label
                            v-for="reason in reasons"
                            :key="reason.value"
                            class="flex cursor-pointer items-center gap-3 rounded-2xl border border-border p-3 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                        >
                            <input
                                v-model="form.reason"
                                type="radio"
                                name="reason"
                                class="size-4 accent-brand"
                                :value="reason.value"
                            />
                            <span class="text-sm text-foreground">{{
                                t(reason.label)
                            }}</span>
                        </Label>
                    </div>
                </fieldset>

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
                        data-test="dismiss-submit"
                    >
                        {{ form.processing ? t('Dismissing…') : t('Dismiss') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
