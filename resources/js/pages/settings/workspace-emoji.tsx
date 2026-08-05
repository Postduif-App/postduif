import { Form, Head, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { destroy, store } from '@/routes/workspace/emoji';

interface WorkspaceEmoji {
    id: number;
    name: string;
    /** With the colons, which is what somebody has to type. */
    shortcode: string;
    url: string;
    /** Null once whoever uploaded it has left the workspace. */
    author: string | null;
    createdAt: string | null;
}

interface WorkspaceEmojiProps {
    emoji: WorkspaceEmoji[];
    /** What the endpoint stops at, so the screen can say so before it refuses. */
    maxEmoji: number;
    workspace: string;
}

export default function WorkspaceEmojiPage({
    emoji,
    maxEmoji,
    workspace,
}: WorkspaceEmojiProps) {
    const { t, tChoice } = useTranslate();
    const [removing, setRemoving] = useState<WorkspaceEmoji | null>(null);

    const full = emoji.length >= maxEmoji;

    return (
        <>
            <Head title={t('workspace_emoji.title')} />

            <div className="space-y-8">
                <Heading
                    title={t('workspace_emoji.title')}
                    description={t('workspace_emoji.description', {
                        workspace,
                    })}
                />

                <p className="max-w-[68ch] text-sm text-muted-foreground">
                    {t('workspace_emoji.explanation')}
                </p>

                {/*
                    The form resets itself on success rather than holding the
                    name in state: an emoji is added and then forgotten about,
                    and a field still holding "shipit" invites a second upload
                    of the same thing under a name the endpoint will refuse.
                */}
                <Form
                    {...store.form()}
                    resetOnSuccess
                    options={{ preserveScroll: true }}
                    className="space-y-4 rounded-lg border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="emoji-name">
                                        {t('workspace_emoji.name')}
                                    </Label>
                                    <Input
                                        id="emoji-name"
                                        name="name"
                                        maxLength={30}
                                        placeholder={t(
                                            'workspace_emoji.name_placeholder',
                                        )}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {t('workspace_emoji.name_hint')}
                                    </p>
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="emoji-image">
                                        {t('workspace_emoji.image')}
                                    </Label>
                                    <Input
                                        id="emoji-image"
                                        name="image"
                                        type="file"
                                        // A hint for the file dialog only; the
                                        // endpoint decides on the bytes.
                                        accept="image/png,image/jpeg,image/gif,image/webp"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {t('workspace_emoji.image_hint')}
                                    </p>
                                    <InputError message={errors.image} />
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <Button
                                    type="submit"
                                    disabled={processing || full}
                                >
                                    {processing ? (
                                        <Spinner />
                                    ) : (
                                        <Plus className="size-4" />
                                    )}
                                    {t(
                                        processing
                                            ? 'workspace_emoji.uploading'
                                            : 'workspace_emoji.upload',
                                    )}
                                </Button>

                                {full && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('workspace_emoji.too_many', {
                                            count: maxEmoji,
                                        })}
                                    </p>
                                )}
                            </div>
                        </>
                    )}
                </Form>

                {emoji.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('workspace_emoji.empty')}
                    </p>
                ) : (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            {tChoice('workspace_emoji.count', emoji.length, {
                                count: emoji.length,
                            })}
                        </p>

                        <ul className="grid gap-2 sm:grid-cols-2">
                            {emoji.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="flex items-center gap-3 rounded-lg border p-3"
                                >
                                    {/*
                                        Drawn at the size it is used at, not
                                        blown up: this row is meant to answer
                                        "is this the one I mean?", and an emoji
                                        shown four times life size answers a
                                        question nobody asked.
                                    */}
                                    <img
                                        src={entry.url}
                                        alt={entry.shortcode}
                                        className="size-8 shrink-0 object-contain"
                                    />

                                    <div className="min-w-0 flex-1">
                                        <code className="font-mono text-sm">
                                            {entry.shortcode}
                                        </code>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {entry.author === null
                                                ? t(
                                                      'workspace_emoji.added_by_unknown',
                                                  )
                                                : t(
                                                      'workspace_emoji.added_by',
                                                      {
                                                          name: entry.author,
                                                      },
                                                  )}
                                        </p>
                                    </div>

                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t(
                                            'workspace_emoji.delete_question',
                                            { name: entry.shortcode },
                                        )}
                                        onClick={() => setRemoving(entry)}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            <AlertDialog
                open={removing !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setRemoving(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {t('workspace_emoji.delete_question', {
                                name: removing?.shortcode ?? '',
                            })}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('workspace_emoji.delete_explanation')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('workspace_emoji.cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => {
                                if (removing) {
                                    router.delete(destroy.url(removing.id), {
                                        preserveScroll: true,
                                    });
                                }
                            }}
                        >
                            {t('workspace_emoji.delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
