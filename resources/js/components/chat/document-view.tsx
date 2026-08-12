import { router } from '@inertiajs/react';
import { ArrowLeft, Trash2 } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

import { DocumentEditor } from '@/components/chat/document-editor';
import { DocumentHistory } from '@/components/chat/document-history';
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
import { useTranslate } from '@/hooks/use-translate';
import { destroy, update } from '@/routes/chat/documents';
import { store as storeFile } from '@/routes/chat/documents/files';
import type {
    ActiveChannel,
    DocumentContent,
    ChatWorkspace,
    OpenDocument,
} from '@/types/chat';

/**
 * How long the document has to sit still before it is saved.
 *
 * Long enough that ordinary typing does not fire a request per word, short
 * enough that somebody who looks away mid-sentence and closes the laptop has
 * already been saved. The blur and unload handlers below cover the rest.
 */
const QUIET_MS = 800;

type SaveState = 'idle' | 'dirty' | 'saving' | 'saved' | 'conflict';

interface DocumentViewProps {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
    openDocument: OpenDocument;
    /**
     * Whether somebody else has saved this document since it was opened.
     *
     * A notice rather than a reload, which is the whole point: replacing the
     * value under a person who is typing would take their caret and their undo
     * history with it. See use-document-activity.
     */
    movedElsewhere: boolean;
    onDismissMoved: () => void;
    onClose: () => void;
}

export function DocumentView({
    workspace,
    channel,
    openDocument,
    movedElsewhere,
    onDismissMoved,
    onClose,
}: DocumentViewProps) {
    const { t } = useTranslate();

    const [title, setTitle] = useState(openDocument.title);
    const titleField = useRef<HTMLTextAreaElement>(null);
    const [state, setState] = useState<SaveState>('idle');
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [conflictMessage, setConflictMessage] = useState<string | null>(null);

    /*
     * Memoised because the save callback below depends on it, and a fresh
     * object literal every render would rebuild that callback every render —
     * which in turn would rebuild the debounce that is supposed to be counting
     * down through those renders.
     */
    const target = useMemo(
        () => ({
            workspace: workspace.slug,
            channel: channel.id,
            document: openDocument.number,
        }),
        [workspace.slug, channel.id, openDocument.number],
    );

    /*
     * Everything the save needs, kept in refs rather than in state.
     *
     * The document changes on every keystroke and none of those should cause a
     * render — the editor is already drawing the change, and re-rendering
     * around it is how a caret ends up jumping. The timer and the version have
     * to be readable from inside a callback that was created several keystrokes
     * ago, which is the other thing a ref is for.
     */
    const pending = useRef<{ body: DocumentContent; text: string } | null>(
        null,
    );
    const version = useRef(openDocument.version);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const inFlight = useRef(false);

    /*
     * Whether autosaving has been given up on, as a ref rather than read off
     * the state above.
     *
     * The callbacks below outlive the render that made them — a request started
     * three keystrokes ago finishes against a closure from three keystrokes
     * ago — and asking `state` there would be asking what was true when the
     * request left, not what is true now.
     */
    const conflicted = useRef(false);

    /*
     * The save function, reachable from inside itself.
     *
     * It needs to be: when a request finishes and something was typed while it
     * was in the air, that change has to be sent, and the only thing that can
     * send it is this same function. A direct call would be a cycle TypeScript
     * refuses and a useCallback pair would be two functions each holding a
     * stale copy of the other. One ref, rewritten on every render, is the
     * version of this that stays honest.
     */
    const saveRef = useRef<() => void>(() => {});

    const save = useCallback(() => {
        const pendingSave = pending.current;

        if (pendingSave === null || inFlight.current || conflicted.current) {
            return;
        }

        pending.current = null;
        inFlight.current = true;
        setState('saving');

        router.patch(
            update.url(target),
            /*
             * Cast because Inertia's payload type is narrower than what it
             * actually sends. FormDataConvertible describes the multipart case,
             * where a nested object genuinely has no encoding; this request
             * carries no files, so it goes out as JSON and the document travels
             * as the tree it is. Typing it away rather than flattening it: the
             * shape belongs to the editor, and anything that reshaped it here
             * would have to be undone on the way back in.
             */
            {
                version: version.current,
                body: pendingSave.body,
                body_text: pendingSave.text,
            } as unknown as Record<string, never>,
            {
                /*
                 * Both, and neither is optional here. Without preserveState the
                 * page — and the editor with it — is rebuilt under whoever is
                 * typing; without preserveScroll a long document jumps to the
                 * top every few seconds.
                 */
                preserveScroll: true,
                preserveState: true,
                /*
                 * No progress bar. This fires every few seconds of quiet while
                 * somebody writes, and a bar crawling across the top of the
                 * page on every pause turns saving — the thing that is supposed
                 * to be invisible — into the most noticeable thing on screen.
                 * The word beside the title says everything that needs saying.
                 */
                showProgress: false,
                /*
                 * Nothing on the page needs rebuilding after a save, and asking
                 * for nothing keeps the response small: the version we need
                 * next is simply the one we sent plus one.
                 */
                only: [],
                onSuccess: () => {
                    version.current += 1;
                    setState(pending.current === null ? 'saved' : 'dirty');
                },
                onError: (errors) => {
                    /*
                     * A version error is the conflict: somebody else saved
                     * while this document was open. Stop autosaving rather than
                     * retrying — every retry would carry the same stale version
                     * and fail identically, and the only way out is a reload
                     * the person has to choose.
                     */
                    if (errors.version !== undefined) {
                        conflicted.current = true;
                        setState('conflict');
                        setConflictMessage(errors.version);

                        return;
                    }

                    setState('dirty');
                },
                onFinish: () => {
                    inFlight.current = false;

                    /*
                     * Typed while the request was out. Without this the change
                     * sits in `pending` until the next keystroke happens to
                     * schedule another save — and if that keystroke was the
                     * last one, it never comes.
                     */
                    if (pending.current !== null && !conflicted.current) {
                        timer.current = setTimeout(
                            () => saveRef.current(),
                            QUIET_MS,
                        );
                    }
                },
            },
        );
    }, [target]);

    // Assigned in an effect rather than during render: writing to a ref while
    // rendering is a side effect, and React is allowed to render twice.
    useEffect(() => {
        saveRef.current = save;
    }, [save]);

    const schedule = useCallback(() => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => saveRef.current(), QUIET_MS);
    }, []);

    const onChange = useCallback(
        (body: DocumentContent, text: string) => {
            if (conflicted.current) {
                return;
            }

            pending.current = { body, text };
            setState('dirty');
            schedule();
        },
        [schedule],
    );

    /*
     * A save that is still waiting when the tab goes away.
     *
     * visibilitychange rather than beforeunload: it fires when a phone is
     * locked or the tab is switched, which is how most editing sessions
     * actually end, and beforeunload does not fire reliably on mobile at all.
     */
    useEffect(() => {
        const flush = () => {
            if (globalThis.document.visibilityState === 'hidden') {
                if (timer.current !== null) {
                    clearTimeout(timer.current);
                }

                save();
            }
        };

        globalThis.document.addEventListener('visibilitychange', flush);

        return () => {
            globalThis.document.removeEventListener('visibilitychange', flush);

            // Leaving the document is also a moment to stop waiting and write.
            if (timer.current !== null) {
                clearTimeout(timer.current);
                save();
            }
        };
    }, [save]);

    /*
     * Grow the title to fit what is in it.
     *
     * A textarea keeps whatever height it was given, so a name that wraps to a
     * second line would otherwise scroll inside a one-line box. Height is reset
     * to auto before it is read: scrollHeight never shrinks below the element's
     * current height, so without the reset the field could only ever get taller.
     *
     * Layout effect, so the height is right before the first paint — a title
     * that visibly snaps taller as the page appears is worse than one that was
     * simply always that tall.
     */
    useLayoutEffect(() => {
        const field = titleField.current;

        if (field === null) {
            return;
        }

        field.style.height = 'auto';
        field.style.height = `${field.scrollHeight}px`;
    }, [title]);

    const rename = () => {
        const trimmed = title.trim();

        if (trimmed === '' || trimmed === openDocument.title) {
            setTitle(openDocument.title);

            return;
        }

        router.patch(
            update.url(target),
            { version: version.current, title: trimmed },
            {
                preserveScroll: true,
                preserveState: true,
                // Same as the autosave: an inline edit, not a navigation.
                showProgress: false,
                onSuccess: () => {
                    version.current += 1;
                },
            },
        );
    };

    return (
        <div className="flex h-full flex-col overflow-hidden">
            {/*
                Somebody else saved while this was open, and nothing has gone
                wrong yet — this is still saveable, because the version only
                clashes once we try. Quieter than the conflict below, and
                dismissible: a reader who is only reading does not have to act
                on it.

                These two stay pinned above the document rather than scrolling
                with it. Everything else moved into the page, but a warning you
                can scroll away from is a warning that gets scrolled away from.
            */}
            {movedElsewhere && state !== 'conflict' && (
                <div className="flex items-center justify-between gap-3 border-b bg-muted/60 px-4 py-2 text-sm">
                    <span className="text-muted-foreground">
                        {t('documents.view.moved')}
                    </span>

                    <span className="flex shrink-0 items-center gap-1">
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={onDismissMoved}
                        >
                            {t('documents.view.dismiss')}
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => router.reload()}
                        >
                            {t('documents.view.reload')}
                        </Button>
                    </span>
                </div>
            )}

            {state === 'conflict' && conflictMessage !== null && (
                /*
                 * Loud, and it stays. Autosave has stopped, so anything typed
                 * from here on is going nowhere — which is the one thing
                 * somebody has to be told immediately rather than discover.
                 */
                <div
                    role="alert"
                    className="flex items-center justify-between gap-3 border-b border-amber-500/40 bg-amber-500/10 px-4 py-2 text-sm"
                >
                    <span>{conflictMessage}</span>

                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => router.reload()}
                    >
                        {t('documents.view.reload')}
                    </Button>
                </div>
            )}

            <div className="flex-1 overflow-y-auto px-6 py-5">
                <div className="mx-auto w-full max-w-3xl">
                    {/*
                        The way back and the two things you can do to the
                        document as a whole. Quiet, and part of the page rather
                        than a bar above it — a bar costs a strip of every
                        screen forever to hold three controls somebody reaches
                        for once a session.

                        It scrolls away with the rest, which is safe because the
                        Documenten tab in the channel header is always there and
                        goes to the same list this arrow does.
                    */}
                    <div className="mb-6 flex items-center gap-1 text-muted-foreground">
                        <button
                            type="button"
                            onClick={onClose}
                            className="-ml-1.5 flex items-center gap-1.5 rounded px-1.5 py-1 text-xs transition-colors hover:bg-muted hover:text-foreground"
                        >
                            <ArrowLeft className="size-3.5" />
                            {t('documents.view.back')}
                        </button>

                        <span className="flex-1" />

                        <SaveIndicator state={state} />

                        {/*
                            Beside the delete button, which is the other place
                            somebody looks when something has gone wrong — and
                            the one they reach for when this panel is what they
                            actually needed.
                        */}
                        {openDocument.canEdit && (
                            <DocumentHistory target={target} />
                        )}

                        {openDocument.canDelete && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                aria-label={t('documents.view.delete')}
                                onClick={() => setConfirmingDelete(true)}
                            >
                                <Trash2 className="size-3.5" />
                            </Button>
                        )}
                    </div>

                    {/*
                        The title, as the document's own first line.
                        
                        A textarea rather than an input, so a long name wraps
                        instead of scrolling sideways out of view — and grown to
                        fit its content, so it reads as a heading somebody typed
                        rather than as a field. Enter commits instead of adding a
                        line: this is one line by definition.
                    */}
                    {openDocument.canEdit ? (
                        <textarea
                            ref={titleField}
                            value={title}
                            rows={1}
                            onChange={(event) => setTitle(event.target.value)}
                            onBlur={rename}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    event.currentTarget.blur();
                                }
                            }}
                            aria-label={t('documents.view.title_label')}
                            placeholder={t('documents.view.untitled')}
                            className="mb-2 w-full resize-none overflow-hidden bg-transparent text-3xl leading-tight font-semibold tracking-tight outline-none placeholder:text-muted-foreground/40"
                        />
                    ) : (
                        <h1 className="mb-2 text-3xl leading-tight font-semibold tracking-tight">
                            {openDocument.title}
                        </h1>
                    )}

                    <DocumentEditor
                        /*
                         * Keyed by the document, so opening another document mounts a
                         * new editor rather than handing the old one a value it has
                         * already decided it owns.
                         */
                        key={openDocument.id}
                        value={openDocument.body}
                        readOnly={!openDocument.canEdit || state === 'conflict'}
                        /*
                         * Null for a reader, and for a writer whose document has
                         * been saved out from under them: the conflict banner is
                         * up, nothing they type is being kept, and a picture
                         * uploaded into that state would be a file on the disk
                         * that no saved document ever mentions.
                         */
                        uploadEndpoint={
                            openDocument.canEdit && state !== 'conflict'
                                ? storeFile.url(target)
                                : null
                        }
                        onChange={onChange}
                    />
                </div>
            </div>

            {/*
                Asked in the application's own words rather than in the
                browser's. A native confirm() is the one dialog that looks like
                it belongs to something else — and this is the most permanent
                button on the page: a document is months of writing that exists
                nowhere else, and the notice is worth reading rather than
                dismissing out of habit.
            */}
            <AlertDialog
                open={confirmingDelete}
                onOpenChange={setConfirmingDelete}
            >
                <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {t('documents.view.confirm_title')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('documents.view.confirm', {
                                title: openDocument.title,
                            })}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('documents.view.cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => router.delete(destroy.url(target))}
                        >
                            {t('documents.view.delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

/**
 * Whether the work is safe, in four words.
 *
 * Not decoration. With autosave there is no button to press and no moment that
 * feels like saving, so without this the honest answer to "is my work stored?"
 * would be "probably".
 */
function SaveIndicator({ state }: { state: SaveState }) {
    const { t } = useTranslate();

    if (state === 'idle' || state === 'conflict') {
        return null;
    }

    return (
        <span
            /*
             * Polite: it changes every few seconds while somebody types, and an
             * assertive region would interrupt a screen reader mid-word.
             */
            aria-live="polite"
            className="shrink-0 text-xs text-muted-foreground tabular-nums"
        >
            {t(
                state === 'saving'
                    ? 'documents.view.saving'
                    : state === 'saved'
                      ? 'documents.view.saved'
                      : 'documents.view.unsaved',
            )}
        </span>
    );
}
