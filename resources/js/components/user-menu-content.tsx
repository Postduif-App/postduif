import { Link, router, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { LogOut, Monitor, Moon, Settings, Smile, Sun } from 'lucide-react';
import { useState } from 'react';

import { StatusDialog } from '@/components/status-dialog';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { Auth, User } from '@/types';

const appearanceOptions: {
    value: Appearance;
    icon: LucideIcon;
    label: string;
}[] = [
    { value: 'light', icon: Sun, label: 'Licht' },
    { value: 'dark', icon: Moon, label: 'Donker' },
    { value: 'system', icon: Monitor, label: 'Systeem' },
];

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const { auth } = usePage<{ auth: Auth }>().props;
    const [statusOpen, setStatusOpen] = useState(false);
    const { appearance, resolvedAppearance, updateAppearance } =
        useAppearance();
    const AppearanceIcon = resolvedAppearance === 'dark' ? Moon : Sun;

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                {/*
                    The menu deliberately stays open behind the dialog: this item
                    lives inside the dropdown, so letting the menu close would
                    unmount the dialog along with it.
                */}
                <DropdownMenuItem
                    className="cursor-pointer"
                    onSelect={(event) => {
                        event.preventDefault();
                        setStatusOpen(true);
                    }}
                >
                    <Smile className="mr-2" />
                    {user.status_text ? (
                        <span className="truncate">
                            {user.status_emoji} {user.status_text}
                        </span>
                    ) : (
                        'Status instellen'
                    )}
                </DropdownMenuItem>

                {/*
                    The appearance store is module level, so switching here and
                    switching on the settings page stay in sync without props.
                */}
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger className="cursor-pointer">
                        <AppearanceIcon className="mr-2 size-4" />
                        Weergave
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                        <DropdownMenuRadioGroup
                            value={appearance}
                            onValueChange={(value) =>
                                updateAppearance(value as Appearance)
                            }
                        >
                            {appearanceOptions.map(
                                ({ value, icon: Icon, label }) => (
                                    <DropdownMenuRadioItem
                                        key={value}
                                        value={value}
                                        className="cursor-pointer"
                                        onSelect={(event) =>
                                            event.preventDefault()
                                        }
                                    >
                                        <Icon className="size-4" />
                                        {label}
                                    </DropdownMenuRadioItem>
                                ),
                            )}
                        </DropdownMenuRadioGroup>
                    </DropdownMenuSubContent>
                </DropdownMenuSub>

                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="mr-2" />
                        Instellingen
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    Uitloggen
                </Link>
            </DropdownMenuItem>

            <StatusDialog
                user={user}
                availabilityOptions={auth.availabilityOptions}
                open={statusOpen}
                onOpenChange={setStatusOpen}
            />
        </>
    );
}
