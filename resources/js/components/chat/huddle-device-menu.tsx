import { ChevronDown } from 'lucide-react';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { MediaDevice } from '@/hooks/use-media-devices';
import { useTranslate } from '@/hooks/use-translate';

interface HuddleDeviceMenuProps {
    label: string;
    devices: MediaDevice[];
    onChoose: (deviceId: string) => void;
}

/**
 * Which microphone, or which camera.
 *
 * A chevron beside the button rather than a menu on the button itself: pressing
 * mute is the thing people do in a hurry, and a button that sometimes opens a
 * list instead of muting you is a button you stop trusting. This is the quiet
 * half of the pair.
 *
 * Nothing at all when there is only one of something. A menu offering the only
 * microphone in the machine is a menu that answers a question nobody asked.
 */
export function HuddleDeviceMenu({
    label,
    devices,
    onChoose,
}: HuddleDeviceMenuProps) {
    const { t } = useTranslate();

    if (devices.length < 2) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                aria-label={label}
                title={label}
                className="-ml-1 rounded-md border border-l-0 px-1 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
            >
                <ChevronDown className="size-3" />
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="max-w-72">
                <DropdownMenuLabel>{label}</DropdownMenuLabel>

                {devices.map((device) => (
                    <DropdownMenuItem
                        key={device.id}
                        onSelect={() => onChoose(device.id)}
                        className="truncate"
                    >
                        {/*
                            A device the browser will not name — which happens
                            for a moment after permission is granted, before the
                            list is read again.
                        */}
                        {device.label === ''
                            ? t('chat_ui.huddle.unnamed_device')
                            : device.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
