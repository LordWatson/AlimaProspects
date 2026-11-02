<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import type {
    AdminCellValue,
    AdminResourceMeta,
    AdminTeamOption,
} from '@/types';

/*
 * Create or edit any registry record (Phase 19a).
 *
 * The form is built from `resource.fields` — the same declaration the server validates against, so
 * the control an operator sees and the rule that judges what they typed cannot disagree. There is
 * no field here the descriptor did not declare, and `ResourceWriteRequest` writes nothing that is
 * not on that list even if the browser posts it.
 *
 * On a **create** for a tenant-owned resource the tenant has to be chosen explicitly, and the help
 * text says why: the panel runs outside every tenant, so `BelongsToTeam`'s usual auto-assignment
 * has nothing to assign. A row created without one would belong to nobody and be invisible to the
 * customer it was made for.
 */
const props = defineProps<{
    resource: AdminResourceMeta;
    teams: AdminTeamOption[];
    record: Record<string, AdminCellValue> | null;
    record_id: string | number | null;
    values: Record<string, AdminCellValue>;
}>();

const isEdit = props.record_id !== null;

/*
 * The form's shape is not known until the descriptor is, so it is an index signature rather than a
 * declared object. That is the one place in this codebase where that is the honest type: the fields
 * are data, and the server validates them against the same declaration that rendered them.
 */
type DynamicFormData = Record<string, string | number | boolean | null>;

const initial: DynamicFormData = { ...props.values };

if (!isEdit && props.resource.tenant_owned) {
    initial.team_id = '';
}

const form = useForm<DynamicFormData>(initial);

function submit(): void {
    if (isEdit) {
        form.patch(
            `/admin/resources/${props.resource.key}/${props.record_id}`,
            { preserveScroll: true },
        );

        return;
    }

    form.post(`/admin/resources/${props.resource.key}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head
        :title="`${isEdit ? 'Edit' : 'New'} ${resource.singular.toLowerCase()}`"
    />

    <div class="flex max-w-2xl flex-col gap-6">
        <div>
            <Button variant="ghost" size="sm" as-child>
                <Link
                    :href="
                        isEdit
                            ? `/admin/resources/${resource.key}/${record_id}`
                            : `/admin/resources/${resource.key}`
                    "
                >
                    <ArrowLeft class="size-4" />
                    Back
                </Link>
            </Button>
        </div>

        <header>
            <p
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                {{ resource.group_label }}
            </p>
            <h1 class="mt-1 text-2xl font-semibold text-foreground">
                {{ isEdit ? 'Edit' : 'New' }}
                {{ resource.singular.toLowerCase() }}
            </h1>
        </header>

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <div
                v-if="!isEdit && resource.tenant_owned"
                class="flex flex-col gap-1.5"
            >
                <Label for="field-team_id">Tenant</Label>
                <NativeSelect
                    id="field-team_id"
                    v-model="form['team_id'] as string"
                    required
                >
                    <option value="" disabled>Choose a tenant…</option>
                    <option
                        v-for="team in teams"
                        :key="team.id"
                        :value="String(team.id)"
                    >
                        {{ team.name }}
                    </option>
                </NativeSelect>
                <p class="text-xs text-muted-foreground">
                    The panel runs outside every tenant, so this cannot be
                    filled in for you — a record with no tenant belongs to
                    nobody.
                </p>
                <InputError :message="form.errors['team_id']" />
            </div>

            <div
                v-for="field in resource.fields"
                :key="field.name"
                class="flex flex-col gap-1.5"
            >
                <template v-if="field.type === 'boolean'">
                    <div class="flex items-center gap-2">
                        <Checkbox
                            :id="`field-${field.name}`"
                            v-model="form[field.name] as boolean"
                        />
                        <Label :for="`field-${field.name}`">
                            {{ field.label }}
                        </Label>
                    </div>
                </template>

                <template v-else>
                    <Label :for="`field-${field.name}`">
                        {{ field.label }}
                        <span
                            v-if="field.required"
                            class="text-destructive"
                            aria-hidden="true"
                        >
                            *
                        </span>
                    </Label>

                    <Textarea
                        v-if="field.type === 'textarea'"
                        :id="`field-${field.name}`"
                        v-model="form[field.name] as string"
                        rows="6"
                        :required="field.required"
                    />

                    <NativeSelect
                        v-else-if="field.type === 'select'"
                        :id="`field-${field.name}`"
                        v-model="form[field.name] as string"
                        :required="field.required"
                    >
                        <option value="">—</option>
                        <option
                            v-for="(label, value) in field.options"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </NativeSelect>

                    <Input
                        v-else
                        :id="`field-${field.name}`"
                        v-model="form[field.name] as string"
                        :type="
                            field.type === 'integer'
                                ? 'number'
                                : field.type === 'date'
                                  ? 'datetime-local'
                                  : 'text'
                        "
                        :required="field.required"
                    />
                </template>

                <p v-if="field.help" class="text-xs text-muted-foreground">
                    {{ field.help }}
                </p>
                <InputError :message="form.errors[field.name]" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{
                        isEdit
                            ? 'Save changes'
                            : `Create ${resource.singular.toLowerCase()}`
                    }}
                </Button>
                <Button variant="ghost" type="button" as-child>
                    <Link
                        :href="
                            isEdit
                                ? `/admin/resources/${resource.key}/${record_id}`
                                : `/admin/resources/${resource.key}`
                        "
                    >
                        Cancel
                    </Link>
                </Button>
            </div>
        </form>
    </div>
</template>
