<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';

/*
 * "You have not saved this yet" — the other half of `useUnsavedGuard`.
 *
 * Deliberately a real dialog rather than `window.confirm`: a native prompt cannot be translated,
 * cannot be set in the brand's type, and says "Leave site?" about a navigation that never leaves
 * the site. Escape and the overlay both mean *stay*, which is the safe answer; leaving takes a
 * deliberate click.
 *
 * The leaving button is `outline`, not `destructive`: throwing away a draft nobody has saved is
 * not a destructive operation on anybody's data, and reserving the red for the things that really
 * are irreversible is what keeps it meaning something.
 */
const props = defineProps<{ open: boolean }>();

const emit = defineEmits<{ stay: []; discard: [] }>();

const { t } = useI18n();
</script>

<template>
    <Dialog
        :open="props.open"
        @update:open="(value: boolean) => !value && emit('stay')"
    >
        <DialogContent class="sm:max-w-md" data-test="unsaved-dialog">
            <DialogHeader>
                <DialogTitle>{{ t('Keep what you have typed?') }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'This form has changes you have not saved. Leave now and they are gone.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="outline"
                    data-test="unsaved-discard"
                    @click="emit('discard')"
                >
                    {{ t('Leave without saving') }}
                </Button>
                <Button
                    variant="brand"
                    data-test="unsaved-stay"
                    @click="emit('stay')"
                >
                    {{ t('Stay on this form') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
