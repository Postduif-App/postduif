import { Head, router } from '@inertiajs/react';
import { Ban, CalendarX, CheckCircle2, FileSignature } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { ContractDocument } from '@/components/chat/contract-document';
import { SignaturePad } from '@/components/chat/signature-pad';
import type { SignatureMethod } from '@/components/chat/signature-pad';
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
    const { t } = useTranslate();

    if (state !== 'signing') {
        return <ClosedScreen state={state} contract={contract} />;
    }

    return (
        <div className="min-h-dvh bg-muted/40">
            <Head title={contract.title} />

            <header className="sticky top-0 z-10 border-b bg-background/95 backdrop-blur">
                <div className="mx-auto flex max-w-4xl flex-col gap-1 px-4 py-3">
                    <h1 className="text-base font-semibold">
                        {contract.title}
                    </h1>
                    <p className="text-xs text-muted-foreground">
                        {t('contracts.sign.addressed_to', {
                            name: contract.signerName,
                        })}
                    </p>
                </div>
            </header>

            <main className="mx-auto max-w-4xl px-4 py-6">
                {contract.message && (
                    <blockquote className="mb-6 border-l-2 pl-4 text-sm text-muted-foreground">
                        {contract.message}
                    </blockquote>
                )}

                <SigningSurface
                    token={token}
                    contract={contract}
                    documentUrl={documentUrl}
                />
            </main>
        </div>
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
    const { t } = useTranslate();

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

    return (
        <div ref={column} className="space-y-4">
            <p
                className="text-xs text-muted-foreground"
                aria-live="polite"
                data-testid="contract-sign-status"
            >
                {saved
                    ? t('contracts.sign.saved')
                    : t('contracts.sign.autosaves')}
            </p>

            <ContractDocument
                url={documentUrl}
                pageCount={contract.pageCount}
                pageWidth={pageWidth}
                overlay={(page, size) => (
                    <div className="absolute inset-0">
                        {contract.fields
                            .filter((field) => field.page === page)
                            .map((field) => (
                                <SignableBox
                                    key={field.id}
                                    field={field}
                                    page={size}
                                    value={values[field.id] ?? ''}
                                    mark={contract.marks[field.type] ?? null}
                                    onChange={(value) =>
                                        change(field.id, value)
                                    }
                                    onMark={() => setMarking(field.type)}
                                />
                            ))}
                    </div>
                )}
            />

            <Dialog
                open={marking !== null}
                onOpenChange={(open) => !open && setMarking(null)}
            >
                <DialogContent className="sm:max-w-lg">
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
 * What somebody sees when the link no longer leads anywhere.
 *
 * Four separate messages rather than one, because they lead to four different
 * next steps: ask for a new link, ring the person who withdrew it, do nothing
 * at all, or simply be reassured that it went through. Collapsing them into
 * "deze link werkt niet" is what makes people telephone.
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
        <div className="flex min-h-dvh items-center justify-center bg-muted/40 px-4">
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
