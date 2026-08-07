import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList, ListChecks, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelMenuButton } from '@/components/chat/channel-menu';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { UserMenu } from '@/components/user-menu-content';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import {
    answers as answersRoute,
    destroy,
    edit,
    store,
} from '@/routes/chat/forms';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
    ScheduledBroadcast,
    WorkspaceOption,
} from '@/types/chat';

/**
 * A form as this list needs it: what it is called, who wrote it, how far along
 * it is.
 *
 * Not its questions. Those belong to the builder — sending every question of
 * every form to a screen that draws a title and two counts is a page that gets
 * slower with each questionnaire somebody writes.
 */
interface FormSummary {
    id: string;
    title: string;
    description: string | null;
    author: string | null;
    state: 'open' | 'closed' | 'expired';
    isShared: boolean;
    submissions: number;
    fields: number;
    /** Whether this particular member may open it. The list shows them all. */
    canEdit: boolean;
    /**
     * Whether they may read what came back. Asked apart from canEdit because
     * the two are different acts — see WorkspaceFormController::index.
     */
    canViewAnswers: boolean;
}

interface FormsProps {
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
    forms: FormSummary[];
}

/**
 * How a form stands, in one word.
 *
 * "Gesloten" and "verstreken" are kept apart on purpose: one is a decision
 * somebody made and can undo here, the other is a date that passed. The server
 * tells them apart too — see the state it sends — and a screen that collapsed
 * them would leave "why can I not reopen this" unanswered.
 */
function StateBadge({ state }: { state: FormSummary['state'] }) {
    const { t } = useTranslate();

    if (state === 'open') {
        return (
            <span className="rounded border border-emerald-500/40 px-2 py-0.5 text-xs text-emerald-700 dark:text-emerald-400">
                {t('forms.screen.open')}
            </span>
        );
    }

    return (
        <span className="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">
            {state === 'closed'
                ? t('forms.screen.closed')
                : t('forms.card.expired')}
        </span>
    );
}

function FormRow({
    form,
    workspaceSlug,
    onDelete,
}: {
    form: FormSummary;
    workspaceSlug: string;
    onDelete: () => void;
}) {
    const { t, tChoice } = useTranslate();

    return (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border p-4">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    {/*
                     * The title is the way in for anybody who may edit, and
                     * plain text for anybody who may not — a link that answers
                     * 403 is worse than no link.
                     */}
                    {form.canEdit ? (
                        <Link
                            href={edit.url({
                                workspace: workspaceSlug,
                                form: form.id,
                            })}
                            className="truncate font-medium hover:underline"
                        >
                            {form.title}
                        </Link>
                    ) : (
                        <span className="truncate font-medium">
                            {form.title}
                        </span>
                    )}

                    <StateBadge state={form.state} />

                    {form.isShared && (
                        <span className="rounded border border-amber-500/40 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-400">
                            {t('forms.screen.shared')}
                        </span>
                    )}
                </div>

                {form.description !== null && form.description !== '' && (
                    <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                        {form.description}
                    </p>
                )}

                <p className="mt-1 text-xs text-muted-foreground">
                    {[
                        form.author,
                        `${t('forms.screen.fields')}: ${form.fields}`,
                        tChoice('forms.answers_screen.count', form.submissions),
                    ]
                        .filter((part) => part !== null && part !== '')
                        .join(' · ')}
                </p>
            </div>

            {form.canViewAnswers && (
                <Link
                    href={answersRoute.url({
                        workspace: workspaceSlug,
                        form: form.id,
                    })}
                    className={cn(
                        buttonVariants({ variant: 'ghost', size: 'sm' }),
                    )}
                >
                    <ListChecks className="size-4" />
                    {t('forms.screen.answers')}
                </Link>
            )}

            {form.canEdit && (
                <>
                    <Link
                        href={edit.url({
                            workspace: workspaceSlug,
                            form: form.id,
                        })}
                        className={cn(
                            buttonVariants({ variant: 'outline', size: 'sm' }),
                        )}
                    >
                        {t('forms.screen.edit')}
                    </Link>

                    <button
                        type="button"
                        onClick={onDelete}
                        aria-label={t('forms.screen.delete')}
                        className="text-muted-foreground transition-colors hover:text-destructive"
                    >
                        <Trash2 className="size-4" />
                    </button>
                </>
            )}
        </div>
    );
}

/**
 * Every form in the workspace, and the one box that makes another.
 *
 * Inside the chat shell rather than under settings, the same way the secrets
 * and transfers lists are: writing a form is work somebody does in the middle
 * of their day, next to the conversation it will be announced in.
 *
 * A new form is a title and nothing else: the server sends it straight into the
 * builder, because a form with no questions is never where somebody wanted to
 * end up.
 */
export default function Forms({
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
    forms,
}: FormsProps) {
    useSessionGuard();

    const { t } = useTranslate();

    const [searchOpen, setSearchOpen] = useState(false);
    useCommandPaletteShortcut(setSearchOpen);

    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

    const [title, setTitle] = useState('');
    const [deleting, setDeleting] = useState<FormSummary | null>(null);

    const userMenu = <UserMenu />;

    return (
        <div className="flex h-dvh overflow-hidden bg-background">
            <Head title={t('forms.screen.title')} />

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
                    <ChannelMenuButton />
                    <ClipboardList className="size-4 text-muted-foreground" />
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            {t('forms.screen.title')}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            {t('forms.screen.description')}
                        </p>
                    </div>
                </header>

                <div className="flex-1 overflow-y-auto">
                    <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                        <div className="space-y-2">
                            {forms.map((form) => (
                                <FormRow
                                    key={form.id}
                                    form={form}
                                    workspaceSlug={workspace.slug}
                                    onDelete={() => setDeleting(form)}
                                />
                            ))}

                            {forms.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    {t('forms.screen.none')}
                                </p>
                            )}
                        </div>

                        <div className="space-y-3 rounded-lg border p-4">
                            <Label htmlFor="new-form">
                                {t('forms.screen.new')}
                            </Label>

                            <div className="flex flex-wrap items-center gap-2">
                                <Input
                                    id="new-form"
                                    value={title}
                                    onChange={(event) =>
                                        setTitle(event.target.value)
                                    }
                                    placeholder={t('forms.screen.form_title')}
                                    maxLength={80}
                                    className="max-w-xs"
                                />

                                <Button
                                    disabled={title.trim() === ''}
                                    onClick={() =>
                                        router.post(store.url(workspace.slug), {
                                            title,
                                        })
                                    }
                                >
                                    <Plus className="size-4" />
                                    {t('forms.screen.new')}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <AlertDialog
                open={deleting !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setDeleting(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {deleting?.title ?? t('forms.screen.delete')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('forms.screen.delete_confirm')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('settings.actions.cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => {
                                if (deleting) {
                                    router.delete(
                                        destroy.url({
                                            workspace: workspace.slug,
                                            form: deleting.id,
                                        }),
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            {t('forms.screen.delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

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
