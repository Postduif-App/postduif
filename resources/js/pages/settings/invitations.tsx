import { Head, router } from '@inertiajs/react';
import { MailPlus, RotateCw, X } from 'lucide-react';

import Heading from '@/components/heading';
import type {
    InvitableChannel,
    InviteLink,
} from '@/components/invite-links-section';
import { InviteLinksSection } from '@/components/invite-links-section';
import { Button } from '@/components/ui/button';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { destroy, resend } from '@/routes/chat/invitations';

interface PendingInvitation {
    id: number;
    email: string;
    role: string;
    roleLabel: string;
    invitedBy: string;
    expiresAt: string;
    hasExpired: boolean;
    channels: string[];
}

interface InvitationsProps {
    workspaceName: string;
    workspaceSlug: string;
    invitations: PendingInvitation[];
    /** False when this workspace switched shareable links off. */
    inviteLinksEnabled: boolean;
    inviteLinks: InviteLink[];
    /** Non-DM, unarchived channels a link may be pointed at. */
    channels: InvitableChannel[];
}

export default function WorkspaceInvitations({
    workspaceName,
    workspaceSlug,
    invitations,
    inviteLinksEnabled,
    inviteLinks,
    channels,
}: InvitationsProps) {
    const { t } = useTranslate();
    const formats = useFormats();

    return (
        <>
            <Head title={t('settings.invitations.head')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.invitations.title')}
                    description={t('settings.invitations.description', {
                        workspace: workspaceName,
                    })}
                />

                {invitations.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <MailPlus className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            {t('settings.invitations.empty')}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('settings.invitations.empty_hint')}
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border px-3">
                        {invitations.map((invitation) => (
                            <li
                                key={invitation.id}
                                className="flex flex-wrap items-center gap-3 py-3"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">
                                        {invitation.email}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {t('settings.invitations.invited_by', {
                                            role: invitation.roleLabel,
                                            name: invitation.invitedBy,
                                        })}
                                        {invitation.channels.length > 0 &&
                                            ' · ' +
                                                invitation.channels
                                                    .map((name) => `#${name}`)
                                                    .join(', ')}
                                    </span>
                                </span>

                                <span
                                    className={cn(
                                        'shrink-0 text-xs',
                                        invitation.hasExpired
                                            ? 'text-destructive'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {invitation.hasExpired
                                        ? t('settings.invitations.expired')
                                        : t(
                                              'settings.invitations.valid_until',
                                              {
                                                  date: formats.date.format(
                                                      new Date(
                                                          invitation.expiresAt,
                                                      ),
                                                  ),
                                              },
                                          )}
                                </span>

                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            resend.url({
                                                workspace: workspaceSlug,
                                                invitation: invitation.id,
                                            }),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    title={t(
                                        'settings.invitations.resend_hint',
                                    )}
                                >
                                    <RotateCw className="size-3.5" />
                                    {t('settings.invitations.resend')}
                                </Button>

                                <button
                                    type="button"
                                    onClick={() =>
                                        router.delete(
                                            destroy.url({
                                                workspace: workspaceSlug,
                                                invitation: invitation.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                    aria-label={t(
                                        'settings.invitations.revoke_named',
                                        { email: invitation.email },
                                    )}
                                    title={t('settings.invitations.revoke')}
                                    className="shrink-0 rounded p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                >
                                    <X className="size-4" />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                {/*
                    Below the mailed invitations rather than on a tab of its
                    own: both are ways in that are still open, and what you come
                    to this page for is to see all of them at once.
                */}
                {inviteLinksEnabled && (
                    <>
                        <Heading
                            variant="small"
                            title={t('settings.invitations.links')}
                            description={t(
                                'settings.invitations.links_description',
                            )}
                        />

                        <InviteLinksSection
                            workspaceSlug={workspaceSlug}
                            links={inviteLinks}
                            channels={channels}
                        />
                    </>
                )}
            </div>
        </>
    );
}
