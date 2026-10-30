<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, MailX } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import { useI18n } from '@/composables/useI18n';

/*
 * The hosted unsubscribe page (Phase 15, docs/compliance.md §3.3) — RFC 8058's HTTPS target.
 *
 * **It is a button, not an automatic action.** Mail clients, link checkers and corporate security
 * gateways fetch every URL in an email, so a page that unsubscribed on load would remove people who
 * never clicked. The form posts back to the same signed URL; a mail client's own one-click button
 * posts to it directly and never sees this page.
 *
 * **It says nothing about who was writing to you.** No address, no company, no team. A forwarded or
 * logged link would otherwise disclose which business is looking at whom — and this page is reached
 * by whoever holds the link, which is the one thing that makes that a disclosure rather than a
 * confirmation.
 *
 * Brand: the marketing layout's cream page, Deep Teal heading, one card. Status tokens for the
 * confirmation; Signal Orange is decorative and never a status (brand-tokens.md §2.1).
 */
defineProps<{
    done: boolean;
    expired: boolean;
    /** The signed URL to post back to; null on the confirmation and expired states. */
    action: string | null;
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="t('Unsubscribe')" />

    <MarketingLayout bare>
        <div class="mx-auto max-w-xl px-5 py-20 sm:py-28">
            <div class="space-y-5 rounded-2xl border border-border bg-card p-8">
                <!-- Done ----------------------------------------------------->
                <template v-if="done">
                    <CheckCircle2
                        class="size-10 text-success"
                        aria-hidden="true"
                    />
                    <h1 class="text-2xl font-semibold text-foreground">
                        {{ t('You will not hear from us again.') }}
                    </h1>
                    <p class="text-muted-foreground">
                        {{
                            t(
                                'Your request has been recorded. It applies to every business using Alima, not just the one that wrote to you, and it takes effect immediately.',
                            )
                        }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Nothing further is needed from you. If a message still reaches you, reply to it and we will look into it.',
                            )
                        }}
                    </p>
                </template>

                <!-- Expired or deleted ---------------------------------------->
                <template v-else-if="expired">
                    <MailX
                        class="size-10 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <h1 class="text-2xl font-semibold text-foreground">
                        {{ t('This link is no longer active.') }}
                    </h1>
                    <p class="text-muted-foreground">
                        {{
                            t(
                                'Reply to the message you received with the word “unsubscribe” and we will stop contacting you. That route always works.',
                            )
                        }}
                    </p>
                </template>

                <!-- The ask -------------------------------------------------->
                <template v-else>
                    <MailX class="size-10 text-teal" aria-hidden="true" />
                    <h1 class="text-2xl font-semibold text-foreground">
                        {{ t('Stop hearing from us?') }}
                    </h1>
                    <p class="text-muted-foreground">
                        {{
                            t(
                                'Confirm below and we will not contact you again — by email or by phone, and across every business using Alima rather than only the one that wrote to you.',
                            )
                        }}
                    </p>

                    <!-- A plain form POST back to this same signed URL: there is no session here,
                         so the signature is the authorisation and Inertia's token is irrelevant. -->
                    <form
                        v-if="action"
                        method="post"
                        :action="action"
                        class="pt-2"
                    >
                        <Button type="submit" data-test="unsubscribe-confirm">
                            {{ t('Yes, unsubscribe me') }}
                        </Button>
                    </form>

                    <p class="pt-2 text-sm text-muted-foreground">
                        {{ t('Want to know what we hold about you?') }}
                        <Link
                            href="/privacy"
                            class="font-medium text-teal underline underline-offset-2"
                        >
                            {{ t('Read the privacy notice.') }}
                        </Link>
                    </p>
                </template>
            </div>
        </div>
    </MarketingLayout>
</template>
