<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Spinner } from '@/components/ui/spinner';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    token: string;
    team: { name: string };
    role: string;
    email: string;
    emailMatches: boolean;
    isExpired: boolean;
    isAccepted: boolean;
}>();

defineOptions({
    layout: {
        title: "You're invited",
        description: 'Join your team on Alima.',
    },
});

const canAccept = !props.isExpired && !props.isAccepted && props.emailMatches;
</script>

<template>
    <Head title="Accept invitation" />

    <div class="space-y-6 text-center">
        <p class="text-sm text-muted-foreground">
            You've been invited to join
            <span class="font-semibold text-foreground">{{ team.name }}</span>
            as <span class="font-semibold text-foreground">{{ role }}</span
            >.
        </p>

        <div v-if="isAccepted" class="rounded-md bg-muted/50 p-4 text-sm">
            This invitation has already been accepted.
        </div>

        <div v-else-if="isExpired" class="rounded-md bg-muted/50 p-4 text-sm">
            This invitation has expired. Ask the team to send a fresh one.
        </div>

        <div
            v-else-if="!emailMatches"
            class="rounded-md bg-muted/50 p-4 text-sm"
        >
            This invitation was sent to <strong>{{ email }}</strong
            >. Sign in with that email address to accept it.
        </div>

        <Form
            v-else
            :action="`/invitations/${token}`"
            method="post"
            v-slot="{ processing }"
        >
            <Button
                :disabled="processing || !canAccept"
                class="w-full"
                data-test="accept-invitation-button"
            >
                <Spinner v-if="processing" />
                Accept invitation
            </Button>
        </Form>
    </div>
</template>
