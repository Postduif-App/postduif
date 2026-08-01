import { Form, Head } from '@inertiajs/react';
import { Hash, Link2, Megaphone, Paperclip } from 'lucide-react';
import { useState } from 'react';

import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { update } from '@/routes/workspace/permissions';

interface Option {
    value: string;
    label: string;
}

interface AttachmentTypeOption extends Option {
    /** The examples somebody needs to recognise what they are ticking. */
    hint: string;
}

interface WorkspacePermissionsProps {
    workspace: {
        name: string;
        broadcastMentions: string;
        channelCreation: string;
        uploadsEnabled: boolean;
        allowedAttachmentTypes: string[];
        maxAttachmentKb: number;
        linkPreviewsEnabled: boolean;
    };
    broadcastMentionOptions: Option[];
    channelCreationOptions: Option[];
    attachmentTypeOptions: AttachmentTypeOption[];
}

/** The field is in megabytes; the server counts in kilobytes. */
const KB_PER_MB = 1024;

/**
 * One choice per question, each rendered as a list of cards rather than a
 * select: there are only ever a handful of options and every one of them needs
 * a sentence of explanation, which is exactly what a dropdown has no room for.
 */
function PolicyChoice({
    name,
    options,
    current,
    error,
    children,
}: {
    name: string;
    options: Option[];
    current: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <fieldset className="grid gap-2">
            {children}

            {options.map((option) => (
                <label
                    key={option.value}
                    className={cn(
                        'flex max-w-sm cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm transition-colors',
                        current === option.value
                            ? 'border-primary bg-primary/5'
                            : 'hover:bg-muted/50',
                    )}
                >
                    <input
                        type="radio"
                        name={name}
                        value={option.value}
                        defaultChecked={current === option.value}
                    />
                    {option.label}
                </label>
            ))}
            <InputError message={error} />
        </fieldset>
    );
}

/**
 * Whether files may be shared here, which kinds, and how large.
 *
 * The kinds and the size only appear once sharing is on: they are settings for
 * a thing that is happening, and showing them beside a switch that is off
 * suggests they still apply.
 */
function AttachmentSettings({
    workspace,
    options,
    errors,
}: {
    workspace: WorkspacePermissionsProps['workspace'];
    options: AttachmentTypeOption[];
    errors: Record<string, string | undefined>;
}) {
    const [enabled, setEnabled] = useState(workspace.uploadsEnabled);

    return (
        <fieldset className="grid gap-3">
            <legend className="flex items-center gap-1.5 text-sm font-medium">
                <Paperclip className="size-4 text-muted-foreground" />
                Bestanden delen in gesprekken
            </legend>

            {/*
                A hidden field alongside the checkbox: an unticked checkbox
                sends nothing at all, and "nothing" would read as a missing
                field rather than as "off".
            */}
            <input type="hidden" name="uploads_enabled" value="0" />
            <label className="flex max-w-sm cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm">
                <input
                    type="checkbox"
                    name="uploads_enabled"
                    value="1"
                    checked={enabled}
                    onChange={(event) => setEnabled(event.target.checked)}
                />
                Leden mogen bestanden meesturen met een bericht
            </label>
            <InputError message={errors.uploads_enabled} />

            {enabled && (
                <>
                    <div className="grid gap-2">
                        <p className="text-sm font-medium">
                            Welke bestandstypes zijn toegestaan?
                        </p>
                        {options.map((option) => (
                            <label
                                key={option.value}
                                className={cn(
                                    'flex max-w-sm cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors',
                                    'hover:bg-muted/50',
                                )}
                            >
                                <input
                                    type="checkbox"
                                    name="allowed_attachment_types[]"
                                    value={option.value}
                                    defaultChecked={workspace.allowedAttachmentTypes.includes(
                                        option.value,
                                    )}
                                    className="mt-0.5"
                                />
                                <span className="min-w-0">
                                    <span className="block font-medium">
                                        {option.label}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {option.hint}
                                    </span>
                                </span>
                            </label>
                        ))}
                        <InputError message={errors.allowed_attachment_types} />
                    </div>

                    <div className="grid max-w-sm gap-2">
                        <Label htmlFor="max_attachment_mb">
                            Maximale bestandsgrootte (MB)
                        </Label>
                        <Input
                            id="max_attachment_mb"
                            name="max_attachment_mb"
                            type="number"
                            min={1}
                            max={200}
                            defaultValue={Math.round(
                                workspace.maxAttachmentKb / KB_PER_MB,
                            )}
                        />
                        <p className="text-xs text-muted-foreground">
                            Tussen 1 en 200 MB. Wat de server zelf aankan kan
                            lager liggen; dan wint die.
                        </p>
                        <InputError message={errors.max_attachment_mb} />
                    </div>
                </>
            )}
        </fieldset>
    );
}

/**
 * Whether the server may open the links people paste.
 *
 * Its own section rather than a line among the file settings, and the
 * explanation is the point: this is the only setting in the application where
 * our server talks to the outside world on somebody's behalf. Off by default,
 * and the text says what turning it on means rather than what it shows.
 */
function LinkPreviewSetting({
    workspace,
    error,
}: {
    workspace: WorkspacePermissionsProps['workspace'];
    error?: string;
}) {
    return (
        <fieldset className="grid gap-3">
            <legend className="flex items-center gap-1.5 text-sm font-medium">
                <Link2 className="size-4 text-muted-foreground" />
                Voorbeeld bij gedeelde links
            </legend>

            {/* An unticked checkbox sends nothing; the hidden field means off. */}
            <input type="hidden" name="link_previews_enabled" value="0" />
            <label className="flex max-w-sm cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm">
                <input
                    type="checkbox"
                    name="link_previews_enabled"
                    value="1"
                    defaultChecked={workspace.linkPreviewsEnabled}
                    className="mt-0.5"
                />
                <span className="min-w-0">
                    <span className="block font-medium">
                        Haal titel en afbeelding op bij een gedeelde link
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        Let op wat dit betekent: onze server opent de link zelf.
                        Dat is zichtbaar aan de andere kant — met ons adres en
                        het moment. Voor een link uit een privékanaal deelt
                        niemand dat bewust, en daarom staat dit standaard uit.
                    </span>
                </span>
            </label>
            <InputError message={error} />
        </fieldset>
    );
}

export default function WorkspacePermissions({
    workspace,
    broadcastMentionOptions,
    channelCreationOptions,
    attachmentTypeOptions,
}: WorkspacePermissionsProps) {
    return (
        <>
            <Head title="Workspace — rechten" />

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="Rechten"
                    description={`Wat leden van ${workspace.name} mogen doen`}
                />

                <Form {...update.form()} options={{ preserveScroll: true }}>
                    {({ processing, errors, recentlySuccessful }) => (
                        <div className="space-y-6">
                            <PolicyChoice
                                name="broadcast_mentions"
                                options={broadcastMentionOptions}
                                current={workspace.broadcastMentions}
                                error={errors.broadcast_mentions}
                            >
                                <legend className="flex items-center gap-1.5 text-sm font-medium">
                                    <Megaphone className="size-4 text-muted-foreground" />
                                    Wie mag @here en @everyone gebruiken?
                                </legend>
                                <p className="text-sm text-muted-foreground">
                                    Deze vermeldingen bereiken in één keer een
                                    heel kanaal. Wie dat niet mag, kan ze niet
                                    kiezen en stuurt er niemand een melding mee.
                                </p>
                            </PolicyChoice>

                            <PolicyChoice
                                name="channel_creation"
                                options={channelCreationOptions}
                                current={workspace.channelCreation}
                                error={errors.channel_creation}
                            >
                                <legend className="flex items-center gap-1.5 text-sm font-medium">
                                    <Hash className="size-4 text-muted-foreground" />
                                    Wie mag nieuwe kanalen aanmaken?
                                </legend>
                                <p className="text-sm text-muted-foreground">
                                    Gasten mogen dit sowieso niet — die zijn er
                                    voor de kanalen waarvoor ze zijn
                                    uitgenodigd.
                                </p>
                            </PolicyChoice>

                            <AttachmentSettings
                                workspace={workspace}
                                options={attachmentTypeOptions}
                                errors={errors}
                            />

                            <LinkPreviewSetting
                                workspace={workspace}
                                error={errors.link_previews_enabled}
                            />

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Opslaan
                                </Button>
                                {recentlySuccessful && (
                                    <p className="text-sm text-muted-foreground">
                                        Opgeslagen.
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </Form>
            </div>
        </>
    );
}
