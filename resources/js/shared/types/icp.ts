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

/**
 * What the AI composer drafted from a sentence — never a saved profile.
 *
 * `viable: false` with a `reason` is a real answer ("rich people in Dubai" is not a profile), and
 * the shape of `profile` is the shape the editor posts back, so nothing has to be translated.
 * `is_active` is absent on purpose: whether this becomes the account's active profile is the
 * member's decision, so the editor's own tick box keeps whatever it was set to.
 */
export type IcpDraft = {
    viable: boolean;
    reason: string | null;
    summary: string | null;
    profile: {
        name: string;
        description: string | null;
        criteria: CriterionFormRow[];
    } | null;
};

/** An editable criterion row in the editor form (weight null for exclusions). */
export type CriterionFormRow = {
    type: string;
    value: string;
    weight: number | null;
};
