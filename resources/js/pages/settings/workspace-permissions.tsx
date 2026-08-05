import { Form, Head } from '@inertiajs/react';
import { Link2, Paperclip, Send, SpellCheck, X } from 'lucide-react';
import { useState } from 'react';

import { ChoiceText } from '@/components/choice-text';
import InputError from '@/components/input-error';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
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
        uploadsEnabled: boolean;
        allowedAttachmentTypes: string[];
        maxAttachmentKb: number;
        linkPreviewsEnabled: boolean;
        transfersEnabled: boolean;
        maxTransferKb: number;
        maxTransferDays: number;
        blockedWords: string[];
    };
    attachmentTypeOptions: AttachmentTypeOption[];
}

/** The field is in megabytes; the server counts in kilobytes. */
const KB_PER_MB = 1024;

/**
 * A setting you tick, drawn as a card.
 *
 * One constant rather than the four hand-written copies this file grew: three
 * of them agreed, the fourth had no hover state, and the odd one out looked
 * disabled beside its neighbours.
 */
const TOGGLE_ROW =
    'flex max-w-sm cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors hover:bg-muted/50';

/**
 * A rule between the groups rather than more whitespace.
 *
 * Four unrelated questions — what may be uploaded, whether links unfold, who
 * may hand over a ticket, which words are refused — ran together as one long
 * column, and a legend alone was not enough of a boundary to say where one
 * ended. Each group is wrapped in a plain div and the rule goes on that: a
 * border on the fieldset itself is cut open by its own legend, which leaves a
 * line dangling off the heading rather than running under it.
 */
const GROUPS =
    'space-y-8 [&>div+div]:border-t [&>div+div]:border-border/60 [&>div+div]:pt-8';

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
    const { t } = useTranslate();

    return (
        <fieldset className="grid gap-3">
            <legend className="flex items-center gap-1.5 text-sm font-medium">
                <Paperclip className="size-4 text-muted-foreground" />
                {t('settings.permissions.attachments')}
            </legend>

            {/*
                A hidden field alongside the checkbox: an unticked checkbox
                sends nothing at all, and "nothing" would read as a missing
                field rather than as "off".
            */}
            <input type="hidden" name="uploads_enabled" value="0" />
            <label className={TOGGLE_ROW}>
                <input
                    type="checkbox"
                    name="uploads_enabled"
                    value="1"
                    checked={enabled}
                    onChange={(event) => setEnabled(event.target.checked)}
                />
                {t('settings.permissions.attachments_toggle')}
            </label>
            <InputError message={errors.uploads_enabled} />

            {enabled && (
                <>
                    <div className="grid gap-2">
                        <p className="text-sm font-medium">
                            {t('settings.permissions.attachment_types')}
                        </p>
                        {options.map((option) => (
                            <label key={option.value} className={TOGGLE_ROW}>
                                <input
                                    type="checkbox"
                                    name="allowed_attachment_types[]"
                                    value={option.value}
                                    defaultChecked={workspace.allowedAttachmentTypes.includes(
                                        option.value,
                                    )}
                                    className="mt-0.5"
                                />
                                <ChoiceText
                                    title={option.label}
                                    hint={option.hint}
                                />
                            </label>
                        ))}
                        <InputError message={errors.allowed_attachment_types} />
                    </div>

                    <div className="grid max-w-sm gap-2">
                        <Label htmlFor="max_attachment_mb">
                            {t('settings.permissions.max_attachment')}
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
                            {t('settings.permissions.max_attachment_hint')}
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
    const { t } = useTranslate();

    return (
        <fieldset className="grid gap-3">
            <legend className="flex items-center gap-1.5 text-sm font-medium">
                <Link2 className="size-4 text-muted-foreground" />
                {t('settings.permissions.link_previews')}
            </legend>

            {/* An unticked checkbox sends nothing; the hidden field means off. */}
            <input type="hidden" name="link_previews_enabled" value="0" />
            <label className={TOGGLE_ROW}>
                <input
                    type="checkbox"
                    name="link_previews_enabled"
                    value="1"
                    defaultChecked={workspace.linkPreviewsEnabled}
                    className="mt-0.5"
                />
                <ChoiceText
                    title={t('settings.permissions.link_previews_toggle')}
                    hint={t('settings.permissions.link_previews_hint')}
                />
            </label>
            <InputError message={error} />
        </fieldset>
    );
}

/**
 * The ceilings on a file sent out of the workspace by link.
 *
 * Only rendered when the feature is on, and that is not the same reasoning as
 * hiding the attachment settings behind their switch: those are hidden because
 * they do not apply yet, these because they cannot be reached at all. A number
 * shown for something a workspace does not have reads as a promise it could
 * use it.
 */
function TransferSettings({
    workspace,
    errors,
}: {
    workspace: WorkspacePermissionsProps['workspace'];
    errors: Record<string, string | undefined>;
}) {
    const { t } = useTranslate();

    if (!workspace.transfersEnabled) {
        return null;
    }

    return (
        <fieldset className="grid gap-3">
            <legend className="flex items-center gap-1.5 text-sm font-medium">
                <Send className="size-4 text-muted-foreground" />
                {t('settings.permissions.transfers')}
            </legend>
            <p className="max-w-sm text-sm text-muted-foreground">
                {t('settings.permissions.transfers_hint')}
            </p>

            <div className="grid max-w-sm gap-2">
                <Label htmlFor="max_transfer_mb">
                    {t('settings.permissions.max_transfer')}
                </Label>
                <Input
                    id="max_transfer_mb"
                    name="max_transfer_mb"
                    type="number"
                    min={1}
                    max={10240}
                    defaultValue={Math.round(
                        workspace.maxTransferKb / KB_PER_MB,
                    )}
                />
                <p className="text-xs text-muted-foreground">
                    {t('settings.permissions.max_transfer_hint')}
                </p>
                <InputError message={errors.max_transfer_mb} />
            </div>

            <div className="grid max-w-sm gap-2">
                <Label htmlFor="max_transfer_days">
                    {t('settings.permissions.transfer_days')}
                </Label>
                <Input
                    id="max_transfer_days"
                    name="max_transfer_days"
                    type="number"
                    min={1}
                    max={90}
                    defaultValue={workspace.maxTransferDays}
                />
                <p className="text-xs text-muted-foreground">
                    {t('settings.permissions.transfer_days_hint')}
                </p>
                <InputError message={errors.max_transfer_days} />
            </div>
        </fieldset>
    );
}

/** The longest word the server accepts, so the field stops where it does. */
const MAX_WORD_LENGTH = 40;

/**
 * The words this workspace strikes out.
 *
 * Chips with an input under them rather than a textarea of lines: a blocklist
 * is read to check whether something is on it, and a list you can scan answers
 * that faster than a paragraph you have to read. The words travel as hidden
 * fields so this section submits with the rest of the form — including one
 * empty entry, because a form that sends no `blocked_words` at all cannot say
 * "I took the last word off".
 */
function BlockedWordsSetting({
    workspace,
    error,
}: {
    workspace: WorkspacePermissionsProps['workspace'];
    error?: string;
}) {
    const { t } = useTranslate();
    const [words, setWords] = useState(workspace.blockedWords);
    const [draft, setDraft] = useState('');

    const add = (word: string) => {
        const trimmed = word.trim();

        // Already on the list under any spelling: the censor does not care
        // about case, so neither does this. The field just clears.
        if (
            trimmed !== '' &&
            !words.some((each) => each.toLowerCase() === trimmed.toLowerCase())
        ) {
            setWords([...words, trimmed]);
        }

        setDraft('');
    };

    return (
        <fieldset className="grid gap-3">
            <legend className="flex items-center gap-1.5 text-sm font-medium">
                <SpellCheck className="size-4 text-muted-foreground" />
                {t('settings.permissions.blocked_words')}
            </legend>
            <p className="max-w-sm text-sm text-muted-foreground">
                {t('settings.permissions.blocked_words_hint')}
            </p>

            {/*
                The empty entry that makes "no words" a thing the form can say.
                Everything the server reads is filtered of blanks anyway.
            */}
            <input type="hidden" name="blocked_words[]" value="" />

            {words.length > 0 ? (
                <div className="flex max-w-sm flex-wrap gap-1.5">
                    {words.map((word) => (
                        <span
                            key={word.toLowerCase()}
                            className="inline-flex items-center gap-1 rounded-full border bg-muted/60 py-0.5 pr-1 pl-2.5 text-xs font-medium"
                        >
                            <input
                                type="hidden"
                                name="blocked_words[]"
                                value={word}
                            />
                            {word}
                            <button
                                type="button"
                                onClick={() =>
                                    setWords(
                                        words.filter((each) => each !== word),
                                    )
                                }
                                aria-label={t(
                                    'settings.permissions.blocked_words_remove',
                                    { word },
                                )}
                                className="rounded-full p-0.5 text-muted-foreground transition-colors hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <X className="size-3" />
                            </button>
                        </span>
                    ))}
                </div>
            ) : (
                <p className="max-w-sm text-xs text-muted-foreground">
                    {t('settings.permissions.blocked_words_empty')}
                </p>
            )}

            <div className="grid max-w-sm gap-2">
                <Label htmlFor="blocked_word">
                    {t('settings.permissions.blocked_words_label')}
                </Label>
                <Input
                    id="blocked_word"
                    value={draft}
                    maxLength={MAX_WORD_LENGTH}
                    placeholder={t(
                        'settings.permissions.blocked_words_placeholder',
                    )}
                    onChange={(event) => setDraft(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            // This field sits inside the settings form. Enter
                            // here means "add this word", never "save the page"
                            // — half a typed word would otherwise be saved by
                            // the reflex that finishes every other field.
                            event.preventDefault();
                            add(draft);

                            return;
                        }

                        if (
                            event.key === 'Backspace' &&
                            draft === '' &&
                            words.length > 0
                        ) {
                            setWords(words.slice(0, -1));
                        }
                    }}
                    // Whatever is still in the field when the form is submitted
                    // was meant to be on the list: somebody typed it and went
                    // straight for Save.
                    onBlur={() => add(draft)}
                />
                <InputError message={error} />
            </div>
        </fieldset>
    );
}

export default function WorkspacePermissions({
    workspace,
    attachmentTypeOptions,
}: WorkspacePermissionsProps) {
    const { t } = useTranslate();

    return (
        <>
            <Head title={t('settings.permissions.head')} />

            <SettingsSection
                title={t('settings.permissions.title')}
                description={t('settings.permissions.description', {
                    workspace: workspace.name,
                })}
            >
                <Form {...update.form()} options={{ preserveScroll: true }}>
                    {({ processing, errors, recentlySuccessful }) => (
                        <div className={GROUPS}>
                            <div>
                                <AttachmentSettings
                                    workspace={workspace}
                                    options={attachmentTypeOptions}
                                    errors={errors}
                                />
                            </div>

                            <div>
                                <LinkPreviewSetting
                                    workspace={workspace}
                                    error={errors.link_previews_enabled}
                                />
                            </div>

                            <div>
                                <TransferSettings
                                    workspace={workspace}
                                    errors={errors}
                                />
                            </div>

                            <div>
                                <BlockedWordsSetting
                                    workspace={workspace}
                                    error={errors.blocked_words}
                                />
                            </div>

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
        </>
    );
}
