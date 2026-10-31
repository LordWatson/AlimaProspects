<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Copy, KeyRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Token = {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    created_at: string | null;
};

/** One scopable ability, as `App\Modules\Auth\Enums\TokenAbility` describes it. */
type Ability = {
    value: string;
    label: string;
};

type AbilityGroup = {
    group: string;
    title: string;
    abilities: Ability[];
};

const props = defineProps<{
    tokens: Token[];
    newToken: string | null;
    abilityGroups: AbilityGroup[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API tokens', href: '/settings/api-tokens' }],
    },
});

/**
 * Full access unless the person narrows it. A token that silently started out able to do nothing
 * would be a token that fails on first use, so the default is the permissive one and limiting is
 * the deliberate act.
 */
const limited = ref(false);
const selected = ref<string[]>([]);

const abilities = computed<string[]>(() =>
    limited.value ? selected.value : ['*'],
);

/**
 * The limit is on and nothing is ticked. Worth its own name because it is the one state the form
 * must not submit: with no `abilities[]` inputs the server reads "no scope asked for" and issues a
 * full-access token — the opposite of what was being asked for.
 */
const nothingChosen = computed<boolean>(
    () => limited.value && selected.value.length === 0,
);

function toggle(ability: string, checked: boolean): void {
    selected.value = checked
        ? [...selected.value, ability]
        : selected.value.filter((value) => value !== ability);
}

/**
 * What a stored ability says on its badge. The raw value, deliberately: it is what the API
 * answers with in `error.details.required_abilities`, so the thing on the screen and the thing in
 * the error are the same string.
 */
function abilityLabel(ability: string): string {
    return ability === '*' ? 'Full access' : ability;
}

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
            class="space-y-6"
            reset-on-success
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2" style="max-width: 24rem">
                <Label for="token-name">Token name</Label>
                <Input
                    id="token-name"
                    name="name"
                    placeholder="e.g. My iPhone"
                    required
                />
                <InputError :message="errors.name" />
            </div>

            <!--
                The scope. Posted as hidden inputs rather than wired straight into the checkboxes,
                so what the form sends is exactly what the summary above it says — one source of
                truth for "what this token will be able to do".
            -->
            <fieldset class="space-y-4">
                <legend class="text-sm font-medium">
                    What this token can do
                </legend>

                <div class="flex items-start gap-3">
                    <Checkbox
                        id="limit-scope"
                        v-model="limited"
                        aria-describedby="limit-scope-hint"
                        data-test="limit-scope"
                    />
                    <span class="text-sm">
                        <Label for="limit-scope" class="font-normal"
                            >Limit this token to certain things</Label
                        >
                        <span
                            id="limit-scope-hint"
                            class="mt-1 block text-muted-foreground"
                        >
                            Leave this off and the token can do anything you
                            can. Turn it on for a script or an integration that
                            only needs part of Alima.
                        </span>
                    </span>
                </div>

                <div
                    v-if="limited"
                    class="grid gap-6 rounded-md border border-border bg-muted/30 p-4 sm:grid-cols-2"
                    data-test="ability-groups"
                >
                    <div
                        v-for="group in abilityGroups"
                        :key="group.group"
                        class="space-y-2"
                    >
                        <p
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                        >
                            {{ group.title }}
                        </p>
                        <div
                            v-for="ability in group.abilities"
                            :key="ability.value"
                            class="flex items-start gap-3"
                        >
                            <Checkbox
                                :id="`ability-${ability.value}`"
                                :model-value="selected.includes(ability.value)"
                                @update:model-value="
                                    (checked) =>
                                        toggle(ability.value, checked === true)
                                "
                            />
                            <!--
                                `flex-col`: Label is a row by default, which would set the scope
                                string beside its own label instead of under it. And `dir="ltr"` on
                                the string, because `leads:read` is an API identifier and must not
                                reorder under Arabic.
                            -->
                            <Label
                                :for="`ability-${ability.value}`"
                                class="flex-col items-start gap-0.5 text-sm leading-snug font-normal"
                            >
                                {{ ability.label }}
                                <span
                                    dir="ltr"
                                    class="block font-mono text-xs text-muted-foreground"
                                    >{{ ability.value }}</span
                                >
                            </Label>
                        </div>
                    </div>
                </div>

                <p
                    v-if="nothingChosen"
                    role="status"
                    class="text-sm text-muted-foreground"
                    data-test="no-abilities"
                >
                    Nothing selected yet — pick at least one thing, or turn the
                    limit off for full access.
                </p>

                <input
                    v-for="ability in abilities"
                    :key="ability"
                    type="hidden"
                    name="abilities[]"
                    :value="ability"
                />
                <InputError
                    :message="errors.abilities ?? errors['abilities.0']"
                />
            </fieldset>

            <!--
                Last, and after the scope: a keyboard user who met the button first would submit
                before seeing the choice at all. Disabled while the limit is on with nothing
                chosen, which is not a nicety — that form sends no `abilities[]`, an absent scope
                means *full access*, and full access is the one thing the person was avoiding.
            -->
            <Button
                :disabled="processing || nothingChosen"
                data-test="create-token-button"
            >
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
                    <div class="min-w-0 space-y-1">
                        <p class="truncate font-medium">{{ token.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            Created {{ token.created_at }}
                            <template v-if="token.last_used_at">
                                · last used {{ token.last_used_at }}</template
                            >
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <Badge
                                v-for="ability in token.abilities"
                                :key="ability"
                                :variant="
                                    ability === '*' ? 'secondary' : 'outline'
                                "
                                :dir="ability === '*' ? undefined : 'ltr'"
                                class="font-mono"
                            >
                                {{ abilityLabel(ability) }}
                            </Badge>
                        </div>
                    </div>
                    <Button variant="ghost" size="sm" @click="revoke(token)"
                        >Revoke</Button
                    >
                </div>
            </CardContent>
        </Card>
    </div>
</template>
