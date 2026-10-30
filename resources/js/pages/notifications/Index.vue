<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, CheckCheck, Settings2 } from '@lucide/vue';
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
const { dateTime } = useFormat();

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
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Notifications') }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold text-foreground">
                    {{ t('What Alima has told you') }}
                </h1>
                <p
                    v-if="unreadCount > 0"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ t(':count unread', { count: unreadCount }) }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="unreadCount > 0"
                    variant="outline"
                    data-test="mark-all-read"
                    @click="markAllRead"
                >
                    <CheckCheck class="size-4" />
                    {{ t('Mark all read') }}
                </Button>
                <Button variant="outline" as-child>
                    <Link href="/settings/notifications">
                        <Settings2 class="size-4" />
                        {{ t('Notification settings') }}
                    </Link>
                </Button>
            </div>
        </header>

        <ul
            v-if="notifications.data.length"
            class="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card"
            data-test="notifications"
        >
            <li
                v-for="notification in notifications.data"
                :key="notification.id"
                class="flex items-start gap-3 p-4"
                :class="notification.read_at ? '' : 'bg-lime-20/40'"
            >
                <span
                    v-if="!notification.read_at"
                    class="mt-2 size-2 shrink-0 rounded-full bg-lime"
                    :aria-label="t('Unread')"
                />
                <span v-else class="mt-2 size-2 shrink-0" aria-hidden="true" />

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-foreground">
                        {{ t(notification.title, notification.params) }}
                    </p>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        {{ t(notification.body, notification.params) }}
                    </p>
                    <p
                        v-if="notification.created_at"
                        class="mt-1 font-mono text-xs text-muted-foreground"
                    >
                        {{ dateTime(notification.created_at) }}
                    </p>
                </div>

                <div class="flex shrink-0 gap-1">
                    <Button
                        v-if="notification.url"
                        size="sm"
                        variant="ghost"
                        as-child
                    >
                        <Link
                            :href="notification.url"
                            @click="markRead(notification)"
                        >
                            {{ t('Open') }}
                        </Link>
                    </Button>
                    <Button
                        v-if="!notification.read_at"
                        size="sm"
                        variant="ghost"
                        @click="markRead(notification)"
                    >
                        {{ t('Mark read') }}
                    </Button>
                </div>
            </li>
        </ul>

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
        />
    </div>
</template>
