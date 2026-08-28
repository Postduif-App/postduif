import { Form } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { update as updateHandle } from '@/routes/workspace/members/handle';

interface HandleHolder {
    id: number;
    name: string;
    username: string;
}

interface MemberHandleDialogProps {
    /** The member being renamed, or null when the dialog is closed. */
    member: HandleHolder | null;
    onOpenChange: (open: boolean) => void;
}

/**
 * Somebody's handle, as one field you overwrite.
 *
 * A dialog rather than an editable cell in the table, because this is the one
 * thing on the row that reaches outside the workspace: a handle belongs to the
 * account, so changing it changes how that person is addressed everywhere they
 * are. That is worth a sentence and a deliberate save.
 */
export function MemberHandleDialog({
    member,
    onOpenChange,
}: MemberHandleDialogProps) {
    const { t } = useTranslate();

    return (
        <Dialog open={member !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('components.member_handle.title', {
                            name: member?.name ?? '',
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('components.member_handle.description', {
                            name: member?.name ?? '',
                        })}
                    </DialogDescription>
                </DialogHeader>

                {member && (
                    <MemberHandleForm
                        // One dialog serves every row, so the field has to start
                        // over per member. A key does that on mount rather than
                        // by resetting state after the fact.
                        key={member.id}
                        member={member}
                        onOpenChange={onOpenChange}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function MemberHandleForm({
    member,
    onOpenChange,
}: MemberHandleDialogProps & { member: HandleHolder }) {
    const { t } = useTranslate();

    return (
        <Form
            {...updateHandle.form(member.id)}
            options={{ preserveScroll: true }}
            onSuccess={() => onOpenChange(false)}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="member-handle">
                            {t('components.member_handle.label')}
                        </Label>
                        {/*
                            The "@" sits beside the field rather than in it: it
                            is not part of what gets stored, and a value that
                            starts with one would be refused for a character
                            the interface put there.
                        */}
                        <div className="flex items-center gap-2">
                            <span
                                aria-hidden="true"
                                className="text-sm text-muted-foreground"
                            >
                                @
                            </span>
                            <Input
                                id="member-handle"
                                name="username"
                                defaultValue={member.username}
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                maxLength={30}
                                required
                            />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {t('components.member_handle.hint')}
                        </p>
                        <InputError message={errors.username} />
                    </div>

                    <p className="rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">
                        {t('components.member_handle.warning', {
                            handle: member.username,
                        })}
                    </p>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('settings.actions.cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {t('components.member_handle.save')}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
