import { router } from '@inertiajs/react';

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
import { useTranslate } from '@/hooks/use-translate';
import { spokenDuration, useElapsed } from '@/lib/duration';
import { clockOut } from '@/routes/chat/timeclock';

/**
 * Asking whether somebody really means to stop the clock.
 *
 * Only on the way out, and that asymmetry is the point. Clocking in by accident
 * costs nothing — the stray shift is a row you delete, or never notice. Clocking
 * out by accident ends the afternoon you are in the middle of, and the way back
 * is a correction with times typed from memory.
 *
 * The elapsed time is in the question rather than only in the menu behind it:
 * "je staat 7u 12m ingeklokt" is what tells somebody whether this is the button
 * they meant, and it is the one fact they cannot see once the dialog covers the
 * screen.
 *
 * One component for both the user menu and the clock screen. The menu keeps
 * itself open behind it — see the item that mounts this — because the dialog
 * lives inside the dropdown and would unmount along with it.
 */
export function ClockOutDialog({
    runningSince,
    workspaceSlug,
    open,
    onOpenChange,
}: {
    /** When the shift began, so the question can say how long it has run. */
    runningSince: string;
    workspaceSlug: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslate();
    const elapsed = useElapsed(open ? runningSince : null);

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {t('timeclock.clock_out_question')}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {t('timeclock.clock_out_explanation', {
                            duration: spokenDuration(elapsed),
                        })}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>
                        {t('timeclock.clock_out_cancel')}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={() =>
                            router.post(
                                clockOut.url(workspaceSlug),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {t('timeclock.clock_out_confirm')}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
