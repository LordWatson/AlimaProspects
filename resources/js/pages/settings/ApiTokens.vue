<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Copy, KeyRound } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Token = {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    created_at: string | null;
};

defineProps<{
    tokens: Token[];
    newToken: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API tokens', href: '/settings/api-tokens' }],
    },
});

function revoke(token: Token): void {
    if (!window.confirm(`Revoke the token "${token.name}"?`)) {
        return;
    }
    router.delete(`/settings/api-tokens/${token.id}`, { preserveScroll: true });
}

function copy(value: string): void {
    void navigator.clipboard?.writeText(value);
}
</script>

<template>
    <Head title="API tokens" />

    <h1 class="sr-only">API tokens</h1>

    <div class="space-y-8">
        <Heading
            variant="small"
            title="API tokens"
            description="Personal access tokens let the Alima mobile app and your own integrations act on your behalf."
        />

        <div
            v-if="newToken"
            class="rounded-md border border-ring/40 bg-muted/50 p-4"
            data-test="new-token"
        >
            <p class="mb-2 text-sm font-medium">
                Here's your new token. Copy it now — you won't see it again.
            </p>
            <div class="flex items-center gap-2">
                <code
                    class="min-w-0 flex-1 truncate rounded bg-background px-3 py-2 font-mono text-sm"
                    >{{ newToken }}</code
                >
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Copy token"
                    @click="copy(newToken)"
                >
                    <Copy class="size-4" />
                </Button>
            </div>
        </div>

        <Form
            action="/settings/api-tokens"
            method="post"
            class="flex flex-wrap items-end gap-3"
            reset-on-success
            v-slot="{ errors, processing }"
        >
            <div class="grid flex-1 gap-2" style="min-width: 16rem">
                <Label for="token-name">Token name</Label>
                <Input
                    id="token-name"
                    name="name"
                    placeholder="e.g. My iPhone"
                    required
                />
                <InputError :message="errors.name" />
            </div>
            <Button :disabled="processing" data-test="create-token-button">
                <KeyRound class="size-4" />
                Create token
            </Button>
        </Form>

        <Card v-if="tokens.length > 0">
            <CardHeader>
                <CardTitle class="text-base">Active tokens</CardTitle>
                <CardDescription
                    >Revoke any token you no longer use.</CardDescription
                >
            </CardHeader>
            <CardContent class="divide-y p-0">
                <div
                    v-for="token in tokens"
                    :key="token.id"
                    class="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ token.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            Created {{ token.created_at }}
                            <template v-if="token.last_used_at">
                                · last used {{ token.last_used_at }}</template
                            >
                        </p>
                    </div>
                    <Button variant="ghost" size="sm" @click="revoke(token)"
                        >Revoke</Button
                    >
                </div>
            </CardContent>
        </Card>
    </div>
</template>
