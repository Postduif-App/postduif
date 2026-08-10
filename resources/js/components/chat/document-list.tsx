import { FileText, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import type { DocumentSummary } from '@/types/chat';

interface DocumentListProps {
    documents: DocumentSummary[];
    /** The document open beside this list, so its row can be marked as such. */
    activeNumber: number | null;
    canCreate: boolean;
    onOpen: (number: number) => void;
    onCreate: () => void;
}

/**
 * The documents a channel keeps, most recently worked on first.
 *
 * A list rather than a sidebar of them: a channel has a handful of documents,
 * not a tree, and anything that looks like a file browser invites people to
 * build one.
 */
export function DocumentList({
    documents,
    activeNumber,
    canCreate,
    onOpen,
    onCreate,
}: DocumentListProps) {
    const formats = useFormats();
    const { t } = useTranslate();

    return (
        <div className="flex h-full flex-col overflow-y-auto">
            <div className="flex items-center justify-between gap-3 border-b px-4 py-3">
                <h2 className="text-sm font-semibold">
                    {t('documents.list.title')}
                </h2>

                {canCreate && (
                    <Button size="sm" onClick={onCreate}>
                        <Plus className="size-4" />
                        {t('documents.list.create')}
                    </Button>
                )}
            </div>

            {documents.length === 0 ? (
                <DocumentListEmpty canCreate={canCreate} onCreate={onCreate} />
            ) : (
                <ul className="divide-y">
                    {documents.map((row) => (
                        <li key={row.id}>
                            <button
                                type="button"
                                onClick={() => onOpen(row.number)}
                                aria-current={activeNumber === row.number}
                                className={cn(
                                    'flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/60',
                                    activeNumber === row.number && 'bg-muted',
                                )}
                            >
                                <FileText className="mt-0.5 size-4 shrink-0 text-muted-foreground" />

                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">
                                        {row.title}
                                    </span>

                                    {/*
                                        Only when there is something to show. An
                                        empty line under every freshly started
                                        document makes the list look broken
                                        rather than new.
                                    */}
                                    {row.excerpt !== '' && (
                                        <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                                            {row.excerpt}
                                        </span>
                                    )}

                                    <span className="mt-1 block text-xs text-muted-foreground">
                                        {row.updatedAt !== null &&
                                            t('documents.list.updated', {
                                                when: formats.moment.format(
                                                    new Date(row.updatedAt),
                                                ),
                                                who:
                                                    row.updatedBy ??
                                                    t(
                                                        'documents.list.somebody',
                                                    ),
                                            })}
                                    </span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * What a channel that has just switched documents on looks like.
 *
 * Worth building rather than leaving blank: this is the state every channel
 * starts in, so it is the screen that has to explain what a document is for.
 */
function DocumentListEmpty({
    canCreate,
    onCreate,
}: {
    canCreate: boolean;
    onCreate: () => void;
}) {
    const { t } = useTranslate();

    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-12 text-center">
            <FileText className="size-8 text-muted-foreground/60" />

            <p className="max-w-sm text-sm text-muted-foreground">
                {t('documents.list.empty')}
            </p>

            {canCreate && (
                <Button size="sm" variant="outline" onClick={onCreate}>
                    <Plus className="size-4" />
                    {t('documents.list.create')}
                </Button>
            )}
        </div>
    );
}
