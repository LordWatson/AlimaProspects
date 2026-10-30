<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { FileText, Lock, Plus, ShieldOff, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    OutreachSuppression,
    SuppressIdentityForm,
    SuppressionTypeOption,
} from '@/types';

/*
 * The do-not-contact list (docs/compliance.md §3.2).
 *
 * Two things about this screen are deliberate and easy to get wrong:
 *
 *  - **Entries show a masked preview, never the address.** The table holds a hash and a mask and
 *    nothing else: a register of people who want nothing to do with us is not somewhere to keep
 *    their contact details in the clear. So the screen cannot show them, and shouldn't want to.
 *  - **Opt-outs are listed but cannot be lifted**, and the lock says so. A team seeing why a send
 *    was refused is the whole reason global entries appear here at all; giving them a button to
 *    remove one would let a tenant overrule a person's stated wish.
 *
 * Brand: cream page, one table, the lock as a muted glyph. Nothing here is Signal Orange — every
 * state on this screen is a status (brand-tokens.md §2.1).
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Do not contact', href: '/outreach/do-not-contact' },
        ],
    },
});

defineProps<{
    entries: OutreachSuppression[];
    options: { types: SuppressionTypeOption[] };
    can: { manage: boolean };
}>();

const { t } = useI18n();
const { date } = useFormat();

const form = useForm<SuppressIdentityForm>({
    type: 'email',
    value: '',
    note: '',
});

function submit(): void {
    form.post('/outreach/do-not-contact', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function lift(entry: OutreachSuppression): void {
    router.delete(`/outreach/do-not-contact/${entry.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="t('Do not contact')" />

    <div class="flex flex-col gap-6 py-2">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Outreach') }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold text-foreground">
                    {{ t('Do not contact') }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        t(
                            'Nothing is ever sent to anyone on this list. Alima checks it when you draft and again the moment before it sends.',
                        )
                    }}
                </p>
            </div>

            <Button variant="outline" as-child>
                <Link href="/outreach/templates">
                    <FileText class="size-4" />
                    {{ t('Briefs') }}
                </Link>
            </Button>
        </header>

        <!-- Add an entry -->
        <section
            v-if="can.manage"
            class="space-y-4 rounded-2xl border border-border bg-card p-6"
        >
            <h2
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                {{ t('Add to your list') }}
            </h2>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="space-y-2">
                        <Label for="dnc-type">{{ t('What to block') }}</Label>
                        <NativeSelect id="dnc-type" v-model="form.type">
                            <option
                                v-for="type in options.types"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ t(type.label) }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.type" />
                    </div>

                    <div class="space-y-2 lg:col-span-2">
                        <Label for="dnc-value">{{
                            t('Address, domain or number')
                        }}</Label>
                        <Input
                            id="dnc-value"
                            v-model="form.value"
                            required
                            dir="ltr"
                            :placeholder="
                                form.type === 'domain'
                                    ? t('acme.com')
                                    : form.type === 'phone'
                                      ? t('+44 20 7946 0000')
                                      : t('name@acme.com')
                            "
                            data-test="dnc-value"
                        />
                        <p class="text-xs text-muted-foreground">
                            <!-- A domain entry is the one teams reach for most: "we already work
                                 with them, stop approaching anyone there". -->
                            {{
                                t(
                                    'Block a domain to stop contacting anyone at that company.',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.value" />
                    </div>

                    <div class="space-y-2">
                        <Label for="dnc-note">
                            {{ t('Why') }}
                            <span
                                class="text-xs font-normal text-muted-foreground"
                            >
                                {{ t('(optional)') }}
                            </span>
                        </Label>
                        <Input
                            id="dnc-note"
                            v-model="form.note"
                            dir="auto"
                            :placeholder="t('e.g. existing client')"
                        />
                        <InputError :message="form.errors.note" />
                    </div>
                </div>

                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="dnc-add"
                >
                    <Plus class="size-4" />
                    {{ t('Add') }}
                </Button>
            </form>
        </section>

        <!-- The list -->
        <section
            v-if="entries.length"
            class="overflow-hidden rounded-2xl border border-border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-start">
                    <tr
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        <th scope="col" class="p-3 text-start font-medium">
                            {{ t('Entry') }}
                        </th>
                        <th scope="col" class="p-3 text-start font-medium">
                            {{ t('Reason') }}
                        </th>
                        <th scope="col" class="p-3 text-start font-medium">
                            {{ t('Applies to') }}
                        </th>
                        <th scope="col" class="p-3 text-start font-medium">
                            {{ t('Added') }}
                        </th>
                        <th scope="col" class="p-3 text-end font-medium">
                            <span class="sr-only">{{ t('Actions') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody data-test="dnc-entries">
                    <tr
                        v-for="entry in entries"
                        :key="entry.id"
                        class="border-t border-border"
                    >
                        <td class="p-3">
                            <!-- The mask is all there is: no column holds the value in the clear. -->
                            <span class="font-mono" dir="ltr">{{
                                entry.value_preview
                            }}</span>
                            <span class="ms-2 text-xs text-muted-foreground">
                                {{ t(entry.type_label) }}
                            </span>
                        </td>
                        <td class="p-3">
                            {{ t(entry.reason_label) }}
                            <p
                                v-if="entry.note"
                                class="text-xs text-muted-foreground"
                                dir="auto"
                            >
                                {{ entry.note }}
                            </p>
                        </td>
                        <td class="p-3">
                            <Badge
                                :variant="
                                    entry.scope === 'global'
                                        ? 'secondary'
                                        : 'outline'
                                "
                            >
                                {{ t(entry.scope_label) }}
                            </Badge>
                        </td>
                        <td class="p-3 font-mono text-xs text-muted-foreground">
                            {{ date(entry.suppressed_at) }}
                        </td>
                        <td class="p-3 text-end">
                            <Button
                                v-if="entry.can_delete"
                                size="sm"
                                variant="ghost"
                                :data-test="`lift-${entry.id}`"
                                @click="lift(entry)"
                            >
                                <Trash2 class="size-4" />
                                {{ t('Lift') }}
                            </Button>
                            <!-- Said, not just disabled: a member should know it is a rule rather
                                 than a permission they lack. -->
                            <span
                                v-else
                                class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Lock class="size-3.5" aria-hidden="true" />
                                {{ t('Cannot be lifted') }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <EmptyState
            v-else
            :icon="ShieldOff"
            :title="t('Nobody on the list')"
            :description="
                t(
                    'Anyone who asks you to stop is added here automatically when you record it. You can also add a company you handle another way.',
                )
            "
            data-test="no-entries"
        />
    </div>
</template>
