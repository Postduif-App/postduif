export type ChannelType = 'public' | 'private' | 'dm';

export interface ChatWorkspace {
    id: number;
    name: string;
    slug: string;
}

export interface ChannelSummary {
    id: number;
    type: ChannelType;
    name: string | null;
    label: string;
    isMember: boolean;
}

export interface ActiveChannel extends ChannelSummary {
    topic: string | null;
    memberCount: number;
}

export interface MessageAuthor {
    id: number;
    name: string;
}

export interface MessageReaction {
    emoji: string;
    count: number;
    reacted: boolean;
}

export interface ChatMessage {
    id: string;
    body: string;
    createdAt: string | null;
    editedAt: string | null;
    replyCount: number;
    author: MessageAuthor;
    reactions: MessageReaction[];
    /** Set while the message is only in the browser, awaiting the server echo. */
    pending?: boolean;
}

export interface SearchHit {
    id: string;
    body: string;
    createdAt: string | null;
    author: string;
    channel: {
        id: number;
        name: string | null;
        type: ChannelType;
    };
}
