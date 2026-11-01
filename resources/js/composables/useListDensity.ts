import { onMounted, readonly, ref } from 'vue';
import type { Ref } from 'vue';

/** How much air a list row is given. */
export type ListDensity = 'comfortable' | 'compact';

const STORAGE_KEY = 'list-density';

// Module level, like `useAppearance`: one choice for every list in the product. A member who sets
// the leads table dense means "I want dense tables", not "I want this one table dense".
const density = ref<ListDensity>('comfortable');

function stored(): ListDensity | null {
    if (typeof window === 'undefined') return null;

    const value = window.localStorage.getItem(STORAGE_KEY);

    return value === 'compact' || value === 'comfortable' ? value : null;
}

export function useListDensity(): {
    density: Readonly<Ref<ListDensity>>;
    setDensity: (value: ListDensity) => void;
    toggleDensity: () => void;
} {
    onMounted(() => {
        const saved = stored();

        if (saved) density.value = saved;
    });

    function setDensity(value: ListDensity): void {
        density.value = value;

        if (typeof window !== 'undefined') {
            window.localStorage.setItem(STORAGE_KEY, value);
        }
    }

    function toggleDensity(): void {
        setDensity(density.value === 'compact' ? 'comfortable' : 'compact');
    }

    return { density: readonly(density), setDensity, toggleDensity };
}
