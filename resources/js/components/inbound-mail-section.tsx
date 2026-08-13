import { router, usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound } from 'lucide-react';
import { useState } from 'react';

import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslate } from '@/hooks/use-translate';
import { inbound } from '@/routes/workspace/mail';
import { token as rollToken } from '@/routes/workspace/mail/inbound';

/** A channel that keeps tickets, and so can hold what the post brings in. */
interface InboundChannel {
    id: number;
    name: string | null;
}

/**
 * Where mail sent *to* the workspace lands.
 *
 * The mirror of the transport form above it, and deliberately a separate form:
 * saving a channel here says nothing about whether sending still works, so it
 * must not clear the verification tick that the other one owns.
 *
 * The delivery URL is the whole credential, and it is shown exactly once —
 * after it is made. Everything after that only knows that there is one. Rolling
 * it is its own button and its own sentence, because it breaks a working
 * arrangement the moment it is pressed: mail keeps arriving at the provider and
 * stops arriving here until somebody pastes the new URL over there.
 */
export function InboundMailSection({
    channels,
    channelId,
    address,
    hasToken,
}: {
    channels: InboundChannel[];
    channelId: number | null;
    address: string | null;
    hasToken: boolean;
}) {
    const { t } = useTranslate();

    /*
     * The freshly minted URL, flashed by the server on the one response that
     * knows it. Read off the page rather than kept in state, so a reload takes
     * it off the screen — which is the honest behaviour for something that was
     * never stored anywhere it could be shown again.
     */
    const { inboundUrl } = usePage<{ inboundUrl?: string }>().props;

    const [channel, setChannel] = useState(
        channelId === null ? 'none' : String(channelId),
    );
    const [inboundAddress, setInboundAddress] = useState(address ?? '');
    const [copied, setCopied] = useState(false);

    const save = () => {
        router.patch(
            inbound().url,
            {
                inbound_channel_id: channel === 'none' ? null : Number(channel),
                inbound_address: inboundAddress.trim() || null,
            },
            { preserveScroll: true },
        );
    };

    return (
        <SettingsSection
            separated
            title={t('settings.mail.inbound.title')}
            description={t('settings.mail.inbound.description')}
            className="mt-10"
        >
            {channels.length === 0 ? (
                <p className="max-w-prose text-sm text-muted-foreground">
                    {t('settings.mail.inbound.no_channels')}
                </p>
            ) : (
                <div className="grid max-w-prose gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="inbound-channel">
                            {t('settings.mail.inbound.channel_label')}
                        </Label>
                        <p className="text-sm text-muted-foreground">
                            {t('settings.mail.inbound.channel_hint')}
                        </p>

                        <Select value={channel} onValueChange={setChannel}>
                            <SelectTrigger id="inbound-channel">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {/*
                                    Switching it off is a choice in the list
                                    rather than a separate button: "nergens" is
                                    a valid answer to "waar komt post binnen",
                                    and it reads better beside the others than
                                    as a destructive-looking action.
                                */}
                                <SelectItem value="none">
                                    {t('settings.mail.inbound.channel_none')}
                                </SelectItem>
                                {channels.map((option) => (
                                    <SelectItem
                                        key={option.id}
                                        value={String(option.id)}
                                    >
                                        {option.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="inbound-address">
                            {t('settings.mail.inbound.address_label')}
                        </Label>
                        <p className="text-sm text-muted-foreground">
                            {t('settings.mail.inbound.address_hint')}
                        </p>
                        <Input
                            id="inbound-address"
                            type="email"
                            value={inboundAddress}
                            onChange={(event) =>
                                setInboundAddress(event.target.value)
                            }
                            placeholder="support@voorbeeld.nl"
                        />
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="button" onClick={save}>
                            {t('settings.mail.inbound.save')}
                        </Button>

                        {hasToken && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        rollToken().url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <KeyRound className="size-4" />
                                {t('settings.mail.inbound.roll')}
                            </Button>
                        )}
                    </div>

                    {inboundUrl && (
                        <div className="grid gap-1.5 rounded-lg border border-emerald-600/40 bg-emerald-600/5 p-3">
                            <span className="text-sm font-medium">
                                {t('settings.mail.inbound.url_heading')}
                            </span>
                            <p className="text-sm text-muted-foreground">
                                {t('settings.mail.inbound.url_once')}
                            </p>

                            <div className="flex items-center gap-2">
                                <code className="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1 font-mono text-xs">
                                    {inboundUrl}
                                </code>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        void navigator.clipboard.writeText(
                                            inboundUrl,
                                        );
                                        setCopied(true);
                                    }}
                                >
                                    {copied ? (
                                        <Check className="size-3.5" />
                                    ) : (
                                        <Copy className="size-3.5" />
                                    )}
                                    {t('settings.mail.inbound.copy')}
                                </Button>
                            </div>
                        </div>
                    )}

                    {hasToken && !inboundUrl && (
                        <p className="text-sm text-muted-foreground">
                            {t('settings.mail.inbound.url_hidden')}
                        </p>
                    )}
                </div>
            )}
        </SettingsSection>
    );
}
