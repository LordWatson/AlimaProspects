<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import { Button } from '@/components/ui/button';

/*
 * The panel's own two-factor enrolment.
 *
 * The panel has required a second factor since 19a and was right to: one super-admin credential
 * reads every tenant's data, and a password is not the right lock on that. What was wrong was the
 * room. The gate used to send a new operator to the **product's** security settings, and an
 * operator belongs to no team — so the rail around that screen was a column of links that all
 * answered 404, and "set up two-factor authentication" read as "the product is broken".
 *
 * So this is the same `ManageTwoFactor` component, driving the same Fortify endpoints, in the
 * panel's shell. Nothing about enrolment is reimplemented; only where it happens has changed.
 *
 * The copy says **why** rather than only what, because an operator meeting this on their first
 * sign-in has not read the runbook and should not have to in order to understand a locked door.
 */
defineProps<{
    canManageTwoFactor: boolean;
    /** Whether the panel is currently insisting — `ADMIN_REQUIRE_TWO_FACTOR`. */
    required: boolean;
    twoFactorEnabled: boolean;
    requiresConfirmation: boolean;
    /** Where the panel lives, since `ADMIN_PATH` can move it. */
    panelUrl: string;
}>();
</script>

<template>
    <Head title="Two-factor authentication" />

    <div class="mx-auto max-w-2xl space-y-8">
        <AdminPageHeader
            eyebrow="Operations · security"
            :title="
                twoFactorEnabled
                    ? 'Your second|factor.'
                    : 'Set up|two-factor authentication.'
            "
            :meta="null"
        />

        <div class="space-y-3">
            <p
                v-if="required && !twoFactorEnabled"
                class="text-base text-muted-foreground"
            >
                The panel reads every tenant's data, so it asks for more than a
                password before it opens. Scan the code with your authenticator
                and enter the six digits it shows — then the panel is yours.
            </p>
            <p v-else-if="required" class="text-base text-muted-foreground">
                The panel is open. Keep your recovery codes somewhere you can
                reach without this device.
            </p>
            <p v-else class="text-base text-muted-foreground">
                Two-factor authentication is not being enforced on this
                environment (<code class="font-mono text-xs"
                    >ADMIN_REQUIRE_TWO_FACTOR=false</code
                >), which is a local convenience and never a deployment setting.
                Setting it up anyway is a good idea.
            </p>
        </div>

        <ManageTwoFactor
            :can-manage-two-factor="canManageTwoFactor"
            :requires-confirmation="requiresConfirmation"
            :two-factor-enabled="twoFactorEnabled"
        />

        <!-- The way on, once there is one. A page that locks somebody out and offers no door is
             the thing this screen exists to stop being. -->
        <div
            v-if="twoFactorEnabled"
            class="flex items-center gap-3 rounded-2xl border border-border bg-accent/60 p-4"
        >
            <ShieldCheck
                class="size-5 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <p class="text-base text-foreground">
                That is everything the panel needed.
            </p>
            <Button class="ms-auto" as-child data-test="enter-the-panel">
                <Link :href="panelUrl">Open the panel</Link>
            </Button>
        </div>
    </div>
</template>
