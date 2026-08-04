import { router } from '@inertiajs/react';
import { useState } from 'react';

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
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { update } from '@/routes/status';
import type { Auth, Availability, User, UserStatus } from '@/types/auth';

/**
 * What most people are about to type anyway.
 *
 * Offered next to your own recent statuses rather than instead of them: the
 * recents are what you actually use, and these are what somebody sees on the
 * first day, when they have no recents at all.
 *
 * A function taking t rather than a constant: the words come from the lang
 * files, and a constant built when the module loads cannot call a hook to reach
 * them. The emoji stay here — a lunch is a lunch in either language.
 */
function suggestions(t: ReturnType<typeof useTranslate>['t']): UserStatus[] {
    return [
        { emoji: '📅', text: t('panelen.status.suggestion.meeting') },
        { emoji: '🍽️', text: t('panelen.status.suggestion.lunch') },
        { emoji: '🎧', text: t('panelen.status.suggestion.focus') },
        { emoji: '🏠', text: t('panelen.status.suggestion.home') },
        { emoji: '🚗', text: t('panelen.status.suggestion.commuting') },
        { emoji: '🤒', text: t('panelen.status.suggestion.sick') },
        { emoji: '🌴', text: t('panelen.status.suggestion.holiday') },
    ];
}

export function StatusDialog({
    user,
    availabilityOptions,
    open,
    onOpenChange,
}: {
    user: User;
    availabilityOptions: Auth['availabilityOptions'];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslate();
    const [emoji, setEmoji] = useState(user.status_emoji ?? '');
    const [text, setText] = useState(user.status_text ?? '');
    const [availability, setAvailability] = useState<Availability>(
        user.availability,
    );
    const [saving, setSaving] = useState(false);

    // Your own history first, then the defaults — minus anything already in the
    // history, so a status you use every day is not offered twice.
    const options = [
        ...user.recent_statuses,
        ...suggestions(t).filter(
            (suggestion) =>
                !user.recent_statuses.some(
                    (recent) => recent.text === suggestion.text,
                ),
        ),
    ];

    const save = (next?: { emoji: string; text: string }) => {
        setSaving(true);

        router.patch(
            update.url(),
            {
                status_emoji: next?.emoji ?? emoji,
                status_text: next?.text ?? text,
                availability,
            },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('panelen.status.title')}</DialogTitle>
                    <DialogDescription>
                        {t('panelen.status.intro')}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="status-text">
                            {t('panelen.status.field')}
                        </Label>
                        <div className="flex gap-2">
                            <Input
                                id="status-emoji"
                                value={emoji}
                                onChange={(event) =>
                                    setEmoji(event.target.value)
                                }
                                maxLength={16}
                                aria-label={t('panelen.status.emoji_field')}
                                placeholder="🙂"
                                className="w-14 text-center"
                            />
                            <Input
                                id="status-text"
                                value={text}
                                onChange={(event) =>
                                    setText(event.target.value)
                                }
                                maxLength={100}
                                placeholder={t('panelen.status.placeholder')}
                                className="flex-1"
                            />
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-1.5">
                        {options.map((option) => (
                            <button
                                key={option.text}
                                type="button"
                                onClick={() => {
                                    setEmoji(option.emoji ?? '');
                                    setText(option.text);
                                }}
                                className="rounded-full border px-2.5 py-1 text-xs transition-colors hover:bg-muted"
                            >
                                {option.emoji} {option.text}
                            </button>
                        ))}
                    </div>

                    <fieldset className="grid gap-1.5">
                        <legend className="mb-1 text-sm font-medium">
                            {t('panelen.status.availability')}
                        </legend>
                        {availabilityOptions.map((option) => (
                            <label
                                key={option.value}
                                className={cn(
                                    'flex cursor-pointer items-start gap-3 rounded-lg border p-2.5 text-sm transition-colors',
                                    availability === option.value
                                        ? 'border-primary bg-primary/5'
                                        : 'hover:bg-muted/50',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="availability"
                                    className="mt-0.5"
                                    value={option.value}
                                    checked={availability === option.value}
                                    onChange={() =>
                                        setAvailability(option.value)
                                    }
                                />
                                <span>
                                    <span className="font-medium">
                                        {option.label}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {option.description}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                </div>

                <DialogFooter className="sm:justify-between">
                    {/*
                        Clearing is its own button rather than emptying the field
                        by hand: it is the most common thing anybody does here
                        after setting one, and it should not take two steps.
                    */}
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={saving || (emoji === '' && text === '')}
                        onClick={() => {
                            setEmoji('');
                            setText('');
                            save({ emoji: '', text: '' });
                        }}
                    >
                        {t('panelen.status.clear')}
                    </Button>
                    <Button
                        type="button"
                        disabled={saving}
                        onClick={() => save()}
                    >
                        {t('panelen.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
