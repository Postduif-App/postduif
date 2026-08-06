import { router } from '@inertiajs/react';
import {
    Maximize2,
    Minimize2,
    Pencil,
    Pin,
    PinOff,
    Trash2,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';

import { Composer } from '@/components/chat/composer';
import { ReactionEmoji } from '@/components/chat/custom-emoji';
import { ReactionPicker } from '@/components/chat/reaction-picker';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFormats } from '@/hooks/use-formats';
import { useInitials } from '@/hooks/use-initials';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { destroy, update } from '@/routes/chat/board';
import {
    destroy as destroyComment,
    store as storeComment,
    update as updateComment,
} from '@/routes/chat/board/comments';
import { store as storeReaction } from '@/routes/chat/board/reactions';
import type {
    BoardComment,
    BoardReaction,
    ChatWorkspace,
    OpenBoardPost,
} from '@/types/chat';

function moment(
    value: string | null,
    // Handed in rather than looked up: this is a plain function, and a hook
    // cannot be called from one.
    dateTime: Intl.DateTimeFormat,
): string {
    return value === null ? '' : dateTime.format(new Date(value));
}

/**
 * The notice being read, beside the board it came from.
 *
 * A panel rather than a page of its own, the same shape the ticket and thread
 * panels take: the list stays visible next to it, so reading one notice does not
 * cost you your place on the board. What is open travels in the query string,
 * which is what makes a single notice something you can send to a colleague.
 */
export function BoardPanel({
    workspace,
    post,
    fullscreen = false,
    onToggleFullscreen,
    onClose,
}: {
    workspace: ChatWorkspace;
    post: OpenBoardPost;
    /**
     * Whether the notice has the screen to itself. Only the width changes with
     * it: one notice rendered two different ways is two things to keep in step,
     * and the whole reason the panel is one component is that it is one notice.
     */
    fullscreen?: boolean;
    onToggleFullscreen?: () => void;
    onClose: () => void;
}) {
    const getInitials = useInitials();
    const formats = useFormats();
    const { t, tChoice } = useTranslate();

    const [editing, setEditing] = useState(false);
    const [title, setTitle] = useState(post.title);
    const [body, setBody] = useState(post.body);
    const [sending, setSending] = useState(false);

    const target = { workspace: workspace.slug, board_post: post.id };

    /*
        Every write below is preserveScroll + preserveState so the page comes
        back where it was. The panel reads what the server sends rather than
        keeping its own copy of the notice: an optimistic board is one where a
        refused edit stays on screen looking like it worked.
    */
    const visit = { preserveScroll: true, preserveState: true };

    const submitEdit = () => {
        if (title.trim() === '' || body.trim() === '') {
            return;
        }

        router.patch(
            update.url(target),
            { title, body },
            { ...visit, onSuccess: () => setEditing(false) },
        );
    };

    /**
     * De reactie wegsturen.
     *
     * De Composer houdt de tekst zelf vast en maakt zichzelf leeg, dus hier is
     * geen veld meer om te wissen — en ook geen lege-tekstcontrole, want een
     * composer met niets erin laat de verstuurknop niet toe.
     */
    const submitReply = (text: string) => {
        if (sending) {
            return;
        }

        setSending(true);

        router.post(
            storeComment.url(target),
            // De stand gaat mee, want dit antwoord bepaalt de URL waar de
            // browser op landt — zie de redirect in BoardCommentController.
            { body: text, full: fullscreen ? 1 : undefined },
            { ...visit, onFinish: () => setSending(false) },
        );
    };

    /**
     * De leeskolom. Op het hele scherm loopt tekst anders van rand tot rand, en
     * een regel van tweeduizend pixels raak je halverwege kwijt — dus breder dan
     * de panelversie, maar niet breder dan een oog in één beweging volgt.
     */
    const column = fullscreen ? 'mx-auto w-full max-w-3xl' : '';

    return (
        <aside
            className={cn(
                'flex flex-col',
                fullscreen
                    ? 'min-w-0 flex-1'
                    : /*
                       * Beside the board on a wide screen; over it on one too
                       * narrow to hold both — the same move the thread and
                       * ticket panels make, anchored at the rail so the way
                       * back to the channel list stays reachable.
                       */
                      'fixed inset-y-0 right-0 left-14 z-30 border-l bg-background lg:static lg:left-auto lg:w-[28rem] lg:shrink-0',
            )}
        >
            <header className="flex h-14 shrink-0 items-center gap-2 border-b px-4">
                <h2
                    className={cn(
                        'min-w-0 flex-1 truncate font-semibold',
                        // Op het hele scherm is de titel de kop van wat je leest
                        // in plaats van het label van een paneel ernaast.
                        fullscreen ? 'text-base' : 'text-sm',
                    )}
                >
                    {editing ? t('chat_ui.board.editing') : post.title}
                </h2>

                {post.canPin && !editing && (
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={
                            post.pinned
                                ? t('chat_ui.board.unpin')
                                : t('chat_ui.board.pin')
                        }
                        title={
                            post.pinned
                                ? t('chat_ui.board.unpin')
                                : t('chat_ui.board.pin')
                        }
                        onClick={() =>
                            router.patch(
                                update.url(target),
                                { pinned: !post.pinned },
                                visit,
                            )
                        }
                    >
                        {post.pinned ? (
                            <PinOff className="size-4" />
                        ) : (
                            <Pin className="size-4" />
                        )}
                    </Button>
                )}

                {post.canEdit && !editing && (
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('chat_ui.board.edit')}
                        onClick={() => {
                            setTitle(post.title);
                            setBody(post.body);
                            setEditing(true);
                        }}
                    >
                        <Pencil className="size-4" />
                    </Button>
                )}

                {post.canDelete && !editing && (
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('chat_ui.board.delete')}
                        onClick={() => {
                            if (
                                window.confirm(
                                    t('chat_ui.board.delete_confirm'),
                                )
                            ) {
                                router.delete(destroy.url(target));
                            }
                        }}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                )}

                {onToggleFullscreen && !editing && (
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={
                            fullscreen
                                ? t('chat_ui.board.back')
                                : t('chat_ui.board.fullscreen')
                        }
                        title={
                            fullscreen
                                ? t('chat_ui.board.back')
                                : t('chat_ui.board.fullscreen')
                        }
                        onClick={onToggleFullscreen}
                    >
                        {fullscreen ? (
                            <Minimize2 className="size-4" />
                        ) : (
                            <Maximize2 className="size-4" />
                        )}
                    </Button>
                )}

                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('chat_ui.board.close')}
                    onClick={onClose}
                >
                    <X className="size-4" />
                </Button>
            </header>

            <div className="flex-1 overflow-y-auto">
                {editing ? (
                    <div className={cn('space-y-3 border-b p-4', column)}>
                        <Input
                            value={title}
                            maxLength={120}
                            aria-label={t('dialogs.board_post.title_label')}
                            onChange={(event) => setTitle(event.target.value)}
                        />
                        <textarea
                            value={body}
                            maxLength={8000}
                            aria-label={t('dialogs.board_post.body_label')}
                            rows={10}
                            className="w-full resize-y rounded-md border bg-background p-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                            onChange={(event) => setBody(event.target.value)}
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setEditing(false)}
                            >
                                {t('panelen.cancel')}
                            </Button>
                            <Button size="sm" onClick={submitEdit}>
                                {t('panelen.save')}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <article className={cn('border-b p-4', column)}>
                        <div className="flex items-center gap-2">
                            <Person person={post.author} />
                            <span className="text-xs text-muted-foreground">
                                {moment(post.createdAt, formats.dateTime)}
                                {/*
                                    Said out loud rather than hidden: a board is
                                    read by people who were not there when the
                                    notice went up, and one whose text can change
                                    silently is one nobody can quote back.
                                */}
                                {post.editedAt &&
                                    ` · ${t('chat_ui.board.edited')}`}
                            </span>
                        </div>

                        <p className="mt-3 text-sm whitespace-pre-wrap">
                            {post.body}
                        </p>

                        {/*
                            Onder de mededeling en boven de reacties, want dat is
                            wat het is: een antwoord op wat er hangt, korter dan
                            een reactie. Wie niets mag laat de rij staan maar niet
                            klikken — de tellingen zijn nog steeds nieuws.
                        */}
                        <BoardReactions
                            reactions={post.reactions}
                            onToggle={
                                post.canReact
                                    ? (emoji) =>
                                          router.post(
                                              storeReaction.url(target),
                                              { emoji },
                                              visit,
                                          )
                                    : undefined
                            }
                        />
                    </article>
                )}

                <div className={cn('p-4', column)}>
                    <h3 className="mb-3 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        {post.comments.length === 0
                            ? t('chat_ui.board.no_comments')
                            : tChoice(
                                  'chat_ui.board.comments',
                                  post.comments.length,
                              )}
                    </h3>

                    <ul className="space-y-4">
                        {post.comments.map((comment) => (
                            <Reply
                                key={comment.id}
                                comment={comment}
                                target={target}
                                getInitials={getInitials}
                                dateTime={formats.dateTime}
                            />
                        ))}
                    </ul>
                </div>
            </div>

            {post.canComment && (
                <div className="shrink-0 border-t p-3">
                    {/*
                        Hetzelfde veld als onderaan een kanaal, niet een veld
                        dat erop lijkt. Reageren is reageren, en een tweede
                        tekstvak met eigen toetsen en eigen opmaak is iets wat
                        je apart moet leren om vervolgens hetzelfde te doen.

                        Zonder triggers: een "@fenna" in een prikbordreactie
                        waarschuwt niemand, want alleen berichten worden op
                        vermeldingen gelezen. Een kiezer die anders suggereert
                        is erger dan geen kiezer. Zonder bijlagen om dezelfde
                        reden: het endpoint neemt alleen tekst aan.
                    */}
                    <div className={column}>
                        <Composer
                            placeholder={t('chat_ui.board.comment_placeholder')}
                            disabled={sending}
                            workspace={workspace}
                            triggers=""
                            // Per bericht, zodat een half getypte reactie op de
                            // ene mededeling niet opduikt onder de andere.
                            draftKey={`board:${post.id}`}
                            onSend={submitReply}
                        />
                    </div>
                </div>
            )}
        </aside>
    );
}

/**
 * De emoji onder een mededeling, met een kiezer om er een bij te zetten.
 *
 * Bewust niet MessageReactions hergebruikt, hoe erg de twee ook op elkaar
 * lijken. Die groepeert zelf en zoekt namen op in de ledenlijst van het kanaal;
 * een prikbord heeft geen ledenlijst, dus de server telt en benoemt hier. Eén
 * component die beide voedingen aankan zou twee componenten zijn met één naam.
 */
function BoardReactions({
    reactions,
    onToggle,
}: {
    reactions: BoardReaction[];
    /** Weggelaten waar reageren niet mag, wat ook de pillen uitzet. */
    onToggle?: (emoji: string) => void;
}) {
    // Niets om te tonen én niets te kiezen: dan is er geen rij.
    if (reactions.length === 0 && !onToggle) {
        return null;
    }

    return (
        <div className="mt-3 flex flex-wrap items-center gap-1">
            {reactions.map((reaction) => (
                <Tooltip key={reaction.emoji}>
                    <TooltipTrigger asChild>
                        <button
                            type="button"
                            disabled={!onToggle}
                            onClick={() => onToggle?.(reaction.emoji)}
                            className={cn(
                                'flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                reaction.mine
                                    ? 'border-primary/40 bg-primary/10'
                                    : 'bg-muted/60',
                                onToggle && 'hover:border-primary/40',
                            )}
                        >
                            <ReactionEmoji emoji={reaction.emoji} />
                            <span className="text-muted-foreground">
                                {reaction.count}
                            </span>
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>
                        {/*
                            De namen zoals de server ze stuurt, en niets erbij
                            geteld: wie inmiddels weg is staat er niet tussen,
                            dus de lijst mag korter zijn dan het getal ernaast.
                        */}
                        {reaction.names.join(', ')}
                    </TooltipContent>
                </Tooltip>
            ))}

            {onToggle && (
                <ReactionPicker
                    triggerClassName="flex size-6 items-center justify-center rounded-full border border-dashed text-muted-foreground transition-colors hover:border-primary/40 hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                    onSelect={onToggle}
                />
            )}
        </div>
    );
}

function Person({ person }: { person: { name: string } | null }) {
    const { t } = useTranslate();

    return (
        <span className="truncate text-sm font-medium">
            {/*
                The server sends null rather than a name once somebody has left,
                so the wording lives here where it can be read in context.
            */}
            {person?.name ?? t('chat_ui.board.author_gone')}
        </span>
    );
}

function Reply({
    comment,
    target,
    getInitials,
    dateTime,
}: {
    comment: BoardComment;
    target: { workspace: string; board_post: string };
    getInitials: (name: string) => string;
    dateTime: Intl.DateTimeFormat;
}) {
    const { t } = useTranslate();
    const [editing, setEditing] = useState(false);
    const [body, setBody] = useState(comment.body);
    const field = useRef<HTMLTextAreaElement>(null);

    const address = { ...target, comment: comment.id };

    return (
        <li className="flex gap-2">
            <Avatar className="size-7 shrink-0">
                {comment.author?.avatarUrl && (
                    <AvatarImage src={comment.author.avatarUrl} alt="" />
                )}
                <AvatarFallback className="text-[10px] font-semibold">
                    {getInitials(comment.author?.name ?? '?')}
                </AvatarFallback>
            </Avatar>

            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <Person person={comment.author} />
                    <span className="text-xs text-muted-foreground">
                        {moment(comment.createdAt, dateTime)}
                        {comment.editedAt && ` · ${t('chat_ui.board.edited')}`}
                    </span>

                    <span className="ml-auto flex shrink-0 items-center">
                        {comment.canEdit && !editing && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-6"
                                aria-label={t('chat_ui.board.comment_edit')}
                                onClick={() => {
                                    setBody(comment.body);
                                    setEditing(true);
                                    // After the field exists, not before.
                                    queueMicrotask(() =>
                                        field.current?.focus(),
                                    );
                                }}
                            >
                                <Pencil className="size-3" />
                            </Button>
                        )}
                        {comment.canDelete && !editing && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-6"
                                aria-label={t('chat_ui.board.comment_delete')}
                                onClick={() =>
                                    router.delete(destroyComment.url(address), {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                <Trash2 className="size-3" />
                            </Button>
                        )}
                    </span>
                </div>

                {editing ? (
                    <div className="mt-1 space-y-2">
                        <textarea
                            ref={field}
                            value={body}
                            rows={3}
                            maxLength={2000}
                            aria-label={t('chat_ui.board.comment_field')}
                            className="w-full resize-none rounded-md border bg-background p-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                            onChange={(event) => setBody(event.target.value)}
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setEditing(false)}
                            >
                                {t('panelen.cancel')}
                            </Button>
                            <Button
                                size="sm"
                                disabled={body.trim() === ''}
                                onClick={() =>
                                    router.patch(
                                        updateComment.url(address),
                                        { body },
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                            onSuccess: () => setEditing(false),
                                        },
                                    )
                                }
                            >
                                {t('panelen.save')}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <p className="mt-0.5 text-sm whitespace-pre-wrap">
                        {comment.body}
                    </p>
                )}
            </div>
        </li>
    );
}
