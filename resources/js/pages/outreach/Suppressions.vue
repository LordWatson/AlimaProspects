<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { FileText, Lock, Plus, ShieldOff, Trash2, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import { FormField } from '@/components/forms';
import { ListCell, ListHead, ListRow, ListTable } from '@/components/list';
import PageHero from '@/components/PageHero.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    OutreachSuppression,
    SuppressIdentityForm,
    SuppressionType,
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
 * The rebuild added the one thing this screen was missing, and it is a compliance point rather
 * than a nicety: **lifting an entry now asks.** Everywhere else in the product a single click
 * undoes something a member can see; here it re-opens a person to contact, and "Lift" sat one
 * mis-click away from the row above it.
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

const props = defineProps<{
    entries: OutreachSuppression[];
    options: { types: SuppressionTypeOption[] };
    can: { manage: boolean };
}>();

const { t } = useI18n();
const { date, number } = useFormat();

const form = useForm<SuppressIdentityForm>({
    type: 'email',
    value: '',
    note: '',
});

/** Which row is asking before it re-opens somebody to contact. */
const confirmingLift = ref<number | null>(null);

const valuePlaceholder = computed(() => {
    if (form.type === 'domain') return t('acme.com');
    if (form.type === 'phone') return t('+44 20 7946 0000');
    if (form.type === 'company_number') return t('01234567');

    return t('name@acme.com');
});

function submit(): void {
    form.post('/outreach/do-not-contact', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

/*
 * The bulk load (PL5). A second form rather than a mode on the first, because the two are answering
 * different questions — "block this one company" and "here is everything we already serve" — and a
 * single form that did both would need a toggle nobody reads.
 *
 * It asks for the **type** and then takes every value in the file. There is no column mapping,
 * because a mapping screen is a second page between a member and a list they already have: a
 * one-column export works, and so does a pasted spreadsheet column.
 */
const importForm = useForm<{
    type: SuppressionType;
    values: string;
    file: File | null;
    note: string;
}>({
    type: 'email',
    values: '',
    file: null,
    note: '',
});

function importValues(): void {
    // `forceFormData`, because the file is optional and Inertia would otherwise send JSON on the
    // paste-only path and multipart on the other — two shapes for one endpoint.
    importForm.post('/outreach/do-not-contact/import', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => importForm.reset(),
    });
}

function pickFile(event: Event): void {
    const input = event.target as HTMLInputElement;

    importForm.file = input.files?.[0] ?? null;
}

function lift(entry: OutreachSuppression): void {
    router.delete(`/outreach/do-not-contact/${entry.id}`, {
        preserveScroll: true,
        onFinish: () => (confirmingLift.value = null),
    });
}
</script>

<template>
    <Head :title="t('Do not contact')" />

    <div class="flex flex-col gap-6 py-2">
        <PageHero
            index="08"
            :eyebrow="t('Outreach')"
            :title="t('Do not|contact.')"
            :description="
                t(
                    'Nothing is ever sent to anyone on this list. Alima checks it when you draft and again the moment before it sends.',
                )
            "
            size="compact"
        >
            <template #actions>
                <Button variant="hero" as-child>
                    <Link href="/outreach/templates">
                        <FileText class="size-4" />
                        {{ t('Briefs') }}
                    </Link>
                </Button>
            </template>
        </PageHero>

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
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField
                        id="dnc-type"
                        :label="t('What to block')"
                        :error="form.errors.type"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <NativeSelect
                                :id="id"
                                v-model="form.type"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            >
                                <option
                                    v-for="type in props.options.types"
                                    :key="type.value"
                                    :value="type.value"
                                >
                                    {{ t(type.label) }}
                                </option>
                            </NativeSelect>
                        </template>
                    </FormField>

                    <FormField
                        id="dnc-value"
                        :label="t('Address, domain or number')"
                        required
                        :error="form.errors.value"
                        :hint="
                            t(
                                'Block a domain to stop contacting anyone at that company.',
                            )
                        "
                        class="lg:col-span-2"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.value"
                                required
                                dir="ltr"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                :placeholder="valuePlaceholder"
                                data-test="dnc-value"
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="dnc-note"
                        :label="t('Why')"
                        optional
                        :error="form.errors.note"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.note"
                                dir="auto"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                :placeholder="t('e.g. existing client')"
                            />
                        </template>
                    </FormField>
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

        <!-- Bulk load (PL5) -->
        <section
            v-if="can.manage"
            class="space-y-4 rounded-2xl border border-border bg-card p-6"
        >
            <h2
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                {{ t('Load a list') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{
                    t(
                        'Paste a column from your CRM, or upload a CSV. Say what the values are and we will read every cell. We store them scrambled so they cannot be read back — never the values themselves.',
                    )
                }}
            </p>

            <form class="space-y-4" @submit.prevent="importValues">
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField
                        id="dnc-import-type"
                        :label="t('What the list contains')"
                        :error="importForm.errors.type"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <NativeSelect
                                :id="id"
                                v-model="importForm.type"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            >
                                <option
                                    v-for="type in props.options.types"
                                    :key="type.value"
                                    :value="type.value"
                                >
                                    {{ t(type.label) }}
                                </option>
                            </NativeSelect>
                        </template>
                    </FormField>

                    <FormField
                        id="dnc-import-note"
                        :label="t('Why')"
                        optional
                        :error="importForm.errors.note"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="importForm.note"
                                dir="auto"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                :placeholder="t('e.g. existing clients')"
                            />
                        </template>
                    </FormField>
                </div>

                <FormField
                    id="dnc-import-values"
                    :label="t('Paste the values')"
                    optional
                    :error="importForm.errors.values"
                    :hint="
                        t(
                            'One per line, or separated by commas. Use this or the file below — whichever is easier.',
                        )
                    "
                >
                    <template #default="{ id, describedBy, invalid }">
                        <Textarea
                            :id="id"
                            v-model="importForm.values"
                            rows="4"
                            dir="ltr"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            data-test="dnc-import-values"
                        />
                    </template>
                </FormField>

                <FormField
                    id="dnc-import-file"
                    :label="t('Or upload a CSV')"
                    optional
                    :error="importForm.errors.file"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <input
                            :id="id"
                            type="file"
                            accept=".csv,.txt,text/csv,text/plain"
                            class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground file:me-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-sm file:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            data-test="dnc-import-file"
                            @change="pickFile"
                        />
                    </template>
                </FormField>

                <Button
                    type="submit"
                    variant="outline"
                    :disabled="importForm.processing"
                    data-test="dnc-import"
                >
                    <Upload class="size-4" aria-hidden="true" />
                    {{ t('Load the list') }}
                </Button>
            </form>
        </section>

        <!-- The list -->
        <ListTable
            v-if="entries.length"
            :caption="
                t(
                    'Everyone your team will not contact, and why — :count in all',
                    {
                        count: number(entries.length),
                    },
                )
            "
        >
            <thead>
                <tr>
                    <ListHead :label="t('Entry')" />
                    <ListHead :label="t('Reason')" />
                    <ListHead :label="t('Applies to')" hide="sm" />
                    <ListHead :label="t('Added')" hide="md" align="end" />
                    <ListHead :label="t('Actions')" align="end">
                        <span class="sr-only">{{ t('Actions') }}</span>
                    </ListHead>
                </tr>
            </thead>
            <tbody data-test="dnc-entries">
                <ListRow v-for="entry in entries" :key="entry.id">
                    <ListCell>
                        <!-- The mask is all there is: no column holds the value in the clear. -->
                        <span class="font-mono" dir="ltr">{{
                            entry.value_preview
                        }}</span>
                        <span class="block text-xs text-muted-foreground">
                            {{ t(entry.type_label) }}
                        </span>
                    </ListCell>
                    <ListCell>
                        {{ t(entry.reason_label) }}
                        <p
                            v-if="entry.note"
                            class="text-xs text-muted-foreground"
                            dir="auto"
                        >
                            {{ entry.note }}
                        </p>
                        <!-- On a phone the scope and date columns are gone; the scope is the half
                             that changes what the row means. -->
                        <p class="text-xs text-muted-foreground sm:hidden">
                            {{ t(entry.scope_label) }}
                        </p>
                    </ListCell>
                    <ListCell hide="sm">
                        <Badge
                            :variant="
                                entry.scope === 'global'
                                    ? 'secondary'
                                    : 'outline'
                            "
                        >
                            {{ t(entry.scope_label) }}
                        </Badge>
                    </ListCell>
                    <ListCell hide="md" muted mono align="end">
                        {{ date(entry.suppressed_at) }}
                    </ListCell>
                    <ListCell align="end">
                        <template v-if="entry.can_delete">
                            <span
                                v-if="confirmingLift === entry.id"
                                class="inline-flex flex-wrap items-center justify-end gap-2"
                            >
                                <span class="text-xs text-foreground">{{
                                    t('Contact them again?')
                                }}</span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="confirmingLift = null"
                                >
                                    {{ t('Cancel') }}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    :data-test="`confirm-lift-${entry.id}`"
                                    @click="lift(entry)"
                                >
                                    {{ t('Lift') }}
                                </Button>
                            </span>
                            <Button
                                v-else
                                size="sm"
                                variant="ghost"
                                :aria-label="t('Lift this entry')"
                                :data-test="`lift-${entry.id}`"
                                @click="confirmingLift = entry.id"
                            >
                                <Trash2 class="size-4" />
                                {{ t('Lift') }}
                            </Button>
                        </template>
                        <!-- Said, not just disabled: a member should know it is a rule rather
                             than a permission they lack. -->
                        <span
                            v-else
                            class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <Lock class="size-3.5" aria-hidden="true" />
                            {{ t('Cannot be lifted') }}
                        </span>
                    </ListCell>
                </ListRow>
            </tbody>
        </ListTable>

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
