<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { PrivacyNotice } from '@/types';

/*
 * The hosted privacy notice for prospects (Phase 15, docs/compliance.md §3.7) — the page every
 * outreach footer links to.
 *
 * **Written for the person who received the message, not for a lawyer.** Somebody who has just been
 * cold-emailed wants to know three things: who has my details, where did they get them, and how do
 * I make it stop. Those three are the first three sections, in that order, and the opt-out is
 * repeated at the end.
 *
 * **It names Alima, not the tenant.** The business that made contact is the controller for that
 * prospect and names itself in the message footer; Alima is the processor. A per-tenant notice would
 * mean a URL that discloses which team is looking at whom to anybody who follows it.
 *
 * Bilingual, like everything else (ADR 0007) — and this is the page most likely to be read in
 * Arabic, since UAE prospects are the ones the prior-consent rules protect.
 *
 * Brand: the marketing layout's cream page, Deep Teal headings, a readable measure. No hero accent:
 * this is a document, and decoration on a privacy notice reads as spin.
 */
const props = defineProps<{ notice: PrivacyNotice }>();

const { t } = useI18n();
const { date } = useFormat();

const months = (days: number): number => Math.round(days / 30);
</script>

<template>
    <Head :title="t('Privacy notice')" />

    <MarketingLayout bare>
        <article class="mx-auto max-w-2xl px-5 py-16 sm:py-24">
            <p
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                {{ t('Privacy notice') }}
            </p>
            <h1 class="mt-2 text-3xl font-semibold text-foreground sm:text-4xl">
                {{ t('How Alima handles your details.') }}
            </h1>
            <p class="mt-4 text-sm text-muted-foreground">
                {{
                    t('Version :version · Last updated :date', {
                        version: props.notice.version,
                        date: props.notice.updated_on
                            ? date(props.notice.updated_on)
                            : '—',
                    })
                }}
            </p>

            <div class="mt-10 space-y-10 text-base leading-relaxed">
                <section class="space-y-3">
                    <h2 class="text-xl font-semibold text-teal">
                        {{ t('Who has your details') }}
                    </h2>
                    <p>
                        {{
                            t(
                                'If you received a message that linked you here, it came from a business using Alima. That business decided to contact you and is responsible for your details — it names itself at the bottom of the message it sent you.',
                            )
                        }}
                    </p>
                    <p>
                        {{
                            t(
                                'Alima is the software they used. We hold the details on their behalf and do not use them for anything of our own: we do not sell data, and we do not market to the people our customers find.',
                            )
                        }}
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-xl font-semibold text-teal">
                        {{ t('Where it came from') }}
                    </h2>
                    <p>
                        {{
                            t(
                                'From public sources: company registers, business listings, published news, and the business’s own website. We prefer a general business address — the kind printed on a contact page — over anyone’s personal one, and we record where every detail came from so it can be traced.',
                            )
                        }}
                    </p>
                    <p>
                        {{
                            t(
                                'Nothing is scraped from a site whose terms forbid it, and we do not buy personal data from data brokers.',
                            )
                        }}
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-xl font-semibold text-teal">
                        {{ t('How to make it stop') }}
                    </h2>
                    <p>
                        {{
                            t(
                                'Use the unsubscribe link in the message, or reply saying so. Either one is honoured straight away and across every business using Alima — not just the one that wrote to you.',
                            )
                        }}
                    </p>
                    <p>
                        {{
                            t(
                                'To keep that promise we have to remember one thing about you: a one-way scramble of your address, which we can check a future message against but cannot read or use to contact you.',
                            )
                        }}
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-xl font-semibold text-teal">
                        {{ t('Why we are allowed to hold it') }}
                    </h2>
                    <p>
                        {{
                            t(
                                'In the UK, businesses using Alima rely on legitimate interests for business-to-business contact, and must write down an assessment of that before they can search for anyone. In the United Arab Emirates the rules expect your prior consent for direct marketing, so Alima blocks cold email and texts to prospects found there outright.',
                            )
                        }}
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-xl font-semibold text-teal">
                        {{ t('How long it is kept') }}
                    </h2>
                    <p>
                        {{
                            t(
                                'If nobody ever contacts you, the record is deleted after about :uncontacted months. If somebody did, the personal details are removed after about :contacted months of nothing happening — what is left is the company record and the fact that an approach was made.',
                                {
                                    uncontacted: months(
                                        props.notice.uncontacted_days,
                                    ),
                                    contacted: months(
                                        props.notice.contacted_days,
                                    ),
                                },
                            )
                        }}
                    </p>
                    <p>
                        {{
                            t(
                                'A business can set shorter periods than these. It cannot set longer ones without that being recorded.',
                            )
                        }}
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-xl font-semibold text-teal">
                        {{ t('Your rights') }}
                    </h2>
                    <p>
                        {{
                            t(
                                'You can ask what is held about you, ask for it to be corrected or erased, and object to it being used at all. Write to us at the address below and we will pass the request to the business responsible and act on it ourselves.',
                            )
                        }}
                    </p>
                    <p>
                        {{
                            t(
                                'We may ask you for enough information to be sure the request is yours — we will not hand somebody else your details on request either.',
                            )
                        }}
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-xl font-semibold text-teal">
                        {{ t('Where the data lives') }}
                    </h2>
                    <!-- `bdi` so a Latin place name keeps its own direction in an Arabic
                         sentence. -->
                    <p>
                        <bdi>{{
                            t('Our servers are in :location.', {
                                location: props.notice.hosting_location,
                            })
                        }}</bdi>
                    </p>
                </section>

                <section
                    class="space-y-3 rounded-2xl border border-border bg-card p-6"
                >
                    <h2 class="text-xl font-semibold text-teal">
                        {{ t('Contact us') }}
                    </h2>
                    <p v-if="props.notice.contact_email">
                        <a
                            :href="`mailto:${props.notice.contact_email}`"
                            class="font-medium text-teal underline underline-offset-2"
                            dir="ltr"
                        >
                            {{ props.notice.contact_email }}
                        </a>
                    </p>
                    <p v-else class="text-muted-foreground">
                        {{
                            t(
                                'Reply to the message you received and it will reach us.',
                            )
                        }}
                    </p>
                </section>
            </div>
        </article>
    </MarketingLayout>
</template>
