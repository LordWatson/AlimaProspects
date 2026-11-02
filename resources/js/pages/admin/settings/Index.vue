<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { OctagonAlert, PencilLine } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { PlatformSetting } from '@/types';

/*
 * The platform switches (Phase 19b, ADR 0015).
 *
 * The operational ones come first and are labelled as such, because this page gets opened at 2am by
 * somebody who needs exactly one of them and should not have to read the others to find it. Each
 * row says what the setting does **and what it does not** — the Stripe switch's help text leads
 * with "it does not change anybody's plan", because that is the first thing an operator will
 * assume and the assumption that would stop them using it.
 *
 * `is_overridden` is rendered rather than hidden: a value alone does not tell you whether somebody
 * chose it, and "has anyone touched this?" is the question an incident starts with.
 */
const props = defineProps<{
    settings: PlatformSetting[];
}>();

const operational = computed(() => props.settings.filter((s) => s.operational));
const tuning = computed(() => props.settings.filter((s) => !s.operational));

/*
 * One form over every setting, keyed by name. The server applies them one at a time so each is its
 * own audited decision with its own before and after — but an operator thinks in terms of a page
 * they save, not a row they submit.
 */
/**
 * A settings value is a boolean, a number or a list of strings — the three `SettingType` cases.
 * Typed as that union rather than `unknown`, because Inertia's form helper narrows an `unknown`
 * index signature to `never` and nothing can then be assigned to it.
 */
type SettingValue = boolean | number | string | string[];

const form = useForm<{ settings: Record<string, SettingValue> }>({
    settings: Object.fromEntries(
        props.settings.map((s) => [s.key, s.value as SettingValue]),
    ),
});

/**
 * What this save would actually change.
 *
 * The page's real hazard is not a wrong value, it is a **right value saved alongside a wrong one**:
 * every setting is on one form, so somebody who came for `signals` in the middle of an incident can
 * carry a half-typed kill-switch list with them. Listing the pending changes is what makes the save
 * reviewable before it is platform-wide.
 */
const changes = computed(() =>
    props.settings
        .filter(
            (s) =>
                JSON.stringify(form.settings[s.key]) !==
                JSON.stringify(s.value),
        )
        .map((s) => ({
            key: s.key,
            label: s.label,
            from: describe(s.value as SettingValue),
            to: describe(form.settings[s.key]),
            // A switch going off is the direction that takes a feature away from every tenant at
            // once, so it is the one that has to be confirmed rather than merely shown.
            disabling:
                (s.type === 'bool' &&
                    s.value === true &&
                    form.settings[s.key] === false) ||
                (s.type === 'string_list' &&
                    (form.settings[s.key] as string[]).length >
                        (s.value as string[]).length),
        })),
);

const disabling = computed(() => changes.value.filter((c) => c.disabling));

/** Whether the operator has seen the summary and pressed again. */
const confirming = ref(false);

function describe(value: SettingValue): string {
    if (typeof value === 'boolean') {
        return value ? 'on' : 'off';
    }

    return Array.isArray(value)
        ? value.length === 0
            ? 'nothing'
            : value.join(', ')
        : String(value);
}

function submit(): void {
    // Nothing destructive pending, or already confirmed: go.
    if (disabling.value.length > 0 && !confirming.value) {
        confirming.value = true;

        return;
    }

    form.patch('/admin/settings', {
        preserveScroll: true,
        onFinish: () => {
            confirming.value = false;
        },
    });
}

/** A `string_list` is typed as one value per line, which is how an operator writes a list. */
function listAsText(value: unknown): string {
    return Array.isArray(value) ? value.join('\n') : '';
}

function setList(key: string, text: string): void {
    form.settings[key] = text
        .split(/[\s,]+/)
        .map((item) => item.trim())
        .filter((item) => item !== '');
}
</script>

<template>
    <Head title="Platform settings" />

    <form class="flex max-w-3xl flex-col gap-8" @submit.prevent="submit">
        <header>
            <p
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                Platform
            </p>
            <h1 class="mt-1 text-2xl font-semibold text-foreground">
                Settings
            </h1>
            <p class="mt-2 max-w-prose text-sm text-muted-foreground">
                Overrides on top of <code>config/admin.php</code>. A setting
                left at its default stores no row, so it keeps following the
                deploy; changing one records what it replaced and who changed
                it.
            </p>
        </header>

        <section
            v-for="group in [
                { label: 'Operational switches', items: operational },
                { label: 'Tuning', items: tuning },
            ]"
            :key="group.label"
            class="flex flex-col gap-4"
        >
            <h2
                v-if="group.items.length > 0"
                class="text-lg font-semibold text-foreground"
            >
                {{ group.label }}
            </h2>

            <div
                v-for="setting in group.items"
                :key="setting.key"
                class="flex flex-col gap-2 rounded-2xl border border-border bg-card px-5 py-4"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Label :for="`setting-${setting.key}`" class="text-base">
                        {{ setting.label }}
                    </Label>
                    <!--
                      Neutral, deliberately. "Somebody chose this" is information, and dressing it
                      as `status.warning` with an alert triangle spends the amber on a non-problem
                      — which is how a real warning stops being noticed.
                    -->
                    <Badge v-if="setting.is_overridden" variant="outline">
                        <PencilLine class="size-3" aria-hidden="true" />
                        Overridden
                    </Badge>
                    <code
                        class="ms-auto font-mono text-xs text-muted-foreground"
                    >
                        {{ setting.key }}
                    </code>
                </div>

                <p class="max-w-prose text-sm text-muted-foreground">
                    {{ setting.help }}
                </p>

                <div class="pt-1">
                    <div
                        v-if="setting.type === 'bool'"
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :id="`setting-${setting.key}`"
                            v-model="form.settings[setting.key] as boolean"
                        />
                        <!--
                          `aria-hidden`, not a second `<Label for>`: two labels pointing at one
                          checkbox are concatenated, so a screen reader announced
                          "Stripe enabled On" as the control's name.
                        -->
                        <span
                            class="text-sm text-foreground"
                            aria-hidden="true"
                        >
                            {{ form.settings[setting.key] ? 'On' : 'Off' }}
                        </span>
                    </div>

                    <Textarea
                        v-else-if="setting.type === 'string_list'"
                        :id="`setting-${setting.key}`"
                        rows="3"
                        :model-value="listAsText(form.settings[setting.key])"
                        placeholder="One per line"
                        @update:model-value="
                            setList(setting.key, String($event))
                        "
                    />

                    <Input
                        v-else
                        :id="`setting-${setting.key}`"
                        v-model="form.settings[setting.key] as string"
                        type="number"
                        min="0"
                        class="max-w-40"
                    />
                </div>

                <InputError :message="form.errors[`settings.${setting.key}`]" />
            </div>
        </section>

        <div class="flex flex-col gap-3">
            <!--
              The pending changes, in words. `status.*` for the border and the icon, and the words
              in `text-foreground`: status-coloured text on a tint of the same status does not clear
              AA at this size.
            -->
            <div
                v-if="changes.length > 0"
                class="flex flex-col gap-1 rounded-2xl border px-5 py-4"
                :class="
                    disabling.length > 0
                        ? 'border-warning/30 bg-warning/12'
                        : 'border-border bg-card'
                "
            >
                <p class="font-semibold text-foreground">
                    {{ changes.length }}
                    {{ changes.length === 1 ? 'change' : 'changes' }} pending
                </p>
                <ul class="flex flex-col gap-0.5 text-sm text-foreground/90">
                    <li v-for="change in changes" :key="change.key">
                        <span class="font-medium">{{ change.label }}</span>
                        — {{ change.from }} → {{ change.to }}
                    </li>
                </ul>
            </div>

            <div v-if="confirming" class="flex flex-wrap items-center gap-3">
                <OctagonAlert
                    class="size-4 shrink-0 text-destructive"
                    aria-hidden="true"
                />
                <p class="text-sm text-foreground">
                    This takes something away from every tenant at once. Save
                    anyway?
                </p>
                <Button v-focus type="submit" variant="destructive">
                    Save and apply
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    @click="confirming = false"
                >
                    Cancel
                </Button>
            </div>

            <div v-else>
                <Button
                    type="submit"
                    :disabled="form.processing || changes.length === 0"
                >
                    Save settings
                </Button>
            </div>
        </div>
    </form>
</template>
