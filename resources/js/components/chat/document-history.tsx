import { router } from '@inertiajs/react';
import { History, RotateCcw } from 'lucide-react';
import { useCallback, useState } from 'react';

import { DocumentEditor } from '@/components/chat/document-editor';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import {
    index as revisionsIndex,
    restore,
    show as revisionShow,
} from '@/routes/chat/documents/revisions';
import type { DocumentContent } from '@/types/chat';

interface Revision {
    id: number;
    author: string | null;
    createdAt: string | null;
    excerpt: string;
}

interface DocumentHistoryProps {
    /** The three parts of the address, as the document view already has them. */
    target: { workspace: string; channel: number; document: number };
}

/**
 * What this document said before, and what it looked like.
 *
 * Two panes rather than one, because a date and a first line are not enough to
 * choose by: the versions from one afternoon all start with the same sentence,
 * and the one worth having is told apart by what is further down. Restoring
 * without being able to look first is a guess, and the whole point of this
 * panel is to be trustworthy in a moment when something has already gone wrong.
 *
 * Both halves are fetched rather than carried in the page props: a history is a
 * stack of full copies of the document, and shipping them with every visit to a
 * channel would make everybody pay for a panel almost nobody opens. The list
 * carries a line each; the body arrives only for the version somebody clicks.
 */
export function DocumentHistory({ target }: DocumentHistoryProps) {
    const { t } = useTranslate();
    const formats = useFormats();

    const [open, setOpen] = useState(false);
    const [revisions, setRevisions] = useState<Revision[] | null>(null);
    const [failed, setFailed] = useState(false);

    const [chosen, setChosen] = useState<number | null>(null);
    const [preview, setPreview] = useState<DocumentContent | null>(null);

    const choose = useCallback(
        async (id: number) => {
            setChosen(id);
            setPreview(null);

            try {
                const response = await fetch(
                    revisionShow.url({ ...target, revision: id }),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );

                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                const body = (await response.json()) as {
                    body: DocumentContent;
                };

                setPreview(body.body);
            } catch {
                setFailed(true);
            }
        },
        [target],
    );

    /*
     * Fetched from the click rather than from an effect on `open`.
     *
     * An effect would set state during the render that opened the panel, which
     * is a cascading render and which the lint rule is right about. The click
     * is also simply where this belongs: opening the panel is the request.
     */
    const load = useCallback(async () => {
        setRevisions(null);
        setChosen(null);
        setPreview(null);
        setFailed(false);

        try {
            const response = await fetch(revisionsIndex.url(target), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const body = (await response.json()) as { revisions: Revision[] };

            setRevisions(body.revisions);

            // Straight into the newest, which is what somebody came for nine
            // times in ten. An empty pane beside a list is a second click for
            // nothing.
            if (body.revisions.length > 0) {
                void choose(body.revisions[0].id);
            }
        } catch {
            setFailed(true);
        }
    }, [target, choose]);

    return (
        <>
            <Button
                variant="ghost"
                size="icon"
                className="size-7"
                aria-label={t('documents.history.label')}
                title={t('documents.history.label')}
                onClick={() => {
                    setOpen(true);
                    void load();
                }}
            >
                <History className="size-3.5" />
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[85vh] sm:max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>
                            {t('documents.history.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('documents.history.description')}
                        </DialogDescription>
                    </DialogHeader>

                    {failed && (
                        <p className="py-6 text-center text-sm text-destructive">
                            {t('documents.history.failed')}
                        </p>
                    )}

                    {!failed && revisions === null && (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            {t('documents.history.loading')}
                        </p>
                    )}

                    {revisions !== null && revisions.length === 0 && (
                        /*
                         * The ordinary state of a document nobody has rewritten
                         * yet, so it is worded as a fact rather than as an
                         * error: there is nothing wrong, there is simply
                         * nothing behind it.
                         */
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            {t('documents.history.empty')}
                        </p>
                    )}

                    {revisions !== null && revisions.length > 0 && (
                        <div className="grid gap-4 sm:grid-cols-[16rem_1fr]">
                            <ul className="max-h-[50vh] overflow-y-auto border-border sm:border-e sm:pe-2">
                                {revisions.map((revision) => (
                                    <li key={revision.id}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                void choose(revision.id)
                                            }
                                            aria-current={
                                                chosen === revision.id
                                            }
                                            className={cn(
                                                'w-full rounded-md px-2 py-2 text-start hover:bg-muted/60',
                                                chosen === revision.id &&
                                                    'bg-muted',
                                            )}
                                        >
                                            <span className="block text-sm font-medium">
                                                {revision.createdAt === null
                                                    ? '—'
                                                    : formats.moment.format(
                                                          new Date(
                                                              revision.createdAt,
                                                          ),
                                                      )}
                                            </span>
                                            <span className="block truncate text-sm text-muted-foreground">
                                                {revision.author ??
                                                    t(
                                                        'documents.history.somebody',
                                                    )}
                                            </span>
                                            {/*
                                                The first line as well, because
                                                a list of "Sebastiaan, 13:44"
                                                repeated forty times is not a
                                                list anybody can scan. The
                                                preview settles it; this is what
                                                makes the row worth clicking.
                                            */}
                                            {revision.excerpt !== '' && (
                                                <span className="block truncate text-xs text-muted-foreground/70">
                                                    {revision.excerpt}
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                ))}
                            </ul>

                            <div className="flex min-w-0 flex-col gap-3">
                                <div className="max-h-[50vh] overflow-y-auto rounded-md border border-border p-3">
                                    {preview === null ? (
                                        <p className="py-6 text-center text-sm text-muted-foreground">
                                            {t('documents.history.loading')}
                                        </p>
                                    ) : (
                                        /*
                                         * The real editor, read-only, so a
                                         * version is shown exactly as it was
                                         * written — headings as headings, the
                                         * table as a table. Keyed by the
                                         * revision, because the editor reads
                                         * its value once and owns it from then
                                         * on; without the key, clicking a
                                         * second version would leave the first
                                         * one on screen.
                                         *
                                         * Code blocks come up uncoloured here:
                                         * decorations do not run in read-only
                                         * mode. The words are all there, which
                                         * is what this pane is for.
                                         */
                                        <DocumentEditor
                                            key={chosen}
                                            value={preview}
                                            readOnly
                                            uploadEndpoint={null}
                                            onChange={() => {}}
                                        />
                                    )}
                                </div>

                                <div className="flex justify-end">
                                    <Button
                                        variant="destructive"
                                        disabled={chosen === null}
                                        onClick={() => {
                                            if (chosen === null) {
                                                return;
                                            }

                                            router.post(
                                                restore.url({
                                                    ...target,
                                                    revision: chosen,
                                                }),
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    /*
                                                     * The page reloaded, not
                                                     * revisited. The editor
                                                     * reads its value once and
                                                     * owns it from then on —
                                                     * that is what keeps a
                                                     * caret from jumping while
                                                     * somebody types — so new
                                                     * props alone change
                                                     * nothing on screen, and
                                                     * the restored text would
                                                     * sit in the database while
                                                     * the old text stayed in
                                                     * front of the writer,
                                                     * ready for autosave to put
                                                     * it straight back.
                                                     */
                                                    onSuccess: () =>
                                                        globalThis.location.reload(),
                                                },
                                            );
                                        }}
                                    >
                                        <RotateCcw className="size-3.5" />
                                        {t('documents.history.restore')}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
