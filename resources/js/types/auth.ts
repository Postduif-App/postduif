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
    /** The chosen language, or null for "follow the browser". */
    locale: string | null;
    /**
     * A few lines beside the name, or null for somebody who wrote none. Read
     * on the member page — see chat/member.
     */
    bio: string | null;
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
     * Whether they may switch parts of the product on and off. The one
     * workspace screen managing the place does not come with: it is seeded to
     * the owner alone.
     */
    canManageFeatures: boolean;
    /** Whether the settings navigation should offer the workflow builder. */
    canManageWorkflows: boolean;
    /** The same, for the outgoing webhooks a workspace sets on its contracts. */
    canManageContractWebhooks: boolean;
    /**
     * The clock, or null where this workspace has none — switched off, or the
     * person is a guest here.
     *
     * Shared rather than fetched per screen because the button lives in the
     * user menu, which is on every page. `runningSince` is the moment the open
     * shift began, so the menu can count without asking the server again.
     */
    timeclock: { runningSince: string | null } | null;
    /**
     * Whether the person is in that workspace from outside.
     *
     * A fact rather than the name of their role: a workspace writes its own
     * roles now, so "is this a guest" cannot be answered by comparing against a
     * string this side happens to know. Every permission still comes from its
     * own flag, so this grants nothing on its own.
     */
    workspaceIsExternal: boolean;
    /** Everything the status picker offers, labelled server-side. */
    availabilityOptions: {
        value: Availability;
        label: string;
        description: string;
    }[];
};

export type SystemRole = 'owner' | 'admin' | 'member' | 'guest';

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
