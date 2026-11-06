export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type TeamRole = 'owner' | 'admin' | 'member' | 'read-only';

export type CurrentTeam = {
    id: number;
    name: string;
    role: TeamRole | null;
    timezone: string;
    locale: string;
    market: string;
};

export type TeamSummary = {
    id: number;
    name: string;
    role: TeamRole | null;
    is_current: boolean;
};

export type Auth = {
    user: User;
    currentTeam?: CurrentTeam | null;
    teams?: TeamSummary[];
    /**
     * Whether this reader is a platform operator.
     *
     * The shell reads it because an operator belongs to **no team**, so the team-scoped rail would
     * be a column of 404s for them; it offers the panel instead. A boolean rather than the role:
     * which role an operator holds decides what the panel lets them do, and nothing outside the
     * panel should branch on that.
     */
    isOperator?: boolean;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
