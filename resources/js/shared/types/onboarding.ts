// Onboarding & Business Profile shared types (Phase 3).

/** Lightweight status shared on every page so the shell can nudge setup. */
export type OnboardingShared = {
    complete: boolean;
    started: boolean;
    currentStep: string;
};

export type OnboardingStepMeta = {
    key: string;
    label: string;
    position: number;
    isData: boolean;
};

export type OnboardingOption = { value: string; label: string };

export type OnboardingOptions = {
    companySizes: OnboardingOption[];
    regions: OnboardingOption[];
    markets: OnboardingOption[];
    currencies: OnboardingOption[];
};

export type OnboardingProgressProp = {
    currentStep: string;
    completedSteps: string[];
    isComplete: boolean;
};

/** The editable profile payload the wizard renders (pricing in major units). */
export type BusinessProfileForm = {
    company_name: string | null;
    website: string | null;
    industry: string | null;
    company_size: string | null;
    locations: string[];
    regions_served: string[];
    primary_market: string | null;
    products_services: string[];
    value_proposition: string | null;
    pricing_min: number | null;
    pricing_max: number | null;
    pricing_currency: string | null;
    customer_examples: string[];
    version: number;
    isComplete: boolean;
};
