import { router } from '@inertiajs/react';
import { Check, FolderPlus, Plus } from 'lucide-react';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslate } from '@/hooks/use-translate';
import { store, update } from '@/routes/chat/sections';
import type {
    ActiveChannel,
    ChannelSection,
    ChatWorkspace,
} from '@/types/chat';

/**
 * Filing this channel under one of your own headings.
 *
 * Yours alone, like muting and starring beside it: the menu never says who else
 * put this channel where, because nobody else can see it. A channel sits in at
 * most one group — in two it is in neither, as far as finding it back goes —
 * so this is a list of one choice rather than a set of ticks.
 */
export function SectionMenu({
    workspace,
    channel,
    sections,
}: {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
    sections: ChannelSection[];
}) {
    const { t } = useTranslate();
    const [naming, setNaming] = useState(false);
    const [name, setName] = useState('');

    const current = sections.find((section) =>
        section.channelIds.includes(channel.id),
    );

    const file = (sectionId: number | null) =>
        router.put(
            update.url({ workspace: workspace.slug }),
            { channel_id: channel.id, section_id: sectionId },
            { preserveScroll: true },
        );

    return (
        <>
            <DropdownMenu>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <DropdownMenuTrigger
                            aria-label={t('panelen.section.file')}
                            className="rounded-md border p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        >
                            <FolderPlus className="size-3.5" />
                        </DropdownMenuTrigger>
                    </TooltipTrigger>
                    <TooltipContent>
                        {current
                            ? t('panelen.section.filed_in', {
                                  name: current.name,
                              })
                            : t('panelen.section.file')}
                    </TooltipContent>
                </Tooltip>

                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuLabel className="font-normal text-muted-foreground">
                        {t('panelen.section.yours_alone')}
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />

                    {sections.map((section) => (
                        <DropdownMenuItem
                            key={section.id}
                            className="cursor-pointer"
                            onSelect={() =>
                                file(
                                    current?.id === section.id
                                        ? null
                                        : section.id,
                                )
                            }
                        >
                            {current?.id === section.id ? (
                                <Check className="size-4" />
                            ) : (
                                <span className="size-4" />
                            )}
                            {section.name}
                        </DropdownMenuItem>
                    ))}

                    {sections.length > 0 && <DropdownMenuSeparator />}

                    <DropdownMenuItem
                        className="cursor-pointer"
                        onSelect={(event) => {
                            // The menu closes as the dialog opens otherwise,
                            // taking the dialog with it.
                            event.preventDefault();
                            setNaming(true);
                        }}
                    >
                        <Plus className="size-4" />
                        {t('panelen.section.new_menu')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={naming} onOpenChange={setNaming}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('panelen.section.new')}</DialogTitle>
                        <DialogDescription>
                            {t('panelen.section.intro')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="section-name">
                            {t('panelen.section.name_field')}
                        </Label>
                        <Input
                            id="section-name"
                            value={name}
                            maxLength={40}
                            placeholder={t('panelen.section.name_placeholder')}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setNaming(false)}
                        >
                            {t('panelen.cancel')}
                        </Button>
                        <Button
                            disabled={name.trim() === ''}
                            onClick={() =>
                                router.post(
                                    store.url({ workspace: workspace.slug }),
                                    { name: name.trim() },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setNaming(false);
                                            setName('');
                                        },
                                    },
                                )
                            }
                        >
                            {t('panelen.section.create')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
