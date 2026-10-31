<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    IonButton,
    IonContent,
    IonInput,
    IonNote,
    IonPage,
    IonSpinner,
} from '@ionic/vue';
import { Capacitor } from '@capacitor/core';
import { Device } from '@/lib/device';
import { ApiError, api } from '@/lib/api';
import { apiUrlRef, isDev, setApiUrl } from '@/lib/config';
import { t } from '@/lib/i18n';
import { notifyFailure } from '@/lib/feedback';
import { requestedAbilities, startSession } from '@/lib/session';
import type { User } from '@shared/types';
import BrandMark from '@/components/BrandMark.vue';
import { router } from '@/router';

/*
 * Sign-in: credentials in, a scoped token out (`POST /auth/token`).
 *
 * Two screens in one, because they are two steps of one exchange. The form posts the
 * credentials; if the account has two-factor on, the server answers `423 auth.two_factor_required`
 * and the same screen swaps to the challenge, keeping the credentials in memory so the second
 * post carries all of it. A client cannot know in advance whether an account needs a second
 * factor — asking is how it finds out — so the 423 is a step in the flow and not an error, and is
 * never shown as one.
 *
 * The token asks for the eight scopes in `requestedAbilities` and nothing more, so what this app
 * can do with the member's account is visible to them on the web app's token screen.
 */
type Step = 'credentials' | 'two-factor';

const step = ref<Step>('credentials');
const busy = ref(false);

const email = ref('');
const password = ref('');
const code = ref('');
const recoveryCode = ref('');
const useRecoveryCode = ref(false);

/** Field errors from a `validation_failed`, keyed by input name. */
const errors = ref<Record<string, string[]>>({});

const serverUrl = ref(apiUrlRef.value);
const serverError = ref(false);

const canSubmitCredentials = computed(
    () => email.value.trim() !== '' && password.value !== '' && !busy.value,
);

const canSubmitChallenge = computed(() =>
    busy.value
        ? false
        : useRecoveryCode.value
          ? recoveryCode.value.trim() !== ''
          : code.value.trim() !== '',
);

function fieldError(field: string): string | null {
    return errors.value[field]?.[0] ?? null;
}

async function submit(): Promise<void> {
    if (busy.value) {
        return;
    }

    busy.value = true;
    errors.value = {};

    try {
        const response = await api.post<{ token: string; user: User }>(
            'auth/token',
            {
                email: email.value.trim(),
                password: password.value,
                device_name: Device.name(),
                abilities: requestedAbilities,
                // Sent only on the second post. The server treats an empty string as "not sent"
                // and answers with the challenge again, but there is no reason to make it.
                ...(step.value === 'two-factor'
                    ? useRecoveryCode.value
                        ? { recovery_code: recoveryCode.value.trim() }
                        : { code: code.value.trim() }
                    : {}),
            },
        );

        await startSession(response.token, response.user);

        // `replace`, so the back gesture cannot return to the sign-in form from inside the app.
        await router.replace({ name: 'dashboard' });
    } catch (error) {
        await handleFailure(error);
    } finally {
        busy.value = false;
    }
}

async function handleFailure(error: unknown): Promise<void> {
    if (!(error instanceof ApiError)) {
        await notifyFailure(error, t('We could not sign you in.'));

        return;
    }

    // The account has two-factor on. Not a failure — the next step. The credentials stay in
    // memory because the second post carries them again: the exchange is settled in one request,
    // so there is no half-finished challenge for anyone to steal.
    if (error.code === 'auth.two_factor_required') {
        step.value = 'two-factor';

        return;
    }

    if (error.code === 'validation_failed') {
        errors.value = error.validationErrors;

        // A wrong code belongs under the input it was typed into, not in a toast that covers it.
        if (
            errors.value.code === undefined &&
            errors.value.recovery_code === undefined &&
            errors.value.email === undefined &&
            errors.value.password === undefined
        ) {
            await notifyFailure(error, t('We could not sign you in.'));
        }

        return;
    }

    await notifyFailure(error, t('We could not sign you in.'));
}

function toggleRecoveryCode(): void {
    useRecoveryCode.value = !useRecoveryCode.value;
    errors.value = {};
}

function restart(): void {
    step.value = 'credentials';
    code.value = '';
    recoveryCode.value = '';
    useRecoveryCode.value = false;
    errors.value = {};
}

async function saveServerUrl(): Promise<void> {
    serverError.value = !(await setApiUrl(serverUrl.value));
}
</script>

<template>
    <IonPage>
        <IonContent :fullscreen="true">
            <div class="signin">
                <header class="signin__head">
                    <BrandMark
                        variant="signature"
                        :title="t('Alima')"
                        class="signin__logo"
                    />
                    <p class="signin__tagline">
                        {{ t('Marketing brighter together.') }}
                    </p>
                </header>

                <!-- Step 1: credentials. -->
                <form
                    v-if="step === 'credentials'"
                    class="signin__form"
                    novalidate
                    @submit.prevent="submit"
                >
                    <h1 class="alima-h1">{{ t('Welcome back') }}</h1>

                    <IonInput
                        v-model="email"
                        type="email"
                        inputmode="email"
                        autocomplete="username"
                        enterkeyhint="next"
                        fill="outline"
                        label-placement="stacked"
                        :label="t('Email address')"
                        :class="{
                            'ion-invalid ion-touched': fieldError('email'),
                        }"
                        :error-text="fieldError('email') ?? undefined"
                        :disabled="busy"
                        dir="ltr"
                    />

                    <IonInput
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                        enterkeyhint="go"
                        fill="outline"
                        label-placement="stacked"
                        :label="t('Password')"
                        :class="{
                            'ion-invalid ion-touched': fieldError('password'),
                        }"
                        :error-text="fieldError('password') ?? undefined"
                        :disabled="busy"
                    />

                    <IonButton
                        type="submit"
                        expand="block"
                        color="primary"
                        :disabled="!canSubmitCredentials"
                    >
                        <IonSpinner v-if="busy" name="crescent" />
                        <span v-else>{{ t('Sign in') }}</span>
                    </IonButton>

                    <IonNote class="signin__note">
                        {{
                            t(
                                'New here? Create your account on the Alima website first — the app signs you in to the team you already have.',
                            )
                        }}
                    </IonNote>
                </form>

                <!-- Step 2: the second factor. -->
                <form
                    v-else
                    class="signin__form"
                    novalidate
                    @submit.prevent="submit"
                >
                    <h1 class="alima-h1">{{ t('One more step') }}</h1>
                    <p class="alima-muted">
                        {{
                            useRecoveryCode
                                ? t(
                                      'Enter one of the recovery codes you saved when you set up two-factor authentication.',
                                  )
                                : t(
                                      'Enter the code from your authenticator app to finish signing in.',
                                  )
                        }}
                    </p>

                    <IonInput
                        v-if="!useRecoveryCode"
                        v-model="code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        enterkeyhint="go"
                        :maxlength="6"
                        fill="outline"
                        label-placement="stacked"
                        :label="t('Authentication code')"
                        :class="{
                            'ion-invalid ion-touched': fieldError('code'),
                        }"
                        :error-text="fieldError('code') ?? undefined"
                        :disabled="busy"
                        class="signin__code alima-ltr"
                    />

                    <IonInput
                        v-else
                        v-model="recoveryCode"
                        type="text"
                        autocomplete="one-time-code"
                        enterkeyhint="go"
                        fill="outline"
                        label-placement="stacked"
                        :label="t('Recovery code')"
                        :class="{
                            'ion-invalid ion-touched':
                                fieldError('recovery_code'),
                        }"
                        :error-text="fieldError('recovery_code') ?? undefined"
                        :disabled="busy"
                        class="alima-ltr"
                    />

                    <IonButton
                        type="submit"
                        expand="block"
                        color="primary"
                        :disabled="!canSubmitChallenge"
                    >
                        <IonSpinner v-if="busy" name="crescent" />
                        <span v-else>{{ t('Sign in') }}</span>
                    </IonButton>

                    <IonButton
                        fill="clear"
                        expand="block"
                        size="small"
                        color="secondary"
                        :disabled="busy"
                        @click="toggleRecoveryCode"
                    >
                        {{
                            useRecoveryCode
                                ? t('Use my authenticator app instead')
                                : t('Use a recovery code instead')
                        }}
                    </IonButton>

                    <IonButton
                        fill="clear"
                        expand="block"
                        size="small"
                        color="medium"
                        :disabled="busy"
                        @click="restart"
                    >
                        {{ t('Back') }}
                    </IonButton>
                </form>

                <!--
                    Development only: this one bundle has to reach the API by four different names
                    during the mobile phase (the Herd domain from a desktop browser, 10.0.2.2 from
                    the Android emulator, a LAN address from a handset, a tunnel for a demo).
                    `isDev` is a build-time constant, so none of this is in a release bundle.
                -->
                <details v-if="isDev" class="signin__dev">
                    <summary>{{ t('Server') }}</summary>
                    <IonInput
                        v-model="serverUrl"
                        type="url"
                        inputmode="url"
                        fill="outline"
                        label-placement="stacked"
                        label="API origin"
                        dir="ltr"
                        class="alima-ltr"
                        @ion-blur="saveServerUrl"
                    />
                    <p v-if="serverError" class="signin__dev-error">
                        Needs an absolute http(s) URL.
                    </p>
                    <p class="signin__dev-hint">
                        {{ Capacitor.getPlatform() }} · {{ apiUrlRef }}/api/v1
                    </p>
                </details>
            </div>
        </IonContent>
    </IonPage>
</template>

<style scoped>
/* Cream canvas, the signature given room, one column. Leading with cream and a bigger logo is
   the brand's own instruction for a screen like this (CLAUDE.md §2a). */
.signin {
    min-height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 32px;
    padding: 32px calc(24px + var(--ion-safe-area-left, 0px)) 32px
        calc(24px + var(--ion-safe-area-right, 0px));
    max-width: 26rem;
    margin-inline: auto;
}

.signin__head {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}

/* Comfortably above the signature's 120px minimum (brand-tokens.md §4).

   Two colourways, not one tint: Teal-on-Cream in light, Cream-on-Ink in dark
   (colourway 08). Leaving it Teal on the dark canvas would be Teal on Ink at
   1.9:1, which §4 lists under "Never". */
.signin__logo {
    width: 180px;
    color: var(--alima-teal);
}

.alima-dark .signin__logo {
    color: var(--alima-cream);
}

.signin__tagline {
    margin: 0;
    font-size: 0.875rem;
    color: var(--alima-text-muted);
    text-align: center;
}

.signin__form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* A six-digit code is an identifier: always LTR (`.alima-ltr` on the input), in mono, and
   spaced for reading back off a screen.

   The tracking survives RTL on purpose and is the one place it does — but only because the
   content is digits inside an isolated LTR run, never Arabic text. `app.css` neutralises the
   eyebrow token under RTL precisely so that tracking cannot reach joined letterforms; this is
   a numeric field, not a label. */
.signin__code {
    letter-spacing: 0.3em;
    font-family: var(--alima-font-mono);
}

.signin__note {
    font-size: 0.8125rem;
    line-height: 1.5;
    text-align: center;
}

.signin__dev {
    border-top: 1px solid var(--alima-border);
    padding-top: 16px;
    font-size: 0.8125rem;
    color: var(--alima-text-muted);
}

.signin__dev summary {
    cursor: pointer;
    font-family: var(--alima-font-mono);
    text-transform: uppercase;
    letter-spacing: var(--alima-tracking-eyebrow);
    font-size: 0.6875rem;
}

.signin__dev-error {
    color: var(--alima-status-error);
    margin: 8px 0 0;
}

.signin__dev-hint {
    margin: 8px 0 0;
    font-family: var(--alima-font-mono);
    direction: ltr;
}
</style>
