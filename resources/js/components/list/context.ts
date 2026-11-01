import type { InjectionKey, Ref } from 'vue';
import type { ListDensity } from '@/composables/useListDensity';

/**
 * How much air this table's rows are given, passed from `ListTable` to every head and cell under
 * it. Injected rather than threaded through props: a density that has to be repeated on forty
 * cells is a density that will be wrong on one of them.
 */
export const listDensityKey: InjectionKey<Readonly<Ref<ListDensity>>> =
    Symbol('alima-list-density');
