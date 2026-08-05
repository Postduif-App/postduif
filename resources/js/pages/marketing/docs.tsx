import { Head } from '@inertiajs/react';

import {
    Card,
    CardLabel,
    DescribedList,
    Note,
    SectionHead,
} from '@/components/marketing/prose';

/** One parameter a call takes. */
interface Param {
    name: string;
    /** Verplicht, optioneel, and whatever ceiling the validator holds to. */
    rule: string;
    about: string;
}

/**
 * One endpoint.
 *
 * Method, path and limiter are read off the router and the rate limiters by
 * BuildApiReference; the prose beside them is written. Nothing here is typed
 * out twice, so nothing here can drift from what the application answers to.
 */
interface Endpoint {
    name: string;
    method: string;
    path: string;
    summary: string;
    /** Which key opens it: a personal token, or a webhook URL. */
    auth: 'token' | 'webhook';
    /** Which limiter guards it, keyed into `limits`. */
    limiter: string;
    params?: Param[];
    returns?: string;
}

interface DocsProps {
    endpoints: Endpoint[];
    limits: Record<string, { perMinute: number }>;
    tools: { name: string; description: string }[];
}

/** The method, in mono, wide enough that the paths beside them line up. */
function Method({ method }: { method: string }) {
    return (
        <span
            style={{
                fontFamily: 'var(--pd-mono)',
                fontSize: 12,
                fontWeight: 600,
                color: 'var(--pd-inkt)',
                background: 'var(--pd-geel)',
                padding: '3px 8px',
                borderRadius: 4,
                display: 'inline-block',
            }}
        >
            {method}
        </span>
    );
}

function EndpointCard({
    endpoint,
    perMinute,
}: {
    endpoint: Endpoint;
    perMinute?: number;
}) {
    return (
        <Card>
            <div className="mb-3 flex flex-wrap items-center gap-3">
                <Method method={endpoint.method} />
                <code
                    style={{
                        fontFamily: 'var(--pd-mono)',
                        fontSize: 14,
                        wordBreak: 'break-all',
                    }}
                >
                    {endpoint.path}
                </code>
            </div>

            <p
                className="m-0 max-w-[68ch]"
                style={{
                    fontSize: 15,
                    lineHeight: 1.6,
                    color: 'var(--pd-steen)',
                }}
            >
                {endpoint.summary}
            </p>

            {endpoint.params && endpoint.params.length > 0 && (
                <div className="mt-5">
                    <CardLabel>Wat het wil</CardLabel>
                    <DescribedList
                        items={endpoint.params.map((param) => ({
                            label: `${param.name} · ${param.rule}`,
                            description: param.about,
                        }))}
                    />
                </div>
            )}

            {endpoint.returns && (
                <div className="mt-5">
                    <CardLabel>Wat het teruggeeft</CardLabel>
                    <code
                        style={{
                            fontFamily: 'var(--pd-mono)',
                            fontSize: 13,
                            color: 'var(--pd-steen)',
                        }}
                    >
                        {endpoint.returns}
                    </code>
                </div>
            )}

            {perMinute !== undefined && (
                <p
                    className="m-0 mt-5"
                    style={{
                        fontFamily: 'var(--pd-mono)',
                        fontSize: 12,
                        color: '#8b8a7b',
                    }}
                >
                    Hoogstens {perMinute} per minuut
                </p>
            )}
        </Card>
    );
}

/**
 * The API reference.
 *
 * Every method, path and ceiling on this page was read off the router and the
 * rate limiters when the page was rendered — see BuildApiReference, and see
 * MarketingController for why the public pages are built that way. An endpoint
 * added without a line written about it fails a test rather than quietly
 * missing from here.
 *
 * Written for somebody with a terminal open. That is why the examples are curl
 * and not a client library: there is no official one, and inventing a snippet
 * in a language nobody ships would be the friendliest possible way to waste an
 * afternoon.
 */
export default function MarketingDocs({ endpoints, limits, tools }: DocsProps) {
    const tokenEndpoints = endpoints.filter(
        (endpoint) => endpoint.auth === 'token',
    );
    const webhookEndpoints = endpoints.filter(
        (endpoint) => endpoint.auth === 'webhook',
    );

    return (
        <>
            <Head title="API" />

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <h1
                    className="m-0 mb-7 max-w-[18ch] text-[40px] sm:text-[64px]"
                    style={{
                        fontFamily: 'var(--pd-mono)',
                        fontWeight: 600,
                        lineHeight: 1,
                        letterSpacing: '-0.045em',
                        textWrap: 'balance',
                    }}
                >
                    De API
                </h1>

                <p
                    className="m-0 max-w-[62ch]"
                    style={{
                        fontSize: 19,
                        lineHeight: 1.6,
                        color: 'var(--pd-steen)',
                        textWrap: 'pretty',
                    }}
                >
                    Klein en met opzet klein gehouden. Elke aanroep loopt langs
                    dezelfde regels als het scherm: wat jij niet mag zien, geeft
                    dit ook niet terug, en een bericht dat hier binnenkomt gaat
                    door dezelfde actie als een bericht dat je typt.
                </p>
            </div>

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="01"
                    title="Aanmelden"
                    lead="Een persoonlijk token hoort bij jou, niet bij een workspace. Je maakt er een bij Instellingen → API-tokens, en je ziet hem één keer."
                />

                <Card>
                    <CardLabel>De header</CardLabel>
                    <pre
                        className="m-0 overflow-x-auto"
                        style={{
                            fontFamily: 'var(--pd-mono)',
                            fontSize: 13,
                            lineHeight: 1.7,
                        }}
                    >
                        {`curl https://<jouw-postduif>/api/v1/status \\
  -H "Authorization: Bearer <je-token>"`}
                    </pre>
                </Card>

                <Note>
                    Elke mislukking geeft hetzelfde antwoord: 401, zonder te
                    zeggen of het token niet bestaat, is ingetrokken of bij een
                    verwijderd account hoort. Dat is met opzet — het verschil
                    vertellen is iemand vertellen welke gok dichterbij was.
                </Note>
            </div>

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="02"
                    title="Met je eigen token"
                    lead="Alles hieronder gaat over de member wiens token je stuurt. Daarom staat er nergens een id in het pad: er is geen manier om bij iemand anders uit te komen."
                />

                <div className="grid grid-cols-1 gap-6">
                    {tokenEndpoints.map((endpoint) => (
                        <EndpointCard
                            key={endpoint.name}
                            endpoint={endpoint}
                            perMinute={limits[endpoint.limiter]?.perMinute}
                        />
                    ))}
                </div>

                <Note>
                    Een workspace laat standaard niets met een token binnen.
                    Zolang die schakelaar uit staat, geeft de kanalenlijst er
                    niets uit terug en antwoordt posten er met 404 — hetzelfde
                    antwoord als een kanaal dat niet bestaat, want het verschil
                    zou verklappen wat er wél is.
                </Note>
            </div>

            {webhookEndpoints.length > 0 && (
                <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                    <SectionHead
                        number="03"
                        title="Zonder token van een persoon"
                        lead="Een webhook draagt zijn sleutel in het pad, want dat is wat de tools die erop wijzen verwachten. Hij komt dus in logs terecht — en dat is precies waarom hij in te trekken en opnieuw te maken is."
                    />

                    <div className="grid grid-cols-1 gap-6">
                        {webhookEndpoints.map((endpoint) => (
                            <EndpointCard
                                key={endpoint.name}
                                endpoint={endpoint}
                                perMinute={limits[endpoint.limiter]?.perMinute}
                            />
                        ))}
                    </div>
                </div>
            )}

            {tools.length > 0 && (
                <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                    <SectionHead
                        number="04"
                        title="Voor een AI-client"
                        lead="Een AI-client meldt zich met OAuth aan en vraagt jou om toestemming. Dit is wat hij daarna kan — dezelfde regels, dezelfde grenzen."
                    />

                    <Card>
                        <CardLabel>De tools</CardLabel>
                        <DescribedList
                            items={tools.map((tool) => ({
                                label: tool.name,
                                description: tool.description,
                            }))}
                        />
                    </Card>
                </div>
            )}
        </>
    );
}
