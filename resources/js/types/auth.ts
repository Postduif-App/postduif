export type Availability = 'available' | 'away' | 'do-not-disturb';

export type UserStatus = {
    emoji: string | null;
    text: string;
};

export type User = {
    id: number;
    name: string;
    email: string;
    /** The zone repeating times are read in — see the profile settings. */
    timezone: string;
    status_emoji: string | null;
    status_text: string | null;
    availability: Availability;
    /** Earlier statuses, newest first, offered back by the picker. */
    recent_statuses: UserStatus[];
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /** The signed-in member's own face, or null when they set none. */
    avatarUrl: string | null;
    /**
     * The workspace the settings screens act on, and the way back to the chat.
     * Null for somebody who belongs to none yet.
     */
    workspace: { name: string; slug: string } | null;
    /** Whether this member runs a workspace, and may open its settings. */
    canManageWorkspace: boolean;
    /** Whether they may bring people in, which is a separate screen. */
    canInviteToWorkspace: boolean;
    /**
     * The role held in that workspace, null for somebody in none. For what the
     * interface says about you — every permission still comes from its own
     * flag, so a new role cannot quietly grant anything.
     */
    workspaceRole: WorkspaceRole | null;
    /** Everything the status picker offers, labelled server-side. */
    availabilityOptions: {
        value: Availability;
        label: string;
        description: string;
    }[];
};

export type WorkspaceRole = 'owner' | 'admin' | 'member' | 'guest';

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
