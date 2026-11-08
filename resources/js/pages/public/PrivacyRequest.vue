<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CheckCircle2, MailCheck, ShieldCheck } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { FormField } from '@/components/forms';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import MarketingLayout from '@/layouts/MarketingLayout.vue';

/*
 * The public privacy-request form (PL5, docs/compliance.md §3.15) — `/privacy/request`.
 *
 * **Three pages in one component**, because they are three states of one errand and splitting them
 * would mean three routes a person can land on out of order: the form, "check your inbox", and
 * "confirmed". The server decides which by two booleans.
 *
 * **It never says whether we hold you.** Not on submit, not on confirm. A form anybody can submit
 * that answered "yes, Acme Ltd has your details" would be a lookup tool for anybody who can type an
 * address — which is also why the confirmation page carries no parameters and can be reloaded
 * freely.
 *
 * **The form is deliberately short.** Type, email, and three optional fields. Every required field
 * is one more reason somebody gives up on an opt-out, and the only one we cannot do without is the
 * address we have to write back to.
 *
 * Brand: the marketing layout's cream page, Deep Teal headings, one card, status tokens for the
 * confirmations. Signal Orange is decorative and never a status (brand-tokens.md §2.1).
 *
 * **Every field is a `FormField`**, which is the product's own wiring for the label, the hint, the
 * error id, `aria-describedby` and `aria-invalid` — and it matters more here than on an internal
 * form, because the person filling this in is a stranger exercising a legal right and has no
 * support channel. Hand-rolling the pairing is exactly how the other long forms drifted into never
 * setting `aria-invalid` at all, which is the defect `FormField` was extracted to end.
 */
const props = defineProps<{
    types: { value: string; label: string }[];
    /** Null when no challenge is configured for this deployment; the widget is then absent. */
    turnstileSiteKey: string | null;
    contactEmail: string | null;
    submitted: boolean;
    verified: boolean;
}>();

const { t, locale } = useI18n();

/** Named once, because the duplicate-load guard below has to match it exactly. */
const TURNSTILE_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

const form = useForm({
    type: props.types[0]?.value ?? 'opt_out',
    email: '',
    name: '',
    company: '',
    phone: '',
    details: '',
    turnstile_token: '',
});

/** The container the Turnstile script renders into, when there is a key. */
const challenge = ref<HTMLDivElement | null>(null);

onMounted(() => {
    if (!props.turnstileSiteKey || !challenge.value) {
        return;
    }

    // Loaded here rather than in the shell, so the one deployment-specific third-party script is
    // only ever fetched on the one page that needs it — and the CSP host it needs is configured
    // rather than loosened (ADR 0017). Guarded, because Inertia keeps the document between visits
    // and coming back to this page would otherwise append a second copy.
    if (document.querySelector(`script[src="${TURNSTILE_SRC}"]`)) {
        return;
    }

    const script = document.createElement('script');
    script.src = TURNSTILE_SRC;
    script.async = true;
    script.defer = true;
    document.head.appendChild(script);

    challenge.value.dataset.sitekey = props.turnstileSiteKey;
    // The widget's own copy follows this, not the page: an Arabic form with an English captcha is
    // the one element on the page a reader cannot act on.
    challenge.value.dataset.language = locale.value;
    challenge.value.classList.add('cf-turnstile');
});

function submit(): void {
    // The widget writes its own hidden input; read it at submit time rather than binding it, since
    // the script owns the element.
    const token = document.querySelector<HTMLInputElement>(
        'input[name="cf-turnstile-response"]',
    );

    form.turnstile_token = token?.value ?? '';

    form.post('/privacy/request', { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Privacy request')" />

    <MarketingLayout bare>
        <div class="mx-auto max-w-xl px-5 py-20 sm:py-28">
            <div class="space-y-5 rounded-2xl border border-border bg-card p-8">
                <!-- Confirmed ------------------------------------------------>
                <template v-if="verified">
                    <CheckCircle2
                        class="size-10 text-success"
                        aria-hidden="true"
                    />
                    <h1 class="text-2xl font-semibold text-foreground">
                        {{ t('Your request is confirmed.') }}
                    </h1>
                    <p class="text-muted-foreground">
                        {{
                            t(
                                'We have recorded it and will not contact you while we deal with it. You will hear from us within one month.',
                            )
                        }}
                    </p>
                </template>

                <!-- Submitted ------------------------------------------------>
                <template v-else-if="submitted">
                    <MailCheck class="size-10 text-teal" aria-hidden="true" />
                    <h1 class="text-2xl font-semibold text-foreground">
                        {{ t('Check your inbox.') }}
                    </h1>
                    <p class="text-muted-foreground">
                        {{
                            t(
                                'We have sent you a link to confirm the request came from you. Nothing happens until you click it.',
                            )
                        }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'If it does not arrive within a few minutes, check your spam folder and then try again.',
                            )
                        }}
                    </p>
                </template>

                <!-- The form ------------------------------------------------->
                <template v-else>
                    <ShieldCheck class="size-10 text-teal" aria-hidden="true" />
                    <h1 class="text-2xl font-semibold text-foreground">
                        {{ t('Make a privacy request.') }}
                    </h1>
                    <p class="text-muted-foreground">
                        {{
                            t(
                                'Tell us what you would like us to do and we will confirm it by email first. Your request applies to every business using Alima, not only the one that contacted you.',
                            )
                        }}
                    </p>

                    <form class="space-y-5 pt-2" @submit.prevent="submit">
                        <FormField
                            id="request-type"
                            :label="t('What would you like us to do?')"
                            :error="form.errors.type"
                        >
                            <template #default="{ id, describedBy, invalid }">
                                <NativeSelect
                                    :id="id"
                                    v-model="form.type"
                                    required
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                >
                                    <option
                                        v-for="option in types"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ t(option.label) }}
                                    </option>
                                </NativeSelect>
                            </template>
                        </FormField>

                        <FormField
                            id="request-email"
                            :label="t('Your email address')"
                            required
                            :error="form.errors.email"
                            :hint="
                                t(
                                    'We write to this address to confirm the request, and search for it in what we hold.',
                                )
                            "
                        >
                            <template #default="{ id, describedBy, invalid }">
                                <Input
                                    :id="id"
                                    v-model="form.email"
                                    type="email"
                                    autocomplete="email"
                                    required
                                    dir="ltr"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                />
                            </template>
                        </FormField>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <FormField
                                id="request-name"
                                :label="t('Your name')"
                                optional
                                :error="form.errors.name"
                            >
                                <template
                                    #default="{ id, describedBy, invalid }"
                                >
                                    <Input
                                        :id="id"
                                        v-model="form.name"
                                        autocomplete="name"
                                        dir="auto"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                    />
                                </template>
                            </FormField>

                            <FormField
                                id="request-company"
                                :label="t('Your company')"
                                optional
                                :error="form.errors.company"
                            >
                                <template
                                    #default="{ id, describedBy, invalid }"
                                >
                                    <Input
                                        :id="id"
                                        v-model="form.company"
                                        autocomplete="organization"
                                        dir="auto"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                    />
                                </template>
                            </FormField>
                        </div>

                        <FormField
                            id="request-phone"
                            :label="t('Your phone number')"
                            optional
                            :error="form.errors.phone"
                            :hint="
                                t(
                                    'Add it if you would also like us to stop calling you. We confirm a number with you before acting on it.',
                                )
                            "
                        >
                            <template #default="{ id, describedBy, invalid }">
                                <Input
                                    :id="id"
                                    v-model="form.phone"
                                    type="tel"
                                    autocomplete="tel"
                                    dir="ltr"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                />
                            </template>
                        </FormField>

                        <FormField
                            id="request-details"
                            :label="t('Anything else we should know?')"
                            optional
                            :error="form.errors.details"
                        >
                            <template #default="{ id, describedBy, invalid }">
                                <Textarea
                                    :id="id"
                                    v-model="form.details"
                                    rows="3"
                                    dir="auto"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                />
                            </template>
                        </FormField>

                        <!-- Rendered only where a challenge is configured. -->
                        <div v-if="turnstileSiteKey" ref="challenge" />

                        <Button
                            type="submit"
                            :disabled="form.processing"
                            data-test="privacy-request-submit"
                        >
                            {{ t('Send my request') }}
                        </Button>
                    </form>
                </template>

                <!-- Two sentences on two lines, not one assembled from fragments: Arabic cannot
                     be reordered around an interpolated link, and a literal full stop after an
                     `ltr` email address lands on the wrong side of it in RTL. -->
                <p class="pt-2 text-sm text-muted-foreground">
                    {{ t('Want to know what we hold and why?') }}
                    <Link
                        href="/privacy"
                        class="font-medium text-brand underline underline-offset-2"
                    >
                        {{ t('Read the privacy notice.') }}
                    </Link>
                </p>
                <p v-if="contactEmail" class="text-sm text-muted-foreground">
                    {{ t('You can also write to us.') }}
                    <a
                        :href="`mailto:${contactEmail}`"
                        class="font-medium text-brand underline underline-offset-2"
                        dir="ltr"
                        >{{ contactEmail }}</a
                    >
                </p>
            </div>
        </div>
    </MarketingLayout>
</template>
