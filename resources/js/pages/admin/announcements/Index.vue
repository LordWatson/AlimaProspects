<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import type { AdminAnnouncement, AdminAnnouncementOptions } from '@/types';

/*
 * Writing announcements (Phase 19b).
 *
 * The one admin screen with an **Arabic field**, and the asymmetry is deliberate: English is
 * required because it is the fallback every reader gets, and Arabic is optional because an operator
 * with an urgent outage notice should not have to wait for a translation. A maintenance notice in
 * one language beats silence in two — and the help text says so, so nobody reads the optional
 * Arabic field as us not caring.
 *
 * Brand (§2a): the level maps onto `status.*` tokens, never Signal Orange.
 */
const props = defineProps<{
    announcements: AdminAnnouncement[];
    options: AdminAnnouncementOptions;
}>();

const { utcDateTime } = useFormat();

const form = useForm({
    level: props.options.levels[0]?.value ?? 'info',
    title: { en: '', ar: '' },
    body: { en: '', ar: '' },
    plans: [] as string[],
    markets: [] as string[],
    starts_at: '',
    ends_at: '',
    is_dismissible: true,
    notify: false,
});

const selectedLevel = computed(() =>
    props.options.levels.find((l) => l.value === form.level),
);

function toggle(list: 'plans' | 'markets', value: string, on: boolean): void {
    form[list] = on
        ? [...form[list], value]
        : form[list].filter((v) => v !== value);
}

/**
 * Which row is asking "are you sure?".
 *
 * Two clicks, as on the team screen: taking an announcement down is the row-level action next to a
 * list of similar-looking rows, and a single press is the easiest misclick on the page. A notice
 * removed by accident is a notice the tenants it was for never read.
 */
const confirmingRetire = ref<number | null>(null);

function retire(id: number): void {
    router.delete(`/admin/announcements/${id}`, {
        preserveScroll: true,
        onFinish: () => {
            confirmingRetire.value = null;
        },
    });
}

function submit(): void {
    form.post('/admin/announcements', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Announcements" />

    <div class="flex max-w-3xl flex-col gap-8">
        <header>
            <p
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                Platform
            </p>
            <h1 class="mt-1 text-2xl font-semibold text-foreground">
                Announcements
            </h1>
            <p class="mt-2 max-w-prose text-sm text-muted-foreground">
                A banner on every targeted tenant's screens, and optionally a
                notification. Taking one down keeps the record — “when did we
                say the product would be down?” is a question somebody asks
                afterwards.
            </p>
        </header>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <h2 class="text-lg font-semibold text-foreground">New notice</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <Label for="level">Level</Label>
                    <NativeSelect id="level" v-model="form.level">
                        <option
                            v-for="l in options.levels"
                            :key="l.value"
                            :value="l.value"
                        >
                            {{ l.label }}
                        </option>
                    </NativeSelect>
                    <p
                        v-if="selectedLevel && !selectedLevel.dismissible"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Not dismissible by default — “we are aware of an outage”
                        should not disappear because somebody clicked a cross.
                    </p>
                </div>

                <div class="flex items-end gap-4">
                    <div class="flex items-center gap-2">
                        <Checkbox
                            id="is_dismissible"
                            v-model="form.is_dismissible"
                        />
                        <Label for="is_dismissible">Dismissible</Label>
                    </div>
                    <div class="flex items-center gap-2">
                        <Checkbox id="notify" v-model="form.notify" />
                        <Label for="notify">Also notify</Label>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <Label for="title_en">Title (English)</Label>
                    <Input id="title_en" v-model="form.title.en" required />
                    <InputError :message="form.errors['title.en']" />
                </div>
                <div>
                    <Label for="title_ar">Title (Arabic)</Label>
                    <Input
                        id="title_ar"
                        v-model="form.title.ar"
                        lang="ar"
                        dir="rtl"
                    />
                </div>

                <div class="sm:col-span-2">
                    <Label for="body_en">Body (English)</Label>
                    <Textarea
                        id="body_en"
                        v-model="form.body.en"
                        rows="3"
                        required
                    />
                    <InputError :message="form.errors['body.en']" />
                </div>
                <div class="sm:col-span-2">
                    <Label for="body_ar">Body (Arabic)</Label>
                    <Textarea
                        id="body_ar"
                        v-model="form.body.ar"
                        rows="3"
                        lang="ar"
                        dir="rtl"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        English is required because it is the fallback every
                        reader gets. Arabic is optional so an urgent notice does
                        not wait for a translation.
                    </p>
                </div>
            </div>

            <fieldset class="grid gap-3 sm:grid-cols-2">
                <legend class="mb-2 text-sm font-medium text-foreground">
                    Who sees it (nothing ticked means everybody)
                </legend>

                <div class="flex flex-col gap-1">
                    <span class="text-xs text-muted-foreground">Plans</span>
                    <div
                        v-for="p in options.plans"
                        :key="p.value"
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :id="`plan-${p.value}`"
                            :model-value="form.plans.includes(p.value)"
                            @update:model-value="
                                toggle('plans', p.value, Boolean($event))
                            "
                        />
                        <Label :for="`plan-${p.value}`">{{ p.label }}</Label>
                    </div>
                </div>

                <div class="flex flex-col gap-1">
                    <span class="text-xs text-muted-foreground">Markets</span>
                    <div
                        v-for="m in options.markets"
                        :key="m.value"
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :id="`market-${m.value}`"
                            :model-value="form.markets.includes(m.value)"
                            @update:model-value="
                                toggle('markets', m.value, Boolean($event))
                            "
                        />
                        <Label :for="`market-${m.value}`">{{ m.label }}</Label>
                    </div>
                </div>
            </fieldset>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <Label for="starts_at">Starts, UTC (optional)</Label>
                    <Input
                        id="starts_at"
                        v-model="form.starts_at"
                        type="datetime-local"
                    />
                </div>
                <div>
                    <Label for="ends_at">Ends, UTC (optional)</Label>
                    <Input
                        id="ends_at"
                        v-model="form.ends_at"
                        type="datetime-local"
                    />
                    <InputError :message="form.errors.ends_at" />
                </div>
            </div>

            <div>
                <Button type="submit" :disabled="form.processing">
                    Publish
                </Button>
            </div>
        </form>

        <section class="flex flex-col gap-3" aria-label="Published">
            <h2 class="text-lg font-semibold text-foreground">Published</h2>

            <EmptyState
                v-if="announcements.length === 0"
                title="Nothing published yet"
                description="Announcements appear as a banner on every tenant they target."
            />

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="a in announcements"
                    :key="a.id"
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-2xl border border-border bg-card px-4 py-3"
                >
                    <Badge :variant="a.variant">{{ a.level }}</Badge>

                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-foreground">
                            {{ a.title }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ utcDateTime(a.starts_at) }} UTC
                            <template v-if="a.ends_at">
                                → {{ utcDateTime(a.ends_at) }}
                            </template>
                            <template v-else>· no end date</template>
                            <template v-if="a.notified_at">
                                · notified {{ utcDateTime(a.notified_at) }} UTC
                            </template>
                            <template v-else-if="a.notify">
                                · notification queued
                            </template>
                        </p>
                    </div>

                    <Badge
                        v-if="a.plans && a.plans.length > 0"
                        variant="outline"
                    >
                        {{ a.plans.join(', ') }}
                    </Badge>
                    <Badge
                        v-if="a.markets && a.markets.length > 0"
                        variant="outline"
                    >
                        {{ a.markets.join(', ') }}
                    </Badge>

                    <!--
                      Two clicks, and the second one is `status.error`. The confirm button takes
                      focus, so a keyboard user is not dropped to the body when the first one
                      disappears.
                    -->
                    <template v-if="!a.ends_at">
                        <Button
                            v-if="confirmingRetire !== a.id"
                            variant="outline"
                            size="sm"
                            :aria-label="`Take down ${a.title}`"
                            @click="confirmingRetire = a.id"
                        >
                            Take down
                        </Button>
                        <template v-else>
                            <Button
                                v-focus
                                variant="destructive"
                                size="sm"
                                @click="retire(a.id)"
                            >
                                Take it down
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="confirmingRetire = null"
                            >
                                Cancel
                            </Button>
                        </template>
                    </template>
                </li>
            </ul>
        </section>
    </div>
</template>
