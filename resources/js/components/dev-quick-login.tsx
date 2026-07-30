import { router } from '@inertiajs/react';
import { Zap } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { login } from '@/routes/dev';

export interface DevAccount {
    id: number;
    name: string;
    email: string;
    role: string | null;
}

/**
 * One-click sign-in for seeded accounts.
 *
 * The server sends an empty list outside local development, so there is no
 * environment flag for this component to check — no accounts, no buttons.
 */
export function DevQuickLogin({ accounts }: { accounts: DevAccount[] }) {
    const getInitials = useInitials();
    const [busyId, setBusyId] = useState<number | null>(null);

    if (accounts.length === 0) {
        return null;
    }

    return (
        <div className="rounded-lg border border-dashed border-amber-500/50 bg-amber-500/5 p-3">
            <p className="mb-2 flex items-center gap-1.5 text-xs font-medium text-amber-700 dark:text-amber-500">
                <Zap className="size-3.5" />
                Alleen in development — direct inloggen
            </p>

            <div className="grid gap-1.5">
                {accounts.map((account) => (
                    <Button
                        key={account.id}
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={busyId !== null}
                        onClick={() => {
                            setBusyId(account.id);
                            router.post(
                                login.url(account.id),
                                {},
                                { onFinish: () => setBusyId(null) },
                            );
                        }}
                        className="h-auto justify-start gap-2 px-2 py-1.5"
                    >
                        <span className="flex size-6 shrink-0 items-center justify-center rounded bg-muted text-[10px] font-semibold">
                            {getInitials(account.name)}
                        </span>
                        <span className="min-w-0 text-left">
                            <span className="block truncate text-sm font-medium">
                                {account.name}
                            </span>
                            <span className="block truncate text-xs font-normal text-muted-foreground">
                                {account.email}
                            </span>
                        </span>
                        {account.role && (
                            <span className="ml-auto shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                                {account.role}
                            </span>
                        )}
                    </Button>
                ))}
            </div>
        </div>
    );
}
