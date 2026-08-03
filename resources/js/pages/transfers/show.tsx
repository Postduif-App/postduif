import { Form, Head } from '@inertiajs/react';
import { Download, FileDown, Package } from 'lucide-react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type State = 'usable' | 'expired' | 'revoked' | 'exhausted';

interface TransferFile {
    id: number;
    name: string;
    size: number;
    url: string;
}

interface TransferShowProps {
    transfer: {
        title: string | null;
        message: string | null;
        senderName: string | null;
        workspaceName: string;
        expiresAt: string;
        downloadsLeft: number | null;
        /** True while the password has not been answered in this browser. */
        isLocked: boolean;
        unlockUrl: string;
        state: State;
        files: TransferFile[];
        downloadAllUrl: string | null;
    };
}

/**
 * Each reason gets its own words, which is why the server sends one of three
 * rather than a bare "no". The advice differs: a link that ran out may be worth
 * asking about again, a withdrawn one probably was withdrawn on purpose.
 */
const DEAD_END: Record<
    Exclude<State, 'usable'>,
    { title: string; body: string }
> = {
    expired: {
        title: 'Deze bestanden zijn verlopen',
        body: 'Een downloadlink is een beperkte tijd geldig; daarna worden de bestanden opgeruimd. Vraag de afzender om ze opnieuw te versturen.',
    },
    revoked: {
        title: 'Deze verzending is ingetrokken',
        body: 'De afzender heeft de link ingetrokken. Neem contact op als je de bestanden nog nodig hebt.',
    },
    exhausted: {
        title: 'Deze link is opgebruikt',
        body: 'De link mocht een beperkt aantal keer gebruikt worden, en dat aantal is bereikt. Vraag de afzender om een nieuwe.',
    },
};

/**
 * Sizes in the units people read them in, with a decimal only where it says
 * something — "1,4 GB" is useful, "1,4 kB" is noise.
 */
function humanSize(bytes: number): string {
    const units = ['B', 'kB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(unit >= 2 && value < 100 ? 1 : 0).replace('.', ',')} ${units[unit]}`;
}

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString('nl-NL', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

export default function TransferShow({ transfer }: TransferShowProps) {
    if (transfer.state !== 'usable') {
        const message = DEAD_END[transfer.state];

        return (
            <>
                <Head title="Bestanden" />
                <div className="space-y-3 text-center">
                    <h2 className="text-lg font-medium">{message.title}</h2>
                    <p className="text-sm text-muted-foreground">
                        {message.body}
                    </p>
                </div>
            </>
        );
    }

    /*
     * The lock is handled before the file list rather than beside it: what the
     * visitor needs here is one field and nothing else, and showing file names
     * above a password box would be giving away the part that is being
     * protected.
     */
    if (transfer.isLocked) {
        return (
            <>
                <Head title="Bestanden" />
                <div className="flex flex-col gap-6">
                    <div className="space-y-1 text-center">
                        <p className="text-sm text-muted-foreground">
                            {transfer.senderName
                                ? `${transfer.senderName} stuurde je bestanden`
                                : 'Er staan bestanden voor je klaar'}
                        </p>
                        <p className="text-lg font-medium">
                            Deze verzending heeft een wachtwoord
                        </p>
                    </div>

                    <Form
                        action={transfer.unlockUrl}
                        method="post"
                        disableWhileProcessing
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="password">Wachtwoord</Label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        required
                                        autoFocus
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <Button type="submit" className="w-full">
                                    {processing && <Spinner />}
                                    Bestanden bekijken
                                </Button>
                            </>
                        )}
                    </Form>

                    <p className="text-center text-xs text-muted-foreground">
                        De afzender heeft je het wachtwoord apart gestuurd —
                        niet in dezelfde mail als deze link, want dan zou het
                        geen tweede slot zijn.
                    </p>
                </div>
            </>
        );
    }

    const total = transfer.files.reduce((sum, file) => sum + file.size, 0);

    return (
        <>
            <Head title={transfer.title ?? 'Bestanden'} />

            <div className="flex flex-col gap-6">
                <div className="space-y-1 text-center">
                    <p className="text-sm text-muted-foreground">
                        {transfer.senderName
                            ? `${transfer.senderName} stuurde je`
                            : 'Er staat iets voor je klaar'}
                    </p>
                    <p className="text-lg font-medium">
                        {transfer.title ??
                            `${transfer.files.length} ${transfer.files.length === 1 ? 'bestand' : 'bestanden'}`}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        via {transfer.workspaceName}
                    </p>
                </div>

                {transfer.message && (
                    <p className="rounded-lg border bg-muted/40 p-3 text-sm whitespace-pre-line">
                        {transfer.message}
                    </p>
                )}

                <ul className="divide-y rounded-lg border">
                    {transfer.files.map((file) => (
                        <li
                            key={file.id}
                            className="flex items-center gap-3 p-3 text-sm"
                        >
                            <FileDown className="size-4 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 flex-1 truncate">
                                {file.name}
                            </span>
                            <span className="shrink-0 text-xs text-muted-foreground">
                                {humanSize(file.size)}
                            </span>
                            {/*
                                A plain anchor rather than an Inertia Link: the
                                response is a file, not a page, and asking
                                Inertia to navigate to it would leave the visit
                                hanging on a response it cannot render.
                            */}
                            <a
                                href={file.url}
                                className="shrink-0 rounded p-1.5 hover:bg-muted"
                                aria-label={`${file.name} downloaden`}
                            >
                                <Download className="size-4" />
                            </a>
                        </li>
                    ))}
                </ul>

                {transfer.downloadAllUrl && transfer.files.length > 1 && (
                    <Button asChild className="w-full">
                        <a href={transfer.downloadAllUrl}>
                            <Package className="size-4" />
                            Alles downloaden ({humanSize(total)})
                        </a>
                    </Button>
                )}

                <div className="space-y-1 text-center text-xs text-muted-foreground">
                    <p>Beschikbaar tot {formatDate(transfer.expiresAt)}</p>
                    {/*
                        Said out loud because the counting is not obvious:
                        fetching the files one by one costs one download each,
                        while the archive costs one for the lot. Somebody who
                        knows that can choose; somebody who does not runs out
                        halfway through and has no idea why.
                    */}
                    {transfer.downloadsLeft !== null && (
                        <p>
                            Nog {transfer.downloadsLeft}{' '}
                            {transfer.downloadsLeft === 1
                                ? 'download'
                                : 'downloads'}{' '}
                            beschikbaar. Alles in één keer downloaden telt als
                            één.
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}

TransferShow.layout = {
    title: 'Bestanden voor jou',
    description: 'Klaargezet om te downloaden',
};
