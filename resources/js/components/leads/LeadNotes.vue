<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { MessageSquarePlus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { LeadNote } from '@/types';

/*
 * Notes on a lead. Append-only by design: a note is amended by writing another one, so the thread
 * stays honest about what was known when. The delete button only appears on notes the server said
 * this member may remove.
 */
const props = defineProps<{
    leadId: number;
    notes: LeadNote[];
    canWrite: boolean;
}>();

const { t } = useI18n();
const { dateTime } = useFormat();

const form = useForm({ body: '' });

function save(): void {
    form.post(`/leads/${props.leadId}/notes`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function remove(note: LeadNote): void {
    useForm({}).delete(`/leads/notes/${note.id}`, { preserveScroll: true });
}
</script>

<template>
    <section class="space-y-4" data-test="notes">
        <h2
            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
        >
            {{ t('Notes') }}
        </h2>

        <form
            v-if="props.canWrite"
            class="space-y-2 rounded-2xl border border-border bg-card p-4"
            @submit.prevent="save"
        >
            <Label for="note-body" class="sr-only">{{ t('Add a note') }}</Label>
            <textarea
                id="note-body"
                v-model="form.body"
                rows="3"
                maxlength="5000"
                class="w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                :placeholder="t('What happened? Who did you speak to?')"
                data-test="note-body"
            />
            <InputError :message="form.errors.body" />
            <div class="flex justify-end">
                <Button
                    type="submit"
                    variant="brand"
                    size="sm"
                    :disabled="form.processing || form.body.trim() === ''"
                    data-test="save-note"
                >
                    <MessageSquarePlus class="size-4" />
                    {{ t('Add note') }}
                </Button>
            </div>
        </form>

        <p
            v-if="!props.notes.length"
            class="rounded-2xl border border-dashed border-border bg-card/50 p-5 text-sm text-muted-foreground"
            data-test="no-notes"
        >
            {{
                t(
                    'No notes yet. Anything your team learns about this company belongs here.',
                )
            }}
        </p>

        <ol v-else class="space-y-3">
            <li
                v-for="note in props.notes"
                :key="note.id"
                class="group rounded-2xl border border-border bg-card p-4"
                :data-test="`note-${note.id}`"
            >
                <div class="flex items-start justify-between gap-3">
                    <p
                        class="flex-1 text-sm leading-relaxed whitespace-pre-line text-foreground"
                        dir="auto"
                    >
                        {{ note.body }}
                    </p>
                    <button
                        v-if="note.can_delete"
                        type="button"
                        class="flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :title="t('Delete')"
                        :aria-label="t('Delete this note')"
                        :data-test="`delete-note-${note.id}`"
                        @click="remove(note)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </div>
                <p class="mt-2 font-mono text-xs text-muted-foreground">
                    <!-- A name is not mono: DM Mono has no Arabic glyphs. -->
                    <span class="font-sans" dir="auto">{{
                        note.author ?? t('A teammate')
                    }}</span>
                    <template v-if="note.created_at">
                        ·
                        <time :datetime="note.created_at">{{
                            dateTime(note.created_at)
                        }}</time>
                    </template>
                </p>
            </li>
        </ol>
    </section>
</template>
