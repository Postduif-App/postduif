import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import { useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { buttonVariants } from '@/components/ui/button';
import { UserMenu } from '@/components/user-menu-content';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useFormats } from '@/hooks/use-formats';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { index as formsIndex } from '@/routes/chat/forms';
import { exportMethod as exportAnswers } from '@/routes/chat/forms/answers';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
    ScheduledBroadcast,
    WorkspaceOption,
} from '@/types/chat';

interface Column {
    key: string;
    label: string;
}

interface Submission {
    id: string;
    /** ISO, or null for a row whose stamp went missing. */
    when: string | null;
    /** A name, or null for somebody who filled it in from outside. */
    who: string | null;
    viaLink: boolean;
    answers: Record<string, string>;
}

interface FormAnswersProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    activeThreads: ActiveThread[];
    workspaceTags: string[];
    archivedChannels: ArchivedChannel[];
    sections: ChannelSectionRow[];
    inboxUnread: number;
    scheduledBroadcasts: ScheduledBroadcast[];
    workspaces: WorkspaceOption[];
    form: { id: string; title: string; submissions: number };
    /**
     * Every question these answers were ever given to — the form's current ones
     * first, then anything a submission remembers that the form no longer asks.
     * Worked out server side, so a deleted question keeps its column.
     */
    columns: Column[];
    submissions: Submission[];
}

/**
 * What came back, as a table.
 *
 * The DM is how somebody hears about one submission; this is where they come
 * looking for all of them at once. Which is why the table scrolls sideways
 * rather than wrapping: a form with fifteen questions has fifteen columns, and
 * a column squeezed to nothing is a column nobody can read. The scrolling
 * happens inside the table's own frame, so the chat shell around it stays put.
 */
export default function FormAnswers({
    workspace,
    channels,
    directMessages,
    activeThreads,
    workspaceTags,
    archivedChannels,
    sections,
    inboxUnread,
    scheduledBroadcasts,
    workspaces,
    form,
    columns,
    submissions,
}: FormAnswersProps) {
    useSessionGuard();

    const { t, tChoice } = useTranslate();
    const formats = useFormats();

    const [searchOpen, setSearchOpen] = useState(false);
    useCommandPaletteShortcut(setSearchOpen);

    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

    const userMenu = <UserMenu />;

    return (
        <div className="flex h-dvh overflow-hidden bg-background">
            <Head
                title={t('forms.answers_screen.title', { form: form.title })}
            />

            <ChannelSidebar
                workspace={workspace}
                inboxUnread={inboxUnread}
                workspaces={workspaces}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                formsActive
                archivedChannels={archivedChannels}
                sections={sections}
                onOpenSearch={() => setSearchOpen(true)}
                onCreateChannel={() => setCreateOpen(true)}
                userMenu={userMenu}
                onStartDirectMessage={() => setDirectOpen(true)}
                onInvitePeople={() => setInviteOpen(true)}
                onBroadcast={() => setBroadcastOpen(true)}
            />

            <main className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                    <Link
                        href={formsIndex.url(workspace.slug)}
                        aria-label={t('forms.answers_screen.back')}
                        className="shrink-0 text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                    </Link>

                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            {t('forms.answers_screen.title', {
                                form: form.title,
                            })}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            {tChoice(
                                'forms.answers_screen.count',
                                form.submissions,
                            )}
                        </p>
                    </div>

                    {/*
                        A plain link rather than a fetch: this is a file the
                        browser should hand to the operating system, and an
                        Inertia visit would only try to make a page out of it.
                    */}
                    <a
                        href={exportAnswers.url({
                            workspace: workspace.slug,
                            form: form.id,
                        })}
                        className={cn(
                            'ml-auto shrink-0',
                            buttonVariants({ variant: 'outline', size: 'sm' }),
                        )}
                    >
                        <Download className="size-4" />
                        {t('forms.answers_screen.export')}
                    </a>
                </header>

                <div className="flex-1 overflow-y-auto p-4">
                    {submissions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('forms.answers_screen.none')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/40 text-left">
                                        <th className="px-3 py-2 font-medium whitespace-nowrap">
                                            {t('forms.answers_screen.when')}
                                        </th>
                                        <th className="px-3 py-2 font-medium whitespace-nowrap">
                                            {t('forms.answers_screen.who')}
                                        </th>
                                        {columns.map((column) => (
                                            <th
                                                key={column.key}
                                                className="px-3 py-2 font-medium whitespace-nowrap"
                                            >
                                                {column.label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>

                                <tbody>
                                    {submissions.map((submission) => (
                                        <tr
                                            key={submission.id}
                                            className="border-b last:border-b-0"
                                        >
                                            <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                                {submission.when === null
                                                    ? t('forms.answers.empty')
                                                    : formats.dateTime.format(
                                                          new Date(
                                                              submission.when,
                                                          ),
                                                      )}
                                            </td>

                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {/*
                                                    A missing name is not a gap:
                                                    it is somebody who answered
                                                    through the shared link, and
                                                    the screen says so rather
                                                    than leaving an empty cell to
                                                    be read as a bug.
                                                */}
                                                {submission.who ?? (
                                                    <span className="text-muted-foreground">
                                                        {t(
                                                            'forms.answers.anonymous',
                                                        )}
                                                    </span>
                                                )}

                                                {submission.viaLink && (
                                                    <span className="ml-2 rounded border border-amber-500/40 px-1.5 py-0.5 text-xs text-amber-700 dark:text-amber-400">
                                                        {t(
                                                            'forms.answers.via_link',
                                                        )}
                                                    </span>
                                                )}
                                            </td>

                                            {columns.map((column) => (
                                                <td
                                                    key={column.key}
                                                    className="px-3 py-2 align-top"
                                                >
                                                    {submission.answers[
                                                        column.key
                                                    ] ?? (
                                                        <span className="text-muted-foreground">
                                                            {t(
                                                                'forms.answers.empty',
                                                            )}
                                                        </span>
                                                    )}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </main>

            <SearchDialog
                workspace={workspace}
                channels={channels}
                directMessages={directMessages}
                actions={{
                    onCreateChannel: workspace.canCreateChannel
                        ? () => setCreateOpen(true)
                        : undefined,
                    onStartDirectMessage: workspace.canStartDirectMessage
                        ? () => setDirectOpen(true)
                        : undefined,
                    onInvitePeople: workspace.canInvite
                        ? () => setInviteOpen(true)
                        : undefined,
                    onBroadcast: workspace.canBroadcastToChannels
                        ? () => setBroadcastOpen(true)
                        : undefined,
                }}
                open={searchOpen}
                onOpenChange={setSearchOpen}
            />

            <CreateChannelDialog
                workspace={workspace}
                open={createOpen}
                onOpenChange={setCreateOpen}
            />

            {workspace.canStartDirectMessage && (
                <NewDirectMessageDialog
                    workspace={workspace}
                    open={directOpen}
                    onOpenChange={setDirectOpen}
                />
            )}

            {workspace.canInvite && (
                <InvitePeopleDialog
                    workspace={workspace}
                    channels={channels.filter((row) => row.type !== 'dm')}
                    open={inviteOpen}
                    onOpenChange={setInviteOpen}
                />
            )}

            <BroadcastDialog
                workspace={workspace}
                channels={channels}
                scheduledBroadcasts={scheduledBroadcasts}
                tags={workspaceTags}
                open={broadcastOpen}
                onOpenChange={setBroadcastOpen}
            />
        </div>
    );
}
