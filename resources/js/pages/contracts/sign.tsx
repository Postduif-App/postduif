import { Head, router, usePage } from '@inertiajs/react';
import {
    Ban,
    CalendarX,
    Check,
    CheckCircle2,
    FileSignature,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { ContractDocument } from '@/components/chat/contract-document';
import { SignaturePad } from '@/components/chat/signature-pad';
import type { SignatureMethod } from '@/components/chat/signature-pad';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslate } from '@/hooks/use-translate';
import { toPixels } from '@/lib/contract-fields';
import type { RenderedPage } from '@/lib/contract-fields';
import { cn } from '@/lib/utils';
import {
    complete as completeSigning,
    decline as declineSigning,
    store as saveDraft,
    signature as storeSignature,
} from '@/routes/contracts/sign';
import type { TranslationKey } from '@/types/translations';

interface SignableField {
    id: number;
    page: number;
    x: number;
    y: number;
    width: number;
    height: number;
    type: string;
    label: string;
    isRequired: boolean;
    value: string | null;
    filled: boolean;
}

/**
 * One box somebody else already dealt with, drawn but never touched.
 *
 * Deliberately not a SignableField: it has no label, no isRequired and no id to
 * save against, because there is nothing here to fill in. What it carries is
 * what to show — text, or the address of a mark.
 */
interface FilledField {
    id: number;
    page: number;
    x: number;
    y: number;
    width: number;
    height: number;
    type: string;
    value: string | null;
    mark: string | null;
}

interface SignableContract {
    title: string;
    message: string | null;
    pageCount: number;
    expiresAt: string | null;
    signerName: string;
    signerCount: number;
    signedCount: number;
    /*
     * One mark per kind, shown in every box of that kind. Null where this
     * person has not made one yet. See StoreSignature for why these are not
     * per box.
     */
    marks: Record<string, string | null>;
    fields: SignableField[];
    /*
     * The boxes of people who signed before this person. Read-only, and kept
     * apart from `fields` so nothing on this page can ever save into one — see
     * PresentContractForSigner.
     */
    filled: FilledField[];
}

/**
 * Which of the five screens this is.
 *
 * Four of them are endings and one is the contract. See ContractSignController
 * for why a dead link explains itself here rather than answering 404 as the
 * public form does.
 */
type SignState =
    'signing' | 'signed' | 'declined' | 'completed' | 'expired' | 'cancelled';

interface SignProps {
    token: string;
    state: SignState;
    contract: SignableContract;
    documentUrl: string;
}

/**
 * How wide a page is drawn on this screen.
 *
 * Measured rather than fixed, because most people sign this on a telephone. The
 * editor offers zoom steps; this offers whatever the screen has, because
 * somebody filling in one contract once wants it to fit, not to be adjustable.
 */
const MAX_PAGE_WIDTH = 820;

/** How long the page waits after a keystroke before it saves. */
const AUTOSAVE_IDLE_MS = 1500;

export default function ContractSign({
    token,
    state,
    contract,
    documentUrl,
}: SignProps) {
    if (state !== 'signing') {
        return <ClosedScreen state={state} contract={contract} />;
    }

    /*
     * Nothing here but the document.
     *
     * No navigation, no workspace around it, and no second column: whoever
     * opened this link came from an email to do one thing, is very often
     * standing up holding a telephone while they do it, and every strip of
     * interface that is not the contract is a strip of screen the contract does
     * not get. What has to be said — whose name this is in, what was written
     * alongside it, how much is left — is said in the two bars, and the whole
     * of the middle is the page they are being asked to sign.
     */
    return (
        <SigningSurface
            token={token}
            contract={contract}
            documentUrl={documentUrl}
        />
    );
}

/**
 * The document with this person's own boxes on it, and the autosave behind it.
 *
 * Split from the page above so the hooks below never run on one of the closed
 * screens — a component that returns early before its hooks is a component that
 * breaks the moment somebody adds one.
 */
function SigningSurface({
    token,
    contract,
    documentUrl,
}: {
    token: string;
    contract: SignableContract;
    documentUrl: string;
}) {
    const { t, tChoice } = useTranslate();

    const [values, setValues] = useState<Record<number, string>>(() =>
        Object.fromEntries(
            contract.fields.map((field) => [field.id, field.value ?? '']),
        ),
    );

    const [pageWidth, setPageWidth] = useState(MAX_PAGE_WIDTH);
    const column = useRef<HTMLDivElement | null>(null);

    /*
     * The width the pages are drawn at, measured off the column they sit in.
     *
     * A ResizeObserver rather than a window listener, because what matters is
     * how much room this column has — which changes when the phone is turned
     * and also when a scrollbar appears, and only one of those is a window
     * resize.
     */
    useEffect(() => {
        const element = column.current;

        if (element === null) {
            return;
        }

        const measure = () =>
            setPageWidth(Math.min(MAX_PAGE_WIDTH, element.clientWidth));

        measure();

        const observer = new ResizeObserver(measure);
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    /*
     * The pending save, so a burst of typing becomes one request.
     *
     * A ref rather than state: it changes on every keystroke and re-rendering
     * the whole document for it would drop characters on a slow phone.
     */
    const pending = useRef<ReturnType<typeof setTimeout> | null>(null);
    const [saved, setSaved] = useState(false);

    /*
     * Which kind of mark is being made, or null when the dialog is shut.
     *
     * The kind rather than the box, because there is one mark per kind and it
     * fills every box of that kind at once — tapping the third initials box on
     * page seven is asking the same question as tapping the first.
     */
    const [marking, setMarking] = useState<string | null>(null);
    const [uploading, setUploading] = useState(false);

    const putDownMark = (
        image: Blob,
        method: SignatureMethod,
        typedName?: string,
    ) => {
        if (marking === null) {
            return;
        }

        setUploading(true);

        /*
         * Sent as a file rather than as a data URL in the JSON body. A base64
         * string would be a third larger and would arrive through a validator
         * that knows nothing about images; this goes through the same machinery
         * every other upload in the application does.
         */
        router.post(
            storeSignature.url(token),
            {
                kind: marking,
                method,
                typed: typedName ?? null,
                image: new File([image], 'signature.png', {
                    type: 'image/png',
                }),
            } as unknown as Record<string, never>,
            {
                preserveScroll: true,
                forceFormData: true,
                onFinish: () => {
                    setUploading(false);
                    setMarking(null);
                },
            },
        );
    };

    const change = (id: number, value: string) => {
        setValues((current) => {
            const next = { ...current, [id]: value };

            if (pending.current !== null) {
                clearTimeout(pending.current);
            }

            /*
             * Saved after a pause rather than on every keystroke. The draft
             * exists so that a closed tab does not cost somebody their
             * afternoon — a second and a half of stillness is well inside what
             * anybody loses by accident, and it turns a hundred requests into
             * one.
             */
            pending.current = setTimeout(() => {
                setSaved(false);

                router.post(
                    saveDraft.url(token),
                    { values: next as unknown as Record<string, never> },
                    {
                        preserveScroll: true,
                        preserveState: true,
                        onSuccess: () => setSaved(true),
                    },
                );
            }, AUTOSAVE_IDLE_MS);

            return next;
        });
    };

    // Nothing half-typed should be lost because somebody navigated away.
    useEffect(
        () => () => {
            if (pending.current !== null) {
                clearTimeout(pending.current);
            }
        },
        [],
    );

    /*
     * What is left to do, counted here rather than on the server so it changes
     * as somebody types, and counted once rather than in both bars that show it.
     * The server counts again when the button is pressed, and that count is the
     * one that decides: this is a hint, not a rule.
     */
    const outstanding = contract.fields.filter((field) => {
        if (!field.isRequired) {
            return false;
        }

        if (field.type === 'signature' || field.type === 'initials') {
            return contract.marks[field.type] == null;
        }

        return (values[field.id] ?? '') === '';
    }).length;

    /*
     * The huisstijl rather than the application's own look, and rather than the
     * accent of a workspace this person very likely does not belong to. Every
     * screen somebody meets from outside wears it — see AuthSimpleLayout, where
     * the same class does the same job for logging in, downloads and secrets.
     * Worn here rather than inherited from a shell, because this page wants no
     * shell: a centred card is the wrong shape for a document.
     *
     * On paper, always, and that is why `pd-themed` is deliberately absent: it
     * is the opt-in to the dark palette, and this page is a white PDF from edge
     * to edge whatever anybody chose. Dark chrome around a page that cannot go
     * dark is not a dark screen, it is a bright rectangle in a frame — and this
     * is the one screen where somebody has to read every word before they agree
     * to it. `scheme-light` says the same thing to the browser itself, so the
     * date fields, scrollbars and autofill on top of the document stay on
     * paper too.
     */
    return (
        <div className="postduif flex min-h-dvh flex-col bg-muted/40 scheme-light">
            <Head title={contract.title} />

            {/*
                One bar, two lines, and everything on them is about this one
                document: whose name it is in, what was sent along with it, and
                whether the work so far is safe. It stays put while the pages go
                past, because "voor wie is dit" is a question that comes back on
                page nine.
            */}
            <header className="sticky top-0 z-10 border-b bg-background/95 backdrop-blur">
                <div className="mx-auto flex max-w-4xl items-center gap-3 px-4 py-2.5">
                    <FileSignature className="size-4 shrink-0 text-muted-foreground" />

                    <div className="min-w-0 flex-1">
                        <h1 className="truncate text-sm font-semibold">
                            {contract.title}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            {t('contracts.sign.addressed_to', {
                                name: contract.signerName,
                            })}
                        </p>
                    </div>

                    {/*
                        The long sentence about autosaving is the title rather
                        than the text. It is worth reading once and worth having
                        within reach after that, but it is not worth a line of
                        its own above a contract on a telephone.
                    */}
                    <p
                        className="hidden shrink-0 text-xs text-muted-foreground sm:block"
                        title={t('contracts.sign.autosaves')}
                        aria-live="polite"
                        data-testid="contract-sign-status"
                    >
                        {saved
                            ? t('contracts.sign.saved')
                            : t('contracts.sign.autosave_short')}
                    </p>
                </div>
            </header>

            <main className="flex-1 px-4 py-6">
                <div ref={column} className="mx-auto max-w-4xl">
                    {/*
                        What the sender wrote alongside it, above the first page
                        and scrolling away with it rather than pinned to the bar.
                        It is often the sentence that says which of two versions
                        this is, so it cannot be cut off at the width of a
                        telephone — and once it has been read it should not go on
                        taking up the top of the screen for twenty pages.
                    */}
                    {contract.message !== null && (
                        <p className="mb-4 border-l-2 pl-3 text-sm text-muted-foreground">
                            {contract.message}
                        </p>
                    )}

                    {/*
                        Said in words as well as shown on the page. Somebody
                        opening a twenty-page contract on a telephone may not
                        reach the signature at the foot of page twenty before
                        they decide, and "er hebben er al twee getekend" is the
                        sort of thing that belongs above the document rather
                        than being left to be discovered.
                    */}
                    {contract.signedCount > 0 && (
                        <p
                            className="mb-4 flex items-center gap-2 rounded-md border border-emerald-600/30 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                            data-testid="contract-signed-before"
                        >
                            <CheckCircle2 className="size-4 shrink-0" />
                            {tChoice(
                                'contracts.sign.signed_before',
                                contract.signedCount,
                                { total: contract.signerCount },
                            )}
                        </p>
                    )}

                    <ContractDocument
                        url={documentUrl}
                        pageCount={contract.pageCount}
                        pageWidth={pageWidth}
                        overlay={(page, size) => (
                            <div className="absolute inset-0">
                                {/*
                                    What the people before this person put down,
                                    drawn first so anything of this person's own
                                    that happens to overlap sits on top of it. A
                                    contract that arrives with two signatures
                                    already on it reads as a contract two people
                                    have agreed to; the same document blank
                                    reads as a draft, and that is the difference
                                    somebody is weighing when they decide
                                    whether to sign.
                                */}
                                {contract.filled
                                    .filter((field) => field.page === page)
                                    .map((field, index) => (
                                        <FilledBox
                                            key={`${field.id}-${index}`}
                                            field={field}
                                            page={size}
                                        />
                                    ))}

                                {contract.fields
                                    .filter((field) => field.page === page)
                                    .map((field) => (
                                        <SignableBox
                                            key={field.id}
                                            field={field}
                                            page={size}
                                            value={values[field.id] ?? ''}
                                            mark={
                                                contract.marks[field.type] ??
                                                null
                                            }
                                            onChange={(value) =>
                                                change(field.id, value)
                                            }
                                            onMark={() =>
                                                setMarking(field.type)
                                            }
                                        />
                                    ))}
                            </div>
                        )}
                    />
                </div>
            </main>

            <SigningFooter token={token} outstanding={outstanding} />

            <Dialog
                open={marking !== null}
                onOpenChange={(open) => !open && setMarking(null)}
            >
                {/*
                    Dressed again here, and not by accident: a dialog is
                    rendered through a portal on the body, so it falls outside
                    the page's own div and would otherwise come up in the
                    application's palette — dark, in front of a sheet of paper,
                    at the moment somebody is asked for their signature.
                */}
                <DialogContent className="postduif scheme-light sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {marking === 'initials'
                                ? t('contracts.signature.title_initials')
                                : t('contracts.signature.title_signature')}
                        </DialogTitle>
                        <DialogDescription>
                            {marking === 'initials'
                                ? t('contracts.signature.hint_initials')
                                : t('contracts.signature.hint_signature')}
                        </DialogDescription>
                    </DialogHeader>

                    <SignaturePad
                        suggestedName={contract.signerName}
                        busy={uploading}
                        onDone={putDownMark}
                    />
                </DialogContent>
            </Dialog>
        </div>
    );
}

/**
 * The two endings, and what stands between somebody and them.
 *
 * Fixed to the foot of the screen rather than at the bottom of the document,
 * because a twenty-page contract would otherwise hide the button that finishes
 * it behind twenty pages of scrolling — and somebody who cannot find it assumes
 * their work was not saved.
 *
 * How much is left is worked out by the surface above and handed down, so the
 * line here and the disabled button below it can never tell two stories.
 */
function SigningFooter({
    token,
    outstanding,
}: {
    token: string;
    outstanding: number;
}) {
    const { t, tChoice } = useTranslate();

    const [declining, setDeclining] = useState(false);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    /*
     * Errors from the two endings arrive on the page rather than in a toast: a
     * refusal to sign is something to read and act on, and a message that
     * disappears after four seconds is the wrong shape for "je hebt nog twee
     * vakken open".
     */
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    const sign = () => {
        setBusy(true);

        router.post(
            completeSigning.url(token),
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const turnDown = () => {
        setBusy(true);

        router.post(
            declineSigning.url(token),
            { reason },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setDeclining(false);
                },
            },
        );
    };

    return (
        <>
            {errors.signing !== undefined && (
                <div className="mx-auto w-full max-w-4xl px-4">
                    <p
                        role="alert"
                        data-testid="contract-sign-error"
                        className="mb-3 rounded-md border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                    >
                        {errors.signing}
                    </p>
                </div>
            )}

            <div className="sticky bottom-0 border-t bg-background/95 backdrop-blur">
                <div className="mx-auto flex max-w-4xl items-center gap-3 px-4 py-3">
                    <p className="min-w-0 truncate text-xs text-muted-foreground">
                        {tChoice('contracts.sign.remaining', outstanding)}
                    </p>

                    <div className="ml-auto flex shrink-0 items-center gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setDeclining(true)}
                            disabled={busy}
                        >
                            {t('contracts.sign.decline')}
                        </Button>

                        {/*
                        Disabled while something is still open, and the server
                        checks again regardless — see SignContract. Both, because
                        the button is what stops somebody wasting a click, and
                        the server is what stops a contract being signed half
                        empty.
                    */}
                        <Button
                            type="button"
                            onClick={sign}
                            disabled={busy || outstanding > 0}
                            data-testid="contract-sign-submit"
                        >
                            {t('contracts.sign.sign')}
                        </Button>
                    </div>
                </div>
            </div>

            <Dialog open={declining} onOpenChange={setDeclining}>
                {/* On paper as well — see the signature dialog. */}
                <DialogContent className="postduif scheme-light">
                    <DialogHeader>
                        <DialogTitle>
                            {t('contracts.sign.decline_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('contracts.sign.decline_hint')}
                        </DialogDescription>
                    </DialogHeader>

                    <label className="space-y-1 text-sm">
                        <span className="text-muted-foreground">
                            {t('contracts.sign.decline_reason')}
                        </span>
                        <textarea
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            maxLength={1000}
                            rows={3}
                            className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                        />
                    </label>

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setDeclining(false)}
                            disabled={busy}
                        >
                            {t('contracts.sign.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={turnDown}
                            disabled={busy}
                            data-testid="contract-decline-submit"
                        >
                            {t('contracts.sign.decline_confirm')}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

/**
 * One box, filled in in place on the page.
 *
 * In place rather than as a list of questions beside the document, and that is
 * the whole argument for this feature existing: somebody signing a contract has
 * to be able to see what the box is under. A form beside the page would be a
 * form, and they already had one of those.
 */
function SignableBox({
    field,
    page,
    value,
    mark,
    onChange,
    onMark,
}: {
    field: SignableField;
    page: RenderedPage;
    value: string;
    /** This person's mark of this kind, or null while they have none. */
    mark: string | null;
    onChange: (value: string) => void;
    onMark: () => void;
}) {
    const { t } = useTranslate();

    const pixels = toPixels(field, page);

    const style = {
        left: pixels.left,
        top: pixels.top,
        width: pixels.width,
        height: pixels.height,
    };

    /*
     * Signatures and initials are drawn rather than typed, so the box is a
     * button that opens the pad — never a text field, which would invite
     * somebody to type their name into the page and believe they had signed.
     *
     * Once a mark exists it is shown here and in every other box of the same
     * kind, and tapping again reopens the pad to replace it. That is the whole
     * of "hergebruik de laatst gezette handtekening": nine initials boxes are
     * one decision, not nine.
     */
    if (field.type === 'signature' || field.type === 'initials') {
        return (
            <button
                type="button"
                style={style}
                onClick={onMark}
                aria-label={field.label}
                data-testid="contract-sign-field"
                data-field-type={field.type}
                className={cn(
                    'absolute flex items-center justify-center rounded-sm border-2 text-[10px]',
                    mark === null
                        ? 'border-dashed border-primary/50 bg-primary/5 text-primary hover:bg-primary/10'
                        : 'border-transparent hover:border-primary/40',
                )}
            >
                {mark === null ? (
                    t('contracts.sign.signature_pending')
                ) : (
                    <img
                        src={mark}
                        alt={field.label}
                        className="max-h-full max-w-full object-contain"
                    />
                )}
            </button>
        );
    }

    if (field.type === 'checkbox') {
        return (
            <div
                style={style}
                data-testid="contract-sign-field"
                data-field-type={field.type}
                className="absolute flex items-center justify-center"
            >
                <Checkbox
                    aria-label={field.label}
                    checked={value !== ''}
                    onCheckedChange={(checked) =>
                        onChange(checked === true ? '1' : '')
                    }
                />
            </div>
        );
    }

    const shared = {
        'aria-label': field.label,
        value,
        placeholder: field.label,
        'data-testid': 'contract-sign-field',
        'data-field-type': field.type,
        className: cn(
            'absolute rounded-sm border-2 border-primary/50 bg-background/90 px-1 text-sm',
            field.isRequired && value === '' && 'border-destructive/60',
        ),
        style,
    };

    if (field.type === 'multiline') {
        return (
            <textarea
                {...shared}
                onChange={(event) => onChange(event.target.value)}
            />
        );
    }

    return (
        <Input
            {...shared}
            type={field.type === 'date' ? 'date' : 'text'}
            onChange={(event) => onChange(event.target.value)}
        />
    );
}

/**
 * One box somebody else has already filled in, as it now reads.
 *
 * Nothing here can be clicked, typed into or tabbed to: it is not this person's
 * box, and a page that let them into it would be offering an edit to a
 * signature that has already been given. `pointer-events-none` is what keeps a
 * finger aiming at a nearby field of their own from landing here instead.
 *
 * Drawn as ink on the page rather than as a filled-in form control — no border,
 * no background. A framed grey box would say "hier moet nog iets"; what these
 * are saying is the opposite.
 */
function FilledBox({
    field,
    page,
}: {
    field: FilledField;
    page: RenderedPage;
}) {
    const { t } = useTranslate();

    const pixels = toPixels(field, page);

    const style = {
        left: pixels.left,
        top: pixels.top,
        width: pixels.width,
        height: pixels.height,
    };

    const shared = {
        style,
        title: t('contracts.sign.filled_by_other'),
        'data-testid': 'contract-filled-field',
        'data-field-type': field.type,
    };

    if (field.mark !== null) {
        return (
            <div
                {...shared}
                className="pointer-events-none absolute flex items-center justify-center"
            >
                <img
                    src={field.mark}
                    alt={t('contracts.sign.filled_by_other')}
                    className="max-h-full max-w-full object-contain"
                />
            </div>
        );
    }

    if (field.type === 'checkbox') {
        return (
            <div
                {...shared}
                className="pointer-events-none absolute flex items-center justify-center"
                aria-label={t('contracts.sign.filled_by_other')}
            >
                <Check className="size-full text-foreground" />
            </div>
        );
    }

    return (
        <p
            {...shared}
            className={cn(
                'pointer-events-none absolute overflow-hidden px-1 text-sm',
                field.type === 'multiline' ? 'whitespace-pre-wrap' : 'truncate',
            )}
        >
            {field.type === 'date' ? asDate(field.value) : field.value}
        </p>
    );
}

/**
 * A stored date in the shape a person reads it, and anything else untouched.
 *
 * The column holds Y-m-d because that is what sorts and what validates; d-m-Y
 * is what this contract will say on paper once it is rendered — see
 * RenderSignedContract. Showing the stored shape here would mean the box reads
 * differently before and after signing.
 */
function asDate(value: string | null): string {
    if (value === null) {
        return '';
    }

    const parts = value.split('-');

    return parts.length === 3 ? `${parts[2]}-${parts[1]}-${parts[0]}` : value;
}

/**
 * What somebody sees when the link no longer leads anywhere.
 *
 * Four separate messages rather than one, because they lead to four different
 * next steps: ask for a new link, ring the person who withdrew it, do nothing
 * at all, or simply be reassured that it went through. Collapsing them into
 * "deze link werkt niet" is what makes people telephone.
 *
 * On paper too, and for the same reason as the screen it replaces: this is the
 * same address, reached by the same person, often on the same day.
 */
function ClosedScreen({
    state,
    contract,
}: {
    state: Exclude<SignState, 'signing'>;
    contract: SignableContract;
}) {
    const { t } = useTranslate();

    const screens: Record<
        Exclude<SignState, 'signing'>,
        { icon: typeof CheckCircle2; tone: string; key: string }
    > = {
        signed: {
            icon: CheckCircle2,
            tone: 'text-emerald-600',
            key: 'signed',
        },
        completed: {
            icon: CheckCircle2,
            tone: 'text-emerald-600',
            key: 'completed',
        },
        declined: { icon: Ban, tone: 'text-muted-foreground', key: 'declined' },
        expired: {
            icon: CalendarX,
            tone: 'text-muted-foreground',
            key: 'expired',
        },
        cancelled: { icon: Ban, tone: 'text-destructive', key: 'cancelled' },
    };

    const screen = screens[state];
    const Icon = screen.icon;

    return (
        <div className="postduif flex min-h-dvh items-center justify-center bg-muted/40 px-4 scheme-light">
            <Head title={contract.title} />

            <div className="w-full max-w-md space-y-4 rounded-lg border bg-background p-8 text-center">
                <Icon className={cn('mx-auto size-10', screen.tone)} />

                <h1 className="text-lg font-semibold">
                    {t(
                        `contracts.sign.closed.${screen.key}.title` as TranslationKey,
                    )}
                </h1>

                <p className="text-sm text-muted-foreground">
                    {t(
                        `contracts.sign.closed.${screen.key}.body` as TranslationKey,
                    )}
                </p>

                <p className="flex items-center justify-center gap-2 border-t pt-4 text-xs text-muted-foreground">
                    <FileSignature className="size-3.5" />
                    {contract.title}
                </p>
            </div>
        </div>
    );
}
