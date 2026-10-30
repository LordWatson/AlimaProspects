<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, CheckCheck } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { AppNotification, NotificationBroadcast } from '@/types';

/*
 * The bell in the app header (Phase 12).
 *
 * It reads the **shared** `notifications` prop rather than fetching, because the bell is part of
 * the shell: every controller in the product would otherwise have to remember to load it, and the
 * one that forgot would show a member zero unread while they had five.
 *
 * Reverb keeps it live. A notification arriving while the member is looking raises a toast and
 * bumps the count without a page load, on their **own** private channel — `teams.{id}` would show
 * one member's reminders to everybody.
 *
 * Every line is composed here from a key plus params: the row may have been written days ago and
 * the member may since have switched the app to Arabic (ADR 0007).
 *
 * Brand: the unread marker is Acid Lime — the shell's hero accent, decorative. Never Signal Orange,
 * which is decorative only and never a status (brand-tokens.md §2.1).
 */
const { t } = useI18n();
const { dateTime } = useFormat();
const page = usePage();

const bell = computed(() => page.props.notifications);

/** Notifications that have arrived over Reverb since this page was rendered, newest first. */
const live = ref<AppNotification[]>([]);

const items = computed<AppNotification[]>(() => [
    ...live.value,
    ...(bell.value?.items ?? []),
]);

const unread = computed(() => (bell.value?.unread ?? 0) + live.value.length);

/** 9+ rather than a three-digit badge nobody can read. */
const badge = computed(() => (unread.value > 9 ? '9+' : String(unread.value)));

function sentence(notification: AppNotification): string {
    return t(notification.body, notification.params);
}

function headline(notification: AppNotification): string {
    return t(notification.title, notification.params);
}

function markRead(notification: AppNotification): void {
    // Dropped from the live list straight away: the server knows, and waiting for the round trip
    // would leave the member's click looking ignored.
    live.value = live.value.filter((item) => item.id !== notification.id);

    router.post(
        `/notifications/${notification.id}/read`,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function markAllRead(): void {
    live.value = [];

    router.post(
        '/notifications/read-all',
        {},
        { preserveScroll: true, preserveState: true },
    );
}

// --- Reverb ---------------------------------------------------------------------------------------
const userId = computed(() => page.props.auth.user?.id ?? null);
let channelName: string | null = null;

function onCreated(payload: NotificationBroadcast): void {
    live.value = [
        {
            id: payload.id,
            type: payload.type,
            title: payload.title,
            body: payload.body,
            params: payload.params,
            url: payload.url,
            read_at: null,
            created_at: payload.created_at,
        },
        ...live.value,
    ];

    // A toast as well as the badge: a member looking at the screen should not have to notice a
    // number change.
    toast(t(payload.title, payload.params), {
        description: t(payload.body, payload.params),
    });
}

onMounted(() => {
    if (userId.value == null || !window.Echo) return;

    // Laravel's own per-user notification channel, which `routes/channels.php` already authorises.
    channelName = `App.Models.User.${userId.value}`;
    window.Echo.private(channelName).listen('.notification.created', onCreated);
});

onBeforeUnmount(() => {
    if (channelName && window.Echo) {
        window.Echo.leave(channelName);
    }
});
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon-sm"
                class="relative"
                :aria-label="
                    unread > 0
                        ? t(':count unread notifications', { count: unread })
                        : t('Notifications')
                "
                data-test="notification-bell"
            >
                <Bell class="size-5" />
                <span
                    v-if="unread > 0"
                    class="absolute -end-0.5 -top-0.5 inline-flex min-w-4 items-center justify-center rounded-full bg-lime px-1 font-mono text-xs leading-4 font-semibold text-ink"
                    aria-hidden="true"
                >
                    {{ badge }}
                </span>
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent
            class="w-80 rounded-xl p-0"
            align="end"
            :side-offset="8"
        >
            <div
                class="flex items-center justify-between gap-2 border-b border-border px-4 py-3"
            >
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Notifications') }}
                </p>
                <Button
                    v-if="unread > 0"
                    variant="ghost"
                    size="sm"
                    data-test="mark-all-read"
                    @click="markAllRead"
                >
                    <CheckCheck class="size-4" />
                    {{ t('Mark all read') }}
                </Button>
            </div>

            <div v-if="items.length" class="max-h-96 overflow-y-auto">
                <!--
                    Each row is a `DropdownMenuItem`, so arrow-key roving focus, typeahead and
                    close-on-activate all work. A notification with no URL renders as a
                    `<button>` rather than a clickable `<div>`, which was unreachable by
                    keyboard entirely.
                -->
                <DropdownMenuItem
                    v-for="notification in items"
                    :key="notification.id"
                    as-child
                    class="cursor-pointer border-b border-border px-4 py-3 last:border-0"
                    @select="markRead(notification)"
                >
                    <component
                        :is="notification.url ? Link : 'button'"
                        :href="notification.url ?? undefined"
                        :type="notification.url ? undefined : 'button'"
                        class="flex w-full items-start gap-2 text-start"
                    >
                        <!-- The dot is decoration. The state is said in words for a screen
                             reader and carried by the title's weight for a sighted one, because
                             Acid Lime on cream is ~1.3:1 and cannot carry meaning on its own
                             (brand-tokens.md §7.7; WCAG 1.4.1 and 1.4.11). -->
                        <span
                            v-if="!notification.read_at"
                            class="mt-1.5 size-2 shrink-0 rounded-full bg-lime ring-1 ring-ink/40"
                            aria-hidden="true"
                        />
                        <span
                            v-else
                            class="mt-1.5 size-2 shrink-0"
                            aria-hidden="true"
                        />

                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm text-foreground"
                                :class="
                                    notification.read_at
                                        ? 'font-normal'
                                        : 'font-semibold'
                                "
                            >
                                <span
                                    v-if="!notification.read_at"
                                    class="sr-only"
                                >
                                    {{ t('Unread') }}
                                </span>
                                {{ headline(notification) }}
                            </span>
                            <span
                                class="mt-0.5 block text-xs text-muted-foreground"
                            >
                                {{ sentence(notification) }}
                            </span>
                            <span
                                v-if="notification.created_at"
                                class="mt-1 block font-mono text-xs text-muted-foreground"
                            >
                                {{ dateTime(notification.created_at) }}
                            </span>
                        </span>
                    </component>
                </DropdownMenuItem>
            </div>

            <p
                v-else
                class="px-4 py-8 text-center text-sm text-muted-foreground"
            >
                {{
                    t(
                        'Nothing new. Alima will tell you when something happens.',
                    )
                }}
            </p>

            <div class="border-t border-border px-4 py-2">
                <Button
                    variant="ghost"
                    size="sm"
                    class="w-full justify-center"
                    as-child
                >
                    <Link href="/notifications">{{ t('See everything') }}</Link>
                </Button>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
