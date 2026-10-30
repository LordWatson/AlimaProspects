<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarClock, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { LeadTask, TeamMemberOption } from '@/types';

/*
 * Work outstanding on a lead. `is_overdue` comes from the server, computed against the stored UTC
 * column, so a client with a wrong clock can't decide for itself what is late.
 *
 * Reminders are stored and nothing sends them yet — Notifications (Phase 12) sweeps them — which
 * the form says plainly rather than implying a nudge that isn't coming.
 */
const props = defineProps<{
    leadId: number;
    tasks: LeadTask[];
    members: TeamMemberOption[];
    canWrite: boolean;
}>();

const { t } = useI18n();
const { dateTime, date } = useFormat();

const isAdding = ref(false);

const form = useForm<{
    title: string;
    assigned_to: number | null;
    due_at: string;
    remind_at: string;
}>({
    title: '',
    assigned_to: null,
    due_at: '',
    remind_at: '',
});

function save(): void {
    form.post(`/leads/${props.leadId}/tasks`, {
        preserveScroll: true,
        onSuccess: () => {
            isAdding.value = false;
            form.reset();
        },
    });
}

function setDone(task: LeadTask, done: boolean): void {
    useForm({ done }).put(`/leads/tasks/${task.id}`, { preserveScroll: true });
}

function remove(task: LeadTask): void {
    useForm({}).delete(`/leads/tasks/${task.id}`, { preserveScroll: true });
}

const open = computed(() =>
    props.tasks.filter((task) => task.status === 'open'),
);
const done = computed(() =>
    props.tasks.filter((task) => task.status === 'done'),
);
</script>

<template>
    <section class="space-y-4" data-test="tasks">
        <div class="flex items-center justify-between gap-3">
            <h2
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                {{ t('To do') }}
            </h2>
            <Button
                v-if="props.canWrite && !isAdding"
                variant="outline"
                size="sm"
                type="button"
                data-test="add-task"
                @click="isAdding = true"
            >
                <Plus class="size-4" />
                {{ t('Add a task') }}
            </Button>
        </div>

        <form
            v-if="isAdding"
            class="space-y-4 rounded-2xl border border-border bg-card p-4"
            @submit.prevent="save"
        >
            <div class="grid gap-2">
                <Label for="task-title">{{ t('What needs doing?') }}</Label>
                <Input
                    id="task-title"
                    v-model="form.title"
                    :placeholder="t('e.g. Call the owner back')"
                    autocomplete="off"
                    data-test="task-title"
                />
                <InputError :message="form.errors.title" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="task-assignee">{{ t('Who?') }}</Label>
                    <select
                        id="task-assignee"
                        v-model="form.assigned_to"
                        class="h-9 rounded-md border border-input bg-background px-3 text-sm focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        data-test="task-assignee"
                    >
                        <option :value="null">{{ t('Anyone') }}</option>
                        <option
                            v-for="member in props.members"
                            :key="member.id"
                            :value="member.id"
                        >
                            {{ member.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.assigned_to" />
                </div>

                <div class="grid gap-2">
                    <Label for="task-due">{{ t('Due') }}</Label>
                    <Input
                        id="task-due"
                        v-model="form.due_at"
                        type="datetime-local"
                        data-test="task-due"
                    />
                    <InputError :message="form.errors.due_at" />
                </div>

                <div class="grid gap-2">
                    <Label for="task-remind">{{ t('Remind me') }}</Label>
                    <Input
                        id="task-remind"
                        v-model="form.remind_at"
                        type="datetime-local"
                        aria-describedby="remind-hint"
                        data-test="task-remind"
                    />
                    <InputError :message="form.errors.remind_at" />
                </div>
            </div>

            <p id="remind-hint" class="text-xs text-muted-foreground">
                {{
                    t(
                        'Reminders are saved now — we’re switching the nudges on soon.',
                    )
                }}
            </p>

            <div class="flex justify-end gap-2">
                <Button
                    type="submit"
                    variant="brand"
                    size="sm"
                    :disabled="form.processing || form.title.trim() === ''"
                    data-test="save-task"
                >
                    {{ t('Add task') }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="isAdding = false"
                >
                    {{ t('Cancel') }}
                </Button>
            </div>
        </form>

        <p
            v-if="!props.tasks.length"
            class="rounded-2xl border border-dashed border-border bg-card/50 p-5 text-sm text-muted-foreground"
            data-test="no-tasks"
        >
            {{ t('Nothing to do on this lead yet.') }}
        </p>

        <ul v-else class="space-y-2">
            <li
                v-for="task in [...open, ...done]"
                :key="task.id"
                class="flex items-start gap-3 rounded-2xl border border-border bg-card p-4"
                :data-test="`task-${task.id}`"
            >
                <Checkbox
                    :id="`task-done-${task.id}`"
                    class="mt-0.5"
                    :model-value="task.status === 'done'"
                    :disabled="!task.can_complete"
                    :data-test="`complete-task-${task.id}`"
                    @update:model-value="setDone(task, $event === true)"
                />

                <div class="min-w-0 flex-1 space-y-1">
                    <Label
                        :for="`task-done-${task.id}`"
                        class="block text-sm font-normal"
                        :class="
                            task.status === 'done'
                                ? 'text-muted-foreground line-through'
                                : 'text-foreground'
                        "
                        dir="auto"
                    >
                        {{ task.title }}
                    </Label>
                    <p
                        class="flex flex-wrap items-center gap-2 font-mono text-xs text-muted-foreground"
                    >
                        <span
                            v-if="task.assignee"
                            class="font-sans"
                            dir="auto"
                            >{{ task.assignee.name }}</span
                        >
                        <span
                            v-if="task.due_at"
                            class="flex items-center gap-1"
                        >
                            <CalendarClock class="size-3" aria-hidden="true" />
                            <bdi>{{ date(task.due_at) }}</bdi>
                        </span>
                        <!-- Late is said in words as well as colour. -->
                        <Badge v-if="task.is_overdue" variant="warning">{{
                            t('Overdue')
                        }}</Badge>
                        <time
                            v-if="task.completed_at"
                            :datetime="task.completed_at"
                            >{{ dateTime(task.completed_at) }}</time
                        >
                    </p>
                </div>

                <button
                    v-if="task.can_delete"
                    type="button"
                    class="flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    :title="t('Delete')"
                    :aria-label="
                        t('Delete the task “:title”', { title: task.title })
                    "
                    :data-test="`delete-task-${task.id}`"
                    @click="remove(task)"
                >
                    <Trash2 class="size-3.5" />
                </button>
            </li>
        </ul>
    </section>
</template>
