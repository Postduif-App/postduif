import { router } from '@inertiajs/react';
import { Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/hooks/use-initials';
import { destroy, store } from '@/routes/avatar';

/**
 * Setting your own face.
 *
 * Uploaded on choosing rather than behind a save button: there is one field and
 * one decision, and a form around a single file is a step that adds nothing.
 * The picture is squared and shrunk on the server, so what gets stored is what
 * gets shown — see StoreAvatar.
 */
export function AvatarField({
    name,
    avatarUrl,
    uploadUrl,
    removeUrl,
    hint = 'png, jpg, gif of webp, tot 2 MB. Wordt bijgesneden tot een vierkant.',
}: {
    name: string;
    avatarUrl: string | null;
    /** Defaults to the signed-in member's own; a workspace passes its own. */
    uploadUrl?: string;
    removeUrl?: string;
    hint?: string;
}) {
    const getInitials = useInitials();
    const inputRef = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);

    return (
        <div className="flex items-center gap-4">
            <Avatar className="size-16">
                {avatarUrl && <AvatarImage src={avatarUrl} alt="" />}
                <AvatarFallback className="text-lg font-semibold">
                    {getInitials(name)}
                </AvatarFallback>
            </Avatar>

            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <input
                        ref={inputRef}
                        type="file"
                        accept="image/png,image/jpeg,image/gif,image/webp"
                        className="hidden"
                        onChange={(event) => {
                            const file = event.target.files?.[0];

                            // Cleared straight away, or picking the same file
                            // twice in a row fires no change the second time.
                            event.target.value = '';

                            if (!file) {
                                return;
                            }

                            setBusy(true);
                            router.post(
                                uploadUrl ?? store.url(),
                                { avatar: file },
                                {
                                    preserveScroll: true,
                                    onFinish: () => setBusy(false),
                                },
                            );
                        }}
                    />

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={busy}
                        onClick={() => inputRef.current?.click()}
                    >
                        {busy ? <Spinner /> : <Upload className="size-4" />}
                        {avatarUrl ? 'Andere foto' : 'Foto kiezen'}
                    </Button>

                    {avatarUrl && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={busy}
                            onClick={() => {
                                setBusy(true);
                                router.delete(removeUrl ?? destroy.url(), {
                                    preserveScroll: true,
                                    onFinish: () => setBusy(false),
                                });
                            }}
                        >
                            <Trash2 className="size-4" />
                            Verwijderen
                        </Button>
                    )}
                </div>

                <p className="text-xs text-muted-foreground">{hint}</p>
            </div>
        </div>
    );
}
