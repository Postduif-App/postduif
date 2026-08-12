import { Form, Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Mail, Send, Server } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
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
import { Spinner } from '@/components/ui/spinner';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { test, update } from '@/routes/workspace/mail';

interface TransportOption {
    value: string;
    label: string;
    description: string;
}

interface EncryptionOption {
    value: string;
    label: string;
    description: string;
    /** What this choice normally listens on, filled in when it is picked. */
    port: number;
}

interface MailSettings {
    transport: string;
    from_address: string | null;
    from_name: string | null;
    smtp_host: string | null;
    smtp_port: number | null;
    smtp_encryption: string;
    smtp_username: string | null;
    postmark_message_stream: string | null;
    lettermint_route_id: string | null;
    /*
     * Whether a secret exists, never the secret itself — the server does not
     * send those back. See WorkspaceMailController::edit.
     */
    has_smtp_password: boolean;
    has_postmark_token: boolean;
    has_lettermint_token: boolean;
    verified_at: string | null;
    last_error: string | null;
}

interface WorkspaceMailProps {
    workspace: { name: string };
    settings: MailSettings;
    transportOptions: TransportOption[];
    encryptionOptions: EncryptionOption[];
    /** Where a test message would land: the address of whoever is looking. */
    testRecipient: string;
}

/**
 * A password field that admits it already has something in it.
 *
 * The one interaction on this screen that needs explaining. The server never
 * sends a secret back, so an untouched field is empty — which looks exactly
 * like "nothing was ever set". Saying so under the field, and only when there
 * is something to say, is what keeps somebody from retyping an API key every
 * time they change a port.
 */
function SecretField({
    name,
    label,
    hint,
    isSet,
    error,
}: {
    name: string;
    label: string;
    hint?: string;
    isSet: boolean;
    error?: string;
}) {
    const { t } = useTranslate();

    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <Input
                id={name}
                name={name}
                type="password"
                autoComplete="new-password"
                placeholder={isSet ? t('settings.mail.secret_placeholder') : ''}
            />
            <p className="text-xs text-muted-foreground">
                {isSet ? t('settings.mail.secret_keep') : hint}
            </p>
            <InputError message={error} />
        </div>
    );
}

/**
 * Where this workspace's mail leaves from.
 *
 * The picker decides what the rest of the page is: choosing a transport swaps
 * the block of fields under it rather than showing all three at once with two
 * of them greyed out. Only the chosen one is submitted, which is also what the
 * server stores — everything else is cleared on save.
 */
export default function WorkspaceMailSettings({
    workspace,
    settings,
    transportOptions,
    encryptionOptions,
    testRecipient,
}: WorkspaceMailProps) {
    const [transport, setTransport] = useState(settings.transport);
    const [encryption, setEncryption] = useState(settings.smtp_encryption);
    /*
     * Controlled, unlike its neighbours: picking STARTTLS after SSL should move
     * the port with it, because 465 under STARTTLS is the single most common
     * way for an otherwise correct setup to hang. Still editable — plenty of
     * relays listen somewhere else.
     */
    const [port, setPort] = useState(
        settings.smtp_port === null ? '587' : String(settings.smtp_port),
    );
    const [testing, setTesting] = useState(false);
    const { t } = useTranslate();
    const formats = useFormats();

    const chooseEncryption = (value: string) => {
        setEncryption(value);
        setPort(
            String(
                encryptionOptions.find((option) => option.value === value)
                    ?.port ?? port,
            ),
        );
    };

    const sendTest = () => {
        setTesting(true);

        router.post(
            test().url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setTesting(false),
            },
        );
    };

    return (
        <>
            <Head title={t('settings.mail.head')} />

            <SettingsSection
                title={t('settings.mail.title')}
                description={t('settings.mail.description', {
                    workspace: workspace.name,
                })}
            >
                <p className="max-w-prose text-sm text-muted-foreground">
                    {t('settings.mail.intro')}
                </p>

                <Form {...update.form()} options={{ preserveScroll: true }}>
                    {({ processing, errors, recentlySuccessful }) => (
                        <div className="space-y-8 [&>div+div]:border-t [&>div+div]:border-border/60 [&>div+div]:pt-8">
                            <div>
                                <fieldset className="grid gap-3">
                                    <legend className="flex items-center gap-1.5 text-sm font-medium">
                                        <Send className="size-4 text-muted-foreground" />
                                        {t('settings.mail.transport')}
                                    </legend>

                                    <div className="grid gap-2">
                                        {transportOptions.map((option) => (
                                            <label
                                                key={option.value}
                                                className={cn(
                                                    'flex max-w-prose cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors',
                                                    transport === option.value
                                                        ? 'border-primary bg-primary/5'
                                                        : 'hover:bg-muted/50',
                                                )}
                                            >
                                                <input
                                                    type="radio"
                                                    name="transport"
                                                    value={option.value}
                                                    checked={
                                                        transport ===
                                                        option.value
                                                    }
                                                    onChange={() =>
                                                        setTransport(
                                                            option.value,
                                                        )
                                                    }
                                                    className="mt-0.5"
                                                />
                                                <span className="grid gap-0.5">
                                                    <span className="font-medium">
                                                        {option.label}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        {option.description}
                                                    </span>
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                    <InputError message={errors.transport} />
                                </fieldset>
                            </div>

                            {transport === 'default' ? (
                                <div>
                                    <p className="max-w-prose text-sm text-muted-foreground">
                                        {t('settings.mail.default_notice')}
                                    </p>
                                </div>
                            ) : (
                                <div>
                                    <fieldset className="grid max-w-sm gap-4">
                                        <legend className="flex items-center gap-1.5 text-sm font-medium">
                                            <Mail className="size-4 text-muted-foreground" />
                                            {t('settings.mail.sender')}
                                        </legend>

                                        <div className="grid gap-2">
                                            <Label htmlFor="from_address">
                                                {t(
                                                    'settings.mail.from_address',
                                                )}
                                            </Label>
                                            <Input
                                                id="from_address"
                                                name="from_address"
                                                type="email"
                                                defaultValue={
                                                    settings.from_address ?? ''
                                                }
                                                placeholder={t(
                                                    'settings.mail.from_address_placeholder',
                                                )}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'settings.mail.from_address_hint',
                                                )}
                                            </p>
                                            <InputError
                                                message={errors.from_address}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="from_name">
                                                {t('settings.mail.from_name')}
                                            </Label>
                                            <Input
                                                id="from_name"
                                                name="from_name"
                                                defaultValue={
                                                    settings.from_name ?? ''
                                                }
                                                placeholder={t(
                                                    'settings.mail.from_name_placeholder',
                                                )}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'settings.mail.from_name_hint',
                                                )}
                                            </p>
                                            <InputError
                                                message={errors.from_name}
                                            />
                                        </div>
                                    </fieldset>
                                </div>
                            )}

                            {transport === 'smtp' && (
                                <div>
                                    <fieldset className="grid max-w-sm gap-4">
                                        <legend className="flex items-center gap-1.5 text-sm font-medium">
                                            <Server className="size-4 text-muted-foreground" />
                                            {t('settings.mail.smtp')}
                                        </legend>

                                        <div className="grid gap-2">
                                            <Label htmlFor="smtp_host">
                                                {t('settings.mail.smtp_host')}
                                            </Label>
                                            <Input
                                                id="smtp_host"
                                                name="smtp_host"
                                                defaultValue={
                                                    settings.smtp_host ?? ''
                                                }
                                                placeholder={t(
                                                    'settings.mail.smtp_host_placeholder',
                                                )}
                                            />
                                            <InputError
                                                message={errors.smtp_host}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="smtp_encryption">
                                                {t(
                                                    'settings.mail.smtp_encryption',
                                                )}
                                            </Label>
                                            <Select
                                                value={encryption}
                                                onValueChange={chooseEncryption}
                                            >
                                                <SelectTrigger id="smtp_encryption">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {encryptionOptions.map(
                                                        (option) => (
                                                            <SelectItem
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <input
                                                type="hidden"
                                                name="smtp_encryption"
                                                value={encryption}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {
                                                    encryptionOptions.find(
                                                        (option) =>
                                                            option.value ===
                                                            encryption,
                                                    )?.description
                                                }
                                            </p>
                                            <InputError
                                                message={errors.smtp_encryption}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="smtp_port">
                                                {t('settings.mail.smtp_port')}
                                            </Label>
                                            <Input
                                                id="smtp_port"
                                                name="smtp_port"
                                                type="number"
                                                inputMode="numeric"
                                                value={port}
                                                onChange={(event) =>
                                                    setPort(event.target.value)
                                                }
                                            />
                                            <InputError
                                                message={errors.smtp_port}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="smtp_username">
                                                {t(
                                                    'settings.mail.smtp_username',
                                                )}
                                            </Label>
                                            <Input
                                                id="smtp_username"
                                                name="smtp_username"
                                                autoComplete="off"
                                                defaultValue={
                                                    settings.smtp_username ?? ''
                                                }
                                            />
                                            <InputError
                                                message={errors.smtp_username}
                                            />
                                        </div>

                                        <SecretField
                                            name="smtp_password"
                                            label={t(
                                                'settings.mail.smtp_password',
                                            )}
                                            isSet={settings.has_smtp_password}
                                            error={errors.smtp_password}
                                        />
                                    </fieldset>
                                </div>
                            )}

                            {transport === 'postmark' && (
                                <div>
                                    <fieldset className="grid max-w-sm gap-4">
                                        <legend className="flex items-center gap-1.5 text-sm font-medium">
                                            <Server className="size-4 text-muted-foreground" />
                                            {t('settings.mail.postmark')}
                                        </legend>

                                        <SecretField
                                            name="postmark_token"
                                            label={t(
                                                'settings.mail.postmark_token',
                                            )}
                                            hint={t(
                                                'settings.mail.postmark_token_hint',
                                            )}
                                            isSet={settings.has_postmark_token}
                                            error={errors.postmark_token}
                                        />

                                        <div className="grid gap-2">
                                            <Label htmlFor="postmark_message_stream">
                                                {t(
                                                    'settings.mail.postmark_stream',
                                                )}
                                            </Label>
                                            <Input
                                                id="postmark_message_stream"
                                                name="postmark_message_stream"
                                                defaultValue={
                                                    settings.postmark_message_stream ??
                                                    ''
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'settings.mail.postmark_stream_hint',
                                                )}
                                            </p>
                                            <InputError
                                                message={
                                                    errors.postmark_message_stream
                                                }
                                            />
                                        </div>
                                    </fieldset>
                                </div>
                            )}

                            {transport === 'lettermint' && (
                                <div>
                                    <fieldset className="grid max-w-sm gap-4">
                                        <legend className="flex items-center gap-1.5 text-sm font-medium">
                                            <Server className="size-4 text-muted-foreground" />
                                            {t('settings.mail.lettermint')}
                                        </legend>

                                        <SecretField
                                            name="lettermint_token"
                                            label={t(
                                                'settings.mail.lettermint_token',
                                            )}
                                            hint={t(
                                                'settings.mail.lettermint_token_hint',
                                            )}
                                            isSet={
                                                settings.has_lettermint_token
                                            }
                                            error={errors.lettermint_token}
                                        />

                                        <div className="grid gap-2">
                                            <Label htmlFor="lettermint_route_id">
                                                {t(
                                                    'settings.mail.lettermint_route',
                                                )}
                                            </Label>
                                            <Input
                                                id="lettermint_route_id"
                                                name="lettermint_route_id"
                                                defaultValue={
                                                    settings.lettermint_route_id ??
                                                    ''
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'settings.mail.lettermint_route_hint',
                                                )}
                                            </p>
                                            <InputError
                                                message={
                                                    errors.lettermint_route_id
                                                }
                                            />
                                        </div>
                                    </fieldset>
                                </div>
                            )}

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('settings.actions.save')}
                                </Button>
                                {recentlySuccessful && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('settings.actions.saved')}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </Form>
            </SettingsSection>

            {/*
                Outside the form on purpose, and only once a transport has been
                chosen. A test button inside the form would submit it, and what
                is being tested is what is saved — not what is on screen.
            */}
            {settings.transport !== 'default' && (
                <SettingsSection
                    separated
                    title={t('settings.mail.test')}
                    description={t('settings.mail.test_hint', {
                        email: testRecipient,
                    })}
                    className="mt-10"
                >
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={sendTest}
                            disabled={testing}
                        >
                            {testing ? (
                                <Spinner />
                            ) : (
                                <Send className="size-4" />
                            )}
                            {t('settings.mail.test_button')}
                        </Button>

                        {settings.verified_at ? (
                            <p className="flex items-center gap-1.5 text-sm text-emerald-600">
                                <CheckCircle2 className="size-4" />
                                {t('settings.mail.verified', {
                                    date: formats.mediumDate.format(
                                        new Date(settings.verified_at),
                                    ),
                                })}
                            </p>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('settings.mail.never_verified')}
                            </p>
                        )}
                    </div>

                    {settings.last_error && (
                        <div className="flex max-w-prose items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-destructive" />
                            <div className="grid gap-1">
                                <span className="font-medium">
                                    {t('settings.mail.last_error')}
                                </span>
                                {/*
                                    Whatever the mail server said, word for word.
                                    In monospace because it is a machine talking:
                                    "535 5.7.8" is a code somebody will look up.
                                */}
                                <code className="font-mono text-xs break-words">
                                    {settings.last_error}
                                </code>
                            </div>
                        </div>
                    )}
                </SettingsSection>
            )}
        </>
    );
}
