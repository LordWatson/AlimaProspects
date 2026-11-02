// Ideal Customer Profile (ICP) shared types (Phase 4).

/** A criterion type the editor offers, with its label, input hint and exclusion flag. */
export type CriterionTypeMeta = {
    value: string;
    label: string;
    placeholder: string;
    isExclusion: boolean;
};

/** One criterion as the API serialises it. */
export type IcpCriterion = {
    id: number;
    type: string;
    type_label: string;
    value: string;
    weight: number | null;
    is_exclusion: boolean;
    position: number;
};

/** An ICP as the index/editor receive it. */
export type Icp = {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    criteria: IcpCriterion[];
    updated_at: string | null;
};

/** An editable criterion row in the editor form (weight null for exclusions). */
export type CriterionFormRow = {
    type: string;
    value: string;
    weight: number | null;
};
