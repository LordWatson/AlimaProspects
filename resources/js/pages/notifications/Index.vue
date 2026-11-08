<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, CheckCheck, ChevronRight, Settings2 } from '@lucide/vue';
import { computed } from 'vue';
import PageHero from '@/components/PageHero.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { AppNotification, PaginationLink } from '@/types';

/*
 * Everything Alima has told this member, in this team (Phase 12).
 *
 * The full list behind the bell. Read ones stay — they are the record of what the member was told
 * and when, which is the question "did anyone know about this?" gets answered from. The retention
 * sweep clears the read ones after 90 days; unread ones are kept whatever their age, because a
 * member who has not looked has not been told.
 *
 * Two changes from the rebuild, both about reading a list of twenty of these rather than one:
 *
 *  - **Days are grouped and times are relative.** "2 hours ago" under a "Today" heading is the
 *    answer to the question somebody actually has; a column of absolute timestamps makes them do
 *    the arithmetic against a clock, twenty times. The exact moment is still there, as the line's
 *    `title`, because for "did anyone know?" it is the only thing that matters.
 *  - **A notification with somewhere to go is clickable across its whole width**, and opening it
 *    marks it read, which it already did from the small "Open" button nobody aims at on a phone.
 *
 * Every line is composed here from a key plus params (ADR 0007). Brand: cream page, Acid Lime as
 * the unread marker — never Signal Orange, which is decorative only (brand-tokens.md §2.1).
 */
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notifications', href: '/notifications' }],
    },
});

const props = defineProps<{
    notifications: {
        data: AppNotification[];
        links: PaginationLink[];
        meta: {
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
        };
    };
    unreadCount: number;
}>();

const { t } = useI18n();
const { date, dateTime, relative, dayKey, number } = useFormat();

/**
 * The rows, split into days in the team's timezone.
 *
 * The heading is "Today" or "Yesterday" where that is what it is, and the date otherwise — the
 * two words people use for the only two days they are likely to care about. A notification raised
 * at 23:30 in Dubai belongs to Tuesday for a Dubai team, which is why the day comes from
 * `dayKey` (team timezone) rather than from the reader's own clock.
 */
const days = computed(() => {
    const today = dayKey(new Date());
    const yesterday = dayKey(Date.now() - 86_400_000);

    const groups: { key: string; label: string; rows: AppNotification[] }[] =
        [];

    for (const notification of props.notifications.data) {
        // A notification with no timestamp is a data oddity rather than a day of its own; it joins
        // whichever group is open, or starts an unlabelled one.
        const key = notification.created_at
            ? dayKey(notification.created_at)
            : (groups.at(-1)?.key ?? 'unknown');

        let group = groups.find((candidate) => candidate.key === key);

        if (!group) {
            group = {
                key,
                label:
                    key === today
                        ? t('Today')
                        : key === yesterday
                          ? t('Yesterday')
                          : key === 'unknown'
                            ? t('Earlier')
                            : // Midday UTC, so formatting the key back into the team's own
                              // timezone cannot land on the day either side of it.
                              date(`${key}T12:00:00Z`),
                rows: [],
            };
            groups.push(group);
        }

        group.rows.push(notification);
    }

    return groups;
});

function markRead(notification: AppNotification): void {
    router.post(
        `/notifications/${notification.id}/read`,
        {},
        { preserveScroll: true },
    );
}

function markAllRead(): void {
    router.post('/notifications/read-all', {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Notifications')" />

    <div class="flex flex-col gap-6 py-2">
        <PageHero
            :eyebrow="t('Notifications')"
            :title="t('What Alima|has told you.')"
            :description="
                unreadCount > 0
                    ? t(':count unread', { count: number(unreadCount) })
                    : null
            "
            size="compact"
        >
            <template #actions>
                <Button
                    v-if="unreadCount > 0"
                    variant="lime"
                    data-test="mark-all-read"
                    @click="markAllRead"
                >
                    <CheckCheck class="size-4" />
                    {{ t('Mark all read') }}
                </Button>
                <Button variant="hero" as-child>
                    <Link href="/settings/notifications">
                        <Settings2 class="size-4" />
                        {{ t('Notification settings') }}
                    </Link>
                </Button>
            </template>
        </PageHero>

        <div
            v-if="notifications.data.length"
            class="space-y-6"
            data-test="notifications"
        >
            <section v-for="day in days" :key="day.key" class="space-y-2">
                <h2
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ day.label }}
                </h2>

                <ul
                    class="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card"
                >
                    <li
                        v-for="notification in day.rows"
                        :key="notification.id"
                        class="group/row relative flex items-start gap-3 p-4 transition-colors"
                        :class="
                            notification.read_at
                                ? 'hover:bg-accent/40'
                                : 'bg-lime-20/40 hover:bg-lime-20/60'
                        "
                    >
                        <span
                            v-if="!notification.read_at"
                            class="mt-2 size-2 shrink-0 rounded-full bg-lime"
                            aria-hidden="true"
                        />
                        <span
                            v-else
                            class="mt-2 size-2 shrink-0"
                            aria-hidden="true"
                        />

                        <div class="min-w-0 flex-1">
                            <p
                                class="text-sm font-semibold text-foreground"
                                dir="auto"
                            >
                                <!-- The whole line is the link where there is somewhere to go,
                                     and opening it marks it read. -->
                                <Link
                                    v-if="notification.url"
                                    :href="notification.url"
                                    class="rounded-sm underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                    @click="markRead(notification)"
                                >
                                    <!-- Unread is a state, so it is said rather than left to the
                                         lime dot alone (WCAG 1.4.1). -->
                                    <span
                                        v-if="!notification.read_at"
                                        class="sr-only"
                                        >{{ t('Unread') }} —
                                    </span>
                                    {{
                                        t(
                                            notification.title,
                                            notification.params,
                                        )
                                    }}
                                    <span
                                        class="absolute inset-0"
                                        aria-hidden="true"
                                    />
                                </Link>
                                <template v-else>
                                    <span
                                        v-if="!notification.read_at"
                                        class="sr-only"
                                        >{{ t('Unread') }} —
                                    </span>
                                    {{
                                        t(
                                            notification.title,
                                            notification.params,
                                        )
                                    }}
                                </template>
                            </p>
                            <p
                                class="mt-0.5 text-sm text-muted-foreground"
                                dir="auto"
                            >
                                {{ t(notification.body, notification.params) }}
                            </p>
                            <p
                                v-if="notification.created_at"
                                class="mt-1 font-mono text-xs text-muted-foreground"
                            >
                                <!-- Relative for reading, exact on hover for the record — and
                                     raised clear of the line's stretched link, which would
                                     otherwise sit over the timestamp and swallow the tooltip.
                                     Hovering the time to read the time is the one place in the
                                     row where not navigating is the right answer. -->
                                <time
                                    :datetime="notification.created_at"
                                    class="relative z-10"
                                    :title="dateTime(notification.created_at)"
                                    >{{
                                        relative(notification.created_at)
                                    }}</time
                                >
                            </p>
                        </div>

                        <!-- Raised above the line's stretched link so the button still works. -->
                        <div
                            class="relative z-10 flex shrink-0 items-center gap-1"
                        >
                            <!-- Twenty rows mean twenty buttons called "Mark read"; the
                                 accessible name says which one. -->
                            <Button
                                v-if="!notification.read_at"
                                size="sm"
                                variant="ghost"
                                :aria-label="
                                    t('Mark “:title” read', {
                                        title: t(
                                            notification.title,
                                            notification.params,
                                        ),
                                    })
                                "
                                @click="markRead(notification)"
                            >
                                {{ t('Mark read') }}
                            </Button>
                            <ChevronRight
                                v-if="notification.url"
                                class="size-4 text-muted-foreground opacity-0 transition-opacity group-hover/row:opacity-100 rtl:rotate-180"
                                aria-hidden="true"
                            />
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <EmptyState
            v-else
            :icon="Bell"
            :title="t('Nothing yet')"
            :description="
                t(
                    'Alima will tell you when a search finishes, a strong match turns up, or a reminder comes due.',
                )
            "
            data-test="no-notifications"
        >
            <Button variant="outline" as-child>
                <Link href="/settings/notifications">
                    {{ t('Notification settings') }}
                </Link>
            </Button>
        </EmptyState>

        <Pagination
            v-if="notifications.meta.last_page > 1"
            :links="notifications.links"
            :meta="notifications.meta"
            label="Pages of notifications"
        />
    </div>
</template>
