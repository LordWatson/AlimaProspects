<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    KeyRound,
    Plug,
    RefreshCw,
    Send,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CopyButton from '@/components/CopyButton.vue';
import InputError from '@/components/InputError.vue';
import PageHero from '@/components/PageHero.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    CrmIntegration,
    CrmProviderOption,
    WebhookDelivery,
    WebhookEndpoint,
    WebhookEventOption,
} from '@/types';

/*
 * The Integrations screen (PL7).
 *
 * One screen for two halves that are really one question — where does this team's data go when it
 * leaves Alima. The webhooks half is the one that works today; the CRM half shows what is coming
 * and says so plainly, because a Connect button that 404s reads as a broken product rather than an
 * unfinished one.
 *
 * Three things the screen is careful about:
 *
 *  - **The secret is shown once and the screen says so before it is minted.** There is no reveal
 *    button anywhere, because the value is never read back — a member who closes this banner
 *    rotates rather than retrieves.
 *  - **A high-volume event is flagged beside its checkbox.** `lead.created` fires once per company
 *    and a discovery run finds hundreds; somebody whose receiver appends a spreadsheet row should
 *    learn that before they tick it.
 *  - **The delivery log distinguishes "we switched it off" from "you switched it off".** They are
 *    different facts and a member whose endpoint stopped needs to know which.
 *
 * Brand: cream page, Ink hero, Deep Teal cards. Every state here is a **status**, so it uses the
 * `status.*` badge variants — Signal Orange appears once, as the hero's rule, and never as a
 * warning (brand-tokens.md §2.1).
 */
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Integrations', href: '/integrations' }],
    },
});

const props = defineProps<{
    canManage: boolean;
    endpoints: WebhookEndpoint[];
    deliveries: WebhookDelivery[];
    eventCatalogue: WebhookEventOption[];
    integrations: CrmIntegration[];
    providers: CrmProviderOption[];
    crmRemaining: number | null;
    newSecret: string | null;
}>();

const { t } = useI18n();
const { dateTime } = useFormat();
const page = usePage();

/**
 * For the one native form on this screen.
 *
 * `connect` ends in a redirect to the provider's own consent page, which an Inertia visit cannot
 * follow — so it is a real browser POST, and a real POST needs the token. Same precedent as the
 * compliance export form.
 */
const csrfToken = computed(() => page.props.csrfToken ?? '');

const addOpen = ref(false);

const form = useForm<{ url: string; events: string[]; is_active: boolean }>({
    url: '',
    // Pre-ticked with the two low-volume events, which is the set somebody building their first
    // automation almost always wants and the set that cannot flood a receiver.
    events: ['contact.enriched', 'lead.sent_to_crm'],
    is_active: true,
});

function toggleEvent(value: string, checked: boolean): void {
    form.events = checked
        ? [...form.events, value]
        : form.events.filter((event) => event !== value);
}

function submit(): void {
    form.post('/integrations/webhooks', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            addOpen.value = false;
        },
    });
}

/** The connected-ness of a CRM provider, where there is a row for it. */
function integrationFor(provider: string): CrmIntegration | undefined {
    return props.integrations.find(
        (integration) => integration.provider === provider,
    );
}

function statusVariant(
    status: CrmIntegration['status'],
): 'success' | 'warning' | 'error' | 'outline' {
    if (status === 'connected') return 'success';
    if (status === 'needs_reauth') return 'warning';
    if (status === 'error') return 'error';

    return 'outline';
}

function deliveryVariant(
    state: WebhookDelivery['state'],
): 'success' | 'outline' | 'error' {
    if (state === 'delivered') return 'success';

    // `outline`, not amber: pending is in progress rather than a problem, and spending the warning
    // colour on a non-problem is how a real warning stops being noticed (brand-tokens.md §7f.5).
    return state === 'pending' ? 'outline' : 'error';
}

/**
 * Whether this endpoint is off because **we** turned it off.
 *
 * `is_active` is the member's own switch and `disabled_at` is ours; the two are stored apart so
 * this question can be asked at all.
 */
function wasAutoDisabled(endpoint: WebhookEndpoint): boolean {
    return endpoint.disabled_at !== null;
}

function reactivate(endpoint: WebhookEndpoint): void {
    router.put(
        `/integrations/webhooks/${endpoint.id}`,
        { url: endpoint.url, events: endpoint.events, is_active: true },
        { preserveScroll: true },
    );
}

const hasEndpoints = computed(() => props.endpoints.length > 0);

/**
 * The two actions that cannot be taken back, held until somebody says so again.
 *
 * Rotating silently stops a receiver that has not been updated — which is exactly what a customer
 * rotating a leaked secret *wants*, and exactly what a mis-click must not do — and removing deletes
 * the endpoint and its log. One ref rather than two dialogs: the shape is identical and the only
 * thing that differs is the sentence.
 */
const confirming = ref<{
    endpoint: WebhookEndpoint;
    action: 'rotate' | 'remove';
} | null>(null);

function confirmed(): void {
    const pending = confirming.value;

    if (pending === null) {
        return;
    }

    confirming.value = null;

    if (pending.action === 'rotate') {
        router.post(
            `/integrations/webhooks/${pending.endpoint.id}/rotate`,
            {},
            { preserveScroll: true },
        );

        return;
    }

    router.delete(`/integrations/webhooks/${pending.endpoint.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="t('Integrations')" />

    <div class="flex flex-col gap-6 py-2">
        <PageHero
            :eyebrow="t('Integrations')"
            :title="t('Send it|somewhere else.')"
            :description="
                t(
                    'Post every new company, score change and trigger event to your own tools as it happens — signed, retried and logged. Your CRM comes next.',
                )
            "
            size="compact"
        />

        <!-- The secret, once ------------------------------------------------->
        <!--
          Deliberately not a dialog: a modal that can be dismissed by clicking beside it is the
          wrong container for the only moment this value exists. It is a banner that stays until
          the next navigation, and the copy says what happens if they lose it.
        -->
        <!--
          `role="status"` and `aria-live`: the banner arrives after a redirect, so without them a
          screen-reader user is never told that the only value they will ever be shown is on the
          page. And a copy button, because the alternative is selecting sixty-four characters by
          hand in the one moment they cannot be retrieved.
        -->
        <section
            v-if="newSecret"
            class="rounded-2xl border border-brand/30 bg-brand/12 p-6"
            role="status"
            aria-live="polite"
            data-test="new-secret"
        >
            <div class="flex items-start gap-3">
                <KeyRound class="mt-0.5 size-5 shrink-0 text-brand" />
                <div class="min-w-0 space-y-2">
                    <h2 class="font-semibold text-foreground">
                        {{ t('Copy your signing secret now') }}
                    </h2>
                    <!-- Body copy on a tint is foreground/80; muted does not clear AA there. -->
                    <p class="text-sm text-foreground/80">
                        {{
                            t(
                                'This is the only time Alima will show it. If you lose it, rotate the secret and update your receiver.',
                            )
                        }}
                    </p>
                    <div class="flex items-start gap-2">
                        <!--
                          `dir="ltr"` and `mono-latin`: under RTL the font token resolves to Tajawal
                          (app.css), and a proportional face makes `0`/`O` and `l`/`1` ambiguous —
                          which is precisely where a hand-transcribed secret fails.
                        -->
                        <p
                            class="mono-latin min-w-0 flex-1 overflow-x-auto rounded-xl bg-card p-3 font-mono text-sm break-all text-foreground"
                            dir="ltr"
                        >
                            {{ newSecret }}
                        </p>
                        <CopyButton
                            :value="newSecret"
                            :label="t('signing secret')"
                            class="mt-3"
                        />
                    </div>
                </div>
            </div>
        </section>

        <!-- Webhooks ---------------------------------------------------------->
        <section v-if="canManage" class="space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Webhooks') }}
                    </p>
                    <h2 class="mt-1 text-lg font-semibold text-foreground">
                        {{ t('Where Alima posts your events') }}
                    </h2>
                </div>

                <Button
                    v-if="!addOpen"
                    variant="brand"
                    data-test="add-endpoint"
                    @click="addOpen = true"
                >
                    <Plug class="size-4" />
                    {{ t('Add an endpoint') }}
                </Button>
            </div>

            <!-- The add form -------------------------------------------------->
            <form
                v-if="addOpen"
                class="space-y-5 rounded-2xl border border-border bg-card p-6"
                data-test="endpoint-form"
                @submit.prevent="submit"
            >
                <div class="space-y-2">
                    <Label for="endpoint-url">{{ t('HTTPS address') }}</Label>
                    <Input
                        id="endpoint-url"
                        v-model="form.url"
                        type="url"
                        inputmode="url"
                        dir="ltr"
                        placeholder="https://hooks.zapier.com/hooks/catch/..."
                        :aria-invalid="form.errors.url ? 'true' : undefined"
                        :aria-describedby="
                            form.errors.url
                                ? 'endpoint-url-hint endpoint-url-error'
                                : 'endpoint-url-hint'
                        "
                        required
                    />
                    <p
                        id="endpoint-url-hint"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            t(
                                'Must be a public https address. Alima will not post to a private or internal one.',
                            )
                        }}
                    </p>
                    <InputError
                        id="endpoint-url-error"
                        :message="form.errors.url"
                    />
                </div>

                <fieldset class="space-y-3">
                    <legend class="text-sm font-medium text-foreground">
                        {{ t('Which events') }}
                    </legend>

                    <label
                        v-for="option in eventCatalogue"
                        :key="option.value"
                        class="flex cursor-pointer items-start gap-3 rounded-xl p-2 transition-colors focus-within:ring-[3px] focus-within:ring-ring hover:bg-accent"
                    >
                        <Checkbox
                            :model-value="form.events.includes(option.value)"
                            class="mt-0.5"
                            @update:model-value="
                                (checked) =>
                                    toggleEvent(option.value, checked === true)
                            "
                        />
                        <span class="min-w-0 space-y-1">
                            <span
                                class="flex flex-wrap items-center gap-2 text-sm font-medium text-foreground"
                            >
                                <bdi class="font-mono">{{ option.value }}</bdi>
                                <!-- Status, not decoration: this is a warning about volume. -->
                                <Badge
                                    v-if="option.high_volume"
                                    variant="warning"
                                >
                                    {{ t('High volume') }}
                                </Badge>
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ t(option.description) }}
                            </span>
                        </span>
                    </label>

                    <InputError :message="form.errors.events" />
                </fieldset>

                <div class="space-y-2">
                    <div class="flex flex-wrap gap-3">
                        <Button
                            type="submit"
                            variant="brand"
                            :disabled="
                                form.processing || form.events.length === 0
                            "
                            aria-describedby="endpoint-submit-hint"
                        >
                            {{ t('Add endpoint') }}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="addOpen = false"
                        >
                            {{ t('Cancel') }}
                        </Button>
                    </div>
                    <!-- A disabled button with no reason beside it reads as a broken page. -->
                    <p
                        v-if="form.events.length === 0"
                        id="endpoint-submit-hint"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('Choose at least one event.') }}
                    </p>
                </div>
            </form>

            <!-- The endpoints ------------------------------------------------->
            <p
                v-if="!hasEndpoints"
                class="rounded-2xl border border-dashed border-border p-6 text-sm text-muted-foreground"
            >
                {{
                    t(
                        'No endpoints yet. Add one and Alima will post your events to it as they happen.',
                    )
                }}
            </p>

            <article
                v-for="endpoint in endpoints"
                :key="endpoint.id"
                class="space-y-4 rounded-2xl border border-border bg-card p-6"
                :aria-labelledby="`endpoint-url-${endpoint.id}`"
                :data-test="`endpoint-${endpoint.id}`"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <!--
                          Wrapped rather than truncated: a `title` tooltip is no help to a keyboard
                          or screen-reader user, and a catch-hook URL's distinguishing part is its
                          tail. `dir="ltr"` keeps it from right-aligning and clipping the wrong end
                          under RTL.
                        -->
                        <p
                            :id="`endpoint-url-${endpoint.id}`"
                            class="mono-latin font-mono text-sm break-all text-foreground"
                            dir="ltr"
                        >
                            {{ endpoint.url }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t('Secret ending :hint', {
                                    hint: endpoint.hint,
                                })
                            }}
                            <template v-if="endpoint.last_delivered_at">
                                ·
                                {{
                                    t('Last delivered :when', {
                                        when: dateTime(
                                            endpoint.last_delivered_at,
                                        ),
                                    })
                                }}
                            </template>
                        </p>
                    </div>

                    <Badge v-if="wasAutoDisabled(endpoint)" variant="error">
                        {{ t('Switched off by Alima') }}
                    </Badge>
                    <Badge v-else-if="!endpoint.is_active" variant="outline">
                        {{ t('Paused') }}
                    </Badge>
                    <Badge v-else variant="success">
                        {{ t('Active') }}
                    </Badge>
                </div>

                <p class="flex flex-wrap gap-2">
                    <Badge
                        v-for="event in endpoint.events"
                        :key="event"
                        variant="outline"
                    >
                        <bdi class="mono-latin font-mono">{{ event }}</bdi>
                    </Badge>
                </p>

                <!-- The colour stays on the icon; the words are foreground (brand-tokens.md §7f.3). -->
                <p
                    v-if="wasAutoDisabled(endpoint)"
                    class="flex items-start gap-2 text-sm text-foreground"
                >
                    <AlertTriangle
                        class="mt-0.5 size-4 shrink-0 text-destructive"
                    />
                    {{
                        t(
                            'This address failed :count times in a row, so Alima stopped sending. Fix it and switch it back on.',
                            { count: endpoint.failure_count },
                        )
                    }}
                </p>

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="wasAutoDisabled(endpoint) || !endpoint.is_active"
                        size="sm"
                        variant="brand"
                        @click="reactivate(endpoint)"
                    >
                        <Check class="size-4" />
                        {{ t('Switch on') }}
                    </Button>

                    <Button
                        size="sm"
                        variant="outline"
                        @click="
                            router.post(
                                `/integrations/webhooks/${endpoint.id}/test`,
                                {},
                                { preserveScroll: true },
                            )
                        "
                    >
                        <Send class="size-4" />
                        {{ t('Send a test event') }}
                    </Button>

                    <Button
                        size="sm"
                        variant="outline"
                        @click="confirming = { endpoint, action: 'rotate' }"
                    >
                        <RefreshCw class="size-4" />
                        {{ t('Rotate the secret') }}
                    </Button>

                    <Button
                        size="sm"
                        variant="ghost"
                        class="text-destructive hover:text-destructive"
                        @click="confirming = { endpoint, action: 'remove' }"
                    >
                        <Trash2 class="size-4" />
                        {{ t('Remove') }}
                    </Button>
                </div>
            </article>

            <!-- How to verify ------------------------------------------------->
            <!--
              On the screen rather than only in the docs: somebody building a receiver has this
              page open, and a signature they cannot verify is a signature they will skip.
            -->
            <details
                class="rounded-2xl border border-border bg-card p-6"
                data-test="verify-help"
            >
                <summary
                    class="cursor-pointer rounded-sm text-sm font-semibold text-foreground outline-none focus-visible:ring-[3px] focus-visible:ring-ring"
                >
                    {{ t('How to check a signature') }}
                </summary>
                <div class="mt-4 space-y-3 text-sm text-muted-foreground">
                    <p>
                        {{
                            t(
                                'Every request carries a signature header holding a timestamp and a hex digest, separated by a comma:',
                            )
                        }}
                    </p>
                    <!--
                      The header and the formula are shown rather than described. The reader is a
                      developer with this page open, and the Latin tokens live in their own `dir="ltr"`
                      blocks instead of inside an RTL sentence, where the brackets and the dot would
                      reorder.
                    -->
                    <p
                        class="mono-latin overflow-x-auto rounded-xl bg-muted p-3 font-mono text-xs break-all text-foreground"
                        dir="ltr"
                    >
                        X-Alima-Signature: t=1700000000,v1=3b1c…
                    </p>
                    <p>
                        {{
                            t(
                                'Join the timestamp, a period, and the raw request body — exactly the bytes you received, never a re-encoded copy — then HMAC-SHA256 it with your secret and compare the hex digest with v1 in constant time. Reject anything older than five minutes.',
                            )
                        }}
                    </p>
                    <p
                        class="mono-latin overflow-x-auto rounded-xl bg-muted p-3 font-mono text-xs break-all text-foreground"
                        dir="ltr"
                    >
                        hmac_sha256(secret, timestamp + "." + raw_body)
                    </p>
                </div>
            </details>
        </section>

        <!-- Delivery log ------------------------------------------------------>
        <section v-if="canManage && deliveries.length > 0" class="space-y-4">
            <div>
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Delivery log') }}
                </p>
                <h2 class="mt-1 text-lg font-semibold text-foreground">
                    {{ t('The last hundred attempts') }}
                </h2>
            </div>

            <div
                class="overflow-x-auto rounded-2xl border border-border bg-card"
            >
                <table class="w-full text-sm">
                    <thead class="text-start text-xs text-muted-foreground">
                        <tr class="border-b border-border">
                            <th scope="col" class="p-3 text-start font-medium">
                                {{ t('Event') }}
                            </th>
                            <th scope="col" class="p-3 text-start font-medium">
                                {{ t('Result') }}
                            </th>
                            <th scope="col" class="p-3 text-start font-medium">
                                {{ t('When') }}
                            </th>
                            <th scope="col" class="p-3 text-start font-medium">
                                <span class="sr-only">{{ t('Actions') }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="delivery in deliveries"
                            :key="delivery.id"
                            class="border-b border-border last:border-0"
                            :data-test="`delivery-${delivery.id}`"
                        >
                            <td class="p-3">
                                <bdi class="mono-latin font-mono text-xs">{{
                                    delivery.event
                                }}</bdi>
                            </td>
                            <td class="p-3">
                                <span class="flex flex-wrap items-center gap-2">
                                    <Badge
                                        :variant="
                                            deliveryVariant(delivery.state)
                                        "
                                    >
                                        {{ t(delivery.state_label) }}
                                    </Badge>
                                    <span
                                        v-if="delivery.status_code"
                                        class="mono-latin font-mono text-xs text-muted-foreground"
                                        >{{ delivery.status_code }}</span
                                    >
                                </span>
                            </td>
                            <!-- The instant, not the day: retries of one event land hours apart. -->
                            <td class="p-3 text-muted-foreground">
                                {{
                                    delivery.created_at
                                        ? dateTime(delivery.created_at)
                                        : '—'
                                }}
                            </td>
                            <td class="p-3 text-end">
                                <!--
                                  A hundred buttons called "Send again" are a hundred rows a
                                  screen-reader user cannot tell apart, so the accessible name
                                  names the event.
                                -->
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    :aria-label="
                                        t('Send :event again', {
                                            event: delivery.event,
                                        })
                                    "
                                    @click="
                                        router.post(
                                            `/integrations/deliveries/${delivery.id}/redeliver`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    "
                                >
                                    {{ t('Send again') }}
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- CRM --------------------------------------------------------------->
        <section class="space-y-4">
            <div>
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('CRM') }}
                </p>
                <h2 class="mt-1 text-lg font-semibold text-foreground">
                    {{ t('Push companies straight into your pipeline') }}
                </h2>
                <p
                    v-if="crmRemaining !== null"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{
                        t(':count records left to push this month', {
                            count: crmRemaining,
                        })
                    }}
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <article
                    v-for="provider in providers"
                    :key="provider.value"
                    class="space-y-3 rounded-2xl border border-border bg-card p-6"
                    :data-test="`provider-${provider.value}`"
                >
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-semibold text-foreground">
                            {{ provider.label }}
                        </h3>
                        <Badge
                            v-if="integrationFor(provider.value)"
                            :variant="
                                statusVariant(
                                    integrationFor(provider.value)!.status,
                                )
                            "
                        >
                            {{
                                t(integrationFor(provider.value)!.status_label)
                            }}
                        </Badge>
                        <Badge
                            v-else-if="!provider.available"
                            variant="outline"
                        >
                            {{ t('Coming soon') }}
                        </Badge>
                    </div>

                    <p
                        v-if="!provider.available"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            t(
                                'Not available yet. In the meantime, a webhook above will carry the same companies into any tool that accepts one.',
                            )
                        }}
                    </p>

                    <form
                        v-else
                        :action="`/integrations/crm/${provider.value}/connect`"
                        method="post"
                    >
                        <input type="hidden" name="_token" :value="csrfToken" />
                        <Button type="submit" variant="brand" size="sm">
                            <Plug class="size-4" />
                            {{ t('Connect') }}
                        </Button>
                    </form>
                </article>
            </div>
        </section>

        <!--
          One dialog for the two actions that cannot be undone. Not `window.confirm`
          (brand-tokens.md §7h.11), and the destructive confirm is the only `destructive` button on
          the screen.
        -->
        <Dialog
            :open="confirming !== null"
            @update:open="(open) => (open ? null : (confirming = null))"
        >
            <DialogContent class="sm:max-w-md" data-test="confirm-dialog">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            confirming?.action === 'rotate'
                                ? t('Rotate this signing secret?')
                                : t('Remove this endpoint?')
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            confirming?.action === 'rotate'
                                ? t(
                                      'The next event is signed with the new secret, so anything still using the old one will reject it. You will be shown the new secret once.',
                                  )
                                : t(
                                      'Alima stops sending to this address and its delivery log goes with it. Your past events are not resent.',
                                  )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button variant="outline" @click="confirming = null">
                        {{ t('Cancel') }}
                    </Button>
                    <Button
                        :variant="
                            confirming?.action === 'rotate'
                                ? 'brand'
                                : 'destructive'
                        "
                        data-test="confirm-action"
                        @click="confirmed"
                    >
                        {{
                            confirming?.action === 'rotate'
                                ? t('Rotate the secret')
                                : t('Remove')
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
