<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BellOff, Smartphone } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    DigestFrequency,
    DigestFrequencyOption,
    NotificationChannel,
    NotificationChannelOption,
    NotificationGroupOption,
    NotificationPreferenceMap,
    NotificationPreferencesForm,
    NotificationTypeOption,
} from '@/types';

/*
 * Notification settings (Phase 12).
 *
 * A grid: a row per kind of notification, a column per channel. That shape is chosen because the
 * question a member actually has is "where will this reach me", and a list of switches labelled
 * "Email me when a search finishes" answers it one notification at a time and is three times as
 * long.
 *
 * Two things the screen is careful about:
 *
 *  - It shows the **effective** channels — defaults included — so an unvisited screen tells the
 *    truth rather than showing everything off while notifications arrive.
 *  - A type that is not this member's to receive is shown **greyed with a reason**, not hidden. A
 *    plain member who cannot see "AI credit is running low" would not know somebody is being told.
 *
 * Brand: cream page, Deep Teal on the digest card, status tokens for the mute note. Signal Orange
 * appears nowhere — everything here is a state (brand-tokens.md §2.1).
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Notification settings', href: '/settings/notifications' },
        ],
    },
});

const props = defineProps<{
    preferences: NotificationPreferenceMap;
    digest: {
        frequency: DigestFrequency;
        hour: number;
        sent_at: string | null;
    };
    mutedUntil: string | null;
    options: {
        types: NotificationTypeOption[];
        channels: NotificationChannelOption[];
        digests: DigestFrequencyOption[];
        groups: NotificationGroupOption[];
    };
    /** The team's timezone, which is the clock the digest hour is in (CLAUDE.md §2). */
    timezone: string;
    pushDevices: number;
}>();

const { t } = useI18n();
// The team's timezone and the app's locale, not the browser's — this page's own copy makes a
// point of the digest hour being on the team's clock.
const { dateTime } = useFormat();

const form = useForm<NotificationPreferencesForm>({
    // Cloned, so toggling a box doesn't mutate the prop and leave the form unable to reset.
    channels: Object.fromEntries(
        Object.entries(props.preferences).map(([type, channels]) => [
            type,
            [...channels],
        ]),
    ),
    digest: props.digest.frequency,
    digest_hour: props.digest.hour,
    muted_until: props.mutedUntil,
});

/** The types, grouped the way the server said to group them. */
const grouped = computed(() =>
    props.options.groups
        .map((group) => ({
            ...group,
            types: props.options.types.filter(
                (type) => type.group === group.value,
            ),
        }))
        .filter((group) => group.types.length > 0),
);

function isOn(type: string, channel: NotificationChannel): boolean {
    return (form.channels[type] ?? []).includes(channel);
}

function toggle(type: string, channel: NotificationChannel, on: boolean): void {
    const current = form.channels[type] ?? [];

    form.channels = {
        ...form.channels,
        [type]: on
            ? [...current, channel]
            : current.filter((value) => value !== channel),
    };
}

/** The hours, as the member's own clock would label them. */
const hours = computed(() =>
    Array.from({ length: 24 }, (_, hour) => ({
        value: hour,
        label: `${String(hour).padStart(2, '0')}:00`,
    })),
);

const isMuted = computed(
    () => form.muted_until !== null && new Date(form.muted_until) > new Date(),
);

function mute(days: number): void {
    const until = new Date();
    until.setDate(until.getDate() + days);
    form.muted_until = until.toISOString();
}

function unmute(): void {
    form.muted_until = null;
}

function submit(): void {
    form.patch('/settings/notifications', { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Notification settings')" />

    <h1 class="sr-only">{{ t('Notification settings') }}</h1>

    <form class="space-y-10" @submit.prevent="submit">
        <Heading
            variant="small"
            :title="t('Notification settings')"
            :description="
                t('Choose what Alima tells you, and where it reaches you.')
            "
        />

        <!-- The grid -->
        <section v-for="group in grouped" :key="group.value" class="space-y-3">
            <h2
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                {{ t(group.label) }}
            </h2>

            <div class="overflow-hidden rounded-2xl border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50">
                        <tr>
                            <th scope="col" class="p-3 text-start font-medium">
                                <span class="sr-only">{{
                                    t('Notification')
                                }}</span>
                            </th>
                            <th
                                v-for="channel in options.channels"
                                :key="channel.value"
                                scope="col"
                                class="p-3 text-center font-mono text-xs font-medium tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                            >
                                {{ t(channel.label) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="type in group.types"
                            :key="type.value"
                            class="border-t border-border"
                        >
                            <th scope="row" class="p-3 text-start font-normal">
                                <span class="block font-medium text-foreground">
                                    {{ t(type.label) }}
                                </span>
                                <span
                                    class="mt-0.5 block text-xs text-muted-foreground"
                                >
                                    {{ t(type.description) }}
                                </span>
                                <!-- Said, not hidden: a member who cannot see this would not know
                                     somebody is being told. -->
                                <Badge
                                    v-if="!type.available"
                                    variant="outline"
                                    class="mt-1.5"
                                >
                                    {{ t('Owners and admins only') }}
                                </Badge>
                            </th>

                            <td
                                v-for="channel in options.channels"
                                :key="channel.value"
                                class="p-3 text-center"
                                :class="type.available ? '' : 'opacity-60'"
                            >
                                <Checkbox
                                    :id="`${type.value}-${channel.value}`"
                                    :model-value="
                                        isOn(type.value, channel.value)
                                    "
                                    :disabled="!type.available"
                                    :aria-label="
                                        t(':channel for :type', {
                                            channel: t(channel.label),
                                            type: t(type.label),
                                        })
                                    "
                                    :data-test="`${type.value}-${channel.value}`"
                                    @update:model-value="
                                        toggle(
                                            type.value,
                                            channel.value,
                                            $event === true,
                                        )
                                    "
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Push -->
        <section
            class="flex gap-3 rounded-2xl border border-border bg-card p-5"
        >
            <Smartphone
                class="mt-0.5 size-5 shrink-0 text-brand"
                aria-hidden="true"
            />
            <div class="text-sm">
                <p class="font-semibold text-foreground">{{ t('Push') }}</p>
                <p v-if="pushDevices > 0" class="mt-1 text-muted-foreground">
                    {{
                        t('Set up on :count of your devices.', {
                            count: pushDevices,
                        })
                    }}
                </p>
                <p v-else class="mt-1 text-muted-foreground">
                    {{
                        t(
                            'Nothing registered yet. Sign in to the Alima app on your phone and allow notifications — these switches will start working then.',
                        )
                    }}
                </p>
            </div>
        </section>

        <!-- The round-up -->
        <section class="space-y-4 rounded-2xl border border-border bg-card p-5">
            <h2
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                {{ t('The round-up') }}
            </h2>

            <p class="text-sm text-muted-foreground">
                {{
                    t(
                        'One email with everything that happened, instead of one per thing. It only arrives when there is something to say.',
                    )
                }}
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="digest">{{ t('How often') }}</Label>
                    <NativeSelect id="digest" v-model="form.digest">
                        <option
                            v-for="option in options.digests"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ t(option.label) }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.digest" />
                </div>

                <div v-if="form.digest !== 'off'" class="space-y-2">
                    <Label for="digest-hour">{{ t('What time') }}</Label>
                    <NativeSelect id="digest-hour" v-model="form.digest_hour">
                        <option
                            v-for="hour in hours"
                            :key="hour.value"
                            :value="hour.value"
                        >
                            {{ hour.label }}
                        </option>
                    </NativeSelect>
                    <!-- The hour is in the team's timezone, and the screen has to say which. -->
                    <p class="text-xs text-muted-foreground">
                        {{
                            t('In :timezone, your team’s timezone.', {
                                timezone,
                            })
                        }}
                    </p>
                    <InputError :message="form.errors.digest_hour" />
                </div>
            </div>
        </section>

        <!-- The mute -->
        <section class="space-y-4 rounded-2xl border border-border bg-card p-5">
            <div class="flex gap-3">
                <BellOff
                    class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div class="text-sm">
                    <h2 class="font-semibold text-foreground">
                        {{ t('Quiet time') }}
                    </h2>
                    <p class="mt-1 text-muted-foreground">
                        {{
                            t(
                                'Stops the emails and the buzzing. The bell keeps working, so you can still catch up.',
                            )
                        }}
                    </p>
                </div>
            </div>

            <p
                v-if="isMuted"
                class="rounded-xl border border-warning/30 bg-warning/10 p-3 text-xs text-warning"
                data-test="muted"
            >
                {{
                    t('Muted until :date.', {
                        date: dateTime(form.muted_until as string),
                    })
                }}
            </p>

            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="mute(1)"
                >
                    {{ t('For a day') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="mute(7)"
                >
                    {{ t('For a week') }}
                </Button>
                <Button
                    v-if="isMuted"
                    type="button"
                    variant="ghost"
                    size="sm"
                    data-test="unmute"
                    @click="unmute"
                >
                    {{ t('Turn it back on') }}
                </Button>
            </div>
        </section>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing" data-test="save">
                {{ t('Save') }}
            </Button>
            <p
                v-if="form.recentlySuccessful"
                class="text-sm text-muted-foreground"
            >
                {{ t('Saved.') }}
            </p>
        </div>
    </form>
</template>
