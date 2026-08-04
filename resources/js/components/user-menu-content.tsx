import { Link, router, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { LogOut, Monitor, Moon, Settings, Smile, Sun } from 'lucide-react';
import { useState } from 'react';

import { StatusDialog } from '@/components/status-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { useInitials } from '@/hooks/use-initials';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useTranslate } from '@/hooks/use-translate';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { Auth, User } from '@/types';
import type { TranslationKey } from '@/types/translations';

const appearanceOptions: {
    value: Appearance;
    icon: LucideIcon;
    label: TranslationKey;
}[] = [
    { value: 'light', icon: Sun, label: 'components.user_menu.light' },
    { value: 'dark', icon: Moon, label: 'components.user_menu.dark' },
    { value: 'system', icon: Monitor, label: 'components.user_menu.system' },
];

type Props = {
    user: User;
};

/**
 * The signed-in member's own menu, trigger and all.
 *
 * Whole rather than in halves: every chat screen hangs the same avatar, name
 * and status line above the same menu, and when each of them spelled that block
 * out itself there were eight copies of "Status instellen" — none of which the
 * literal check could see, because they sat in an expression rather than in
 * markup.
 */
export function UserMenu() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const getInitials = useInitials();
    const { t } = useTranslate();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors hover:bg-sidebar-accent/50 focus-visible:ring-2 focus-visible:outline-none">
                <Avatar className="size-8 shrink-0">
                    {/*
                        Above the fallback rather than instead of it: Radix
                        draws the initials until the picture has loaded, and
                        keeps them if it never does.
                    */}
                    {auth.avatarUrl && (
                        <AvatarImage src={auth.avatarUrl} alt="" />
                    )}
                    <AvatarFallback className="text-xs font-semibold">
                        {getInitials(auth.user.name)}
                    </AvatarFallback>
                </Avatar>
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                        {auth.user.name}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {auth.user.status_text
                            ? `${auth.user.status_emoji ?? ''} ${auth.user.status_text}`.trim()
                            : t('components.user_menu.set_status')}
                    </span>
                </span>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="start" className="w-56">
                <UserMenuContent user={auth.user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const { auth } = usePage<{ auth: Auth }>().props;
    const { t } = useTranslate();
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
                        t('components.user_menu.set_status')
                    )}
                </DropdownMenuItem>

                {/*
                    The appearance store is module level, so switching here and
                    switching on the settings page stay in sync without props.
                */}
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger className="cursor-pointer">
                        <AppearanceIcon className="mr-2 size-4" />
                        {t('components.user_menu.appearance')}
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
                                        {t(label)}
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
                        {t('components.user_menu.settings')}
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
                    {t('components.user_menu.log_out')}
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
