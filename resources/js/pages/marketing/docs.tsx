import { Head } from '@inertiajs/react';

import {
    Card,
    CardLabel,
    DescribedList,
    Note,
    SectionHead,
} from '@/components/marketing/prose';
import { useTranslate } from '@/hooks/use-translate';

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
    auth: 'token' | 'contract-token' | 'webhook';
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
    const { t, tChoice } = useTranslate();

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
                    <CardLabel>{t('marketing.docs.wants')}</CardLabel>
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
                    <CardLabel>{t('marketing.docs.returns')}</CardLabel>
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
                    {tChoice('marketing.docs.rate_limit', perMinute)}
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
    const { t } = useTranslate();

    const tokenEndpoints = endpoints.filter(
        (endpoint) => endpoint.auth === 'token',
    );
    /*
     * A section of their own rather than among the token endpoints, because
     * they ask for a different credential: a token tied to one workspace and
     * carrying the contracts scope. Somebody who tried their ordinary token
     * here would be refused, and a page that put the two side by side without
     * saying so would be the reason why.
     */
    const contractEndpoints = endpoints.filter(
        (endpoint) => endpoint.auth === 'contract-token',
    );
    const webhookEndpoints = endpoints.filter(
        (endpoint) => endpoint.auth === 'webhook',
    );

    return (
        <>
            <Head title={t('marketing.docs.head')} />

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
                    {t('marketing.docs.title')}
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
                    {t('marketing.docs.intro')}
                </p>
            </div>

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="01"
                    title={t('marketing.docs.auth.title')}
                    lead={t('marketing.docs.auth.lead')}
                />

                <Card>
                    <CardLabel>{t('marketing.docs.auth.header')}</CardLabel>
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

                <Note>{t('marketing.docs.auth.note')}</Note>
            </div>

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="02"
                    title={t('marketing.docs.token.title')}
                    lead={t('marketing.docs.token.lead')}
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

                <Note>{t('marketing.docs.token.note')}</Note>
            </div>

            {contractEndpoints.length > 0 && (
                <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                    <SectionHead
                        number="03"
                        title={t('marketing.docs.contracts.title')}
                        lead={t('marketing.docs.contracts.lead')}
                    />

                    <div className="grid grid-cols-1 gap-6">
                        {contractEndpoints.map((endpoint) => (
                            <EndpointCard
                                key={endpoint.name}
                                endpoint={endpoint}
                                perMinute={limits[endpoint.limiter]?.perMinute}
                            />
                        ))}
                    </div>

                    <Card>
                        <CardLabel>
                            {t('marketing.docs.contracts.callback')}
                        </CardLabel>
                        <pre
                            className="m-0 overflow-x-auto"
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 13,
                                lineHeight: 1.7,
                            }}
                        >
                            {`POST <jouw-callback-url>
X-Postduif-Event: signed
X-Postduif-Signature: sha256=<hex>

{
  "event": "signed",
  "occurredAt": "2026-08-13T09:12:44+00:00",
  "contract": { "id": "01K…", "title": "Huurovereenkomst", "status": "sent", "completedAt": null },
  "signers": [ { "name": "Anna de Vries", "email": "anna@example.com", "signedAt": "2026-08-13T09:12:44+00:00", "declinedAt": null, "declineReason": null } ],
  "documentUrl": null
}`}
                        </pre>
                    </Card>

                    <Card>
                        <CardLabel>
                            {t('marketing.docs.contracts.verify')}
                        </CardLabel>
                        <pre
                            className="m-0 overflow-x-auto"
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 13,
                                lineHeight: 1.7,
                            }}
                        >
                            {`$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

if (! hash_equals($expected, $request->header('X-Postduif-Signature'))) {
    abort(401);
}`}
                        </pre>
                    </Card>

                    <Note>{t('marketing.docs.contracts.note')}</Note>
                </div>
            )}

            {webhookEndpoints.length > 0 && (
                <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                    <SectionHead
                        number="04"
                        title={t('marketing.docs.webhooks.title')}
                        lead={t('marketing.docs.webhooks.lead')}
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
                        number="05"
                        title={t('marketing.docs.ai.title')}
                        lead={t('marketing.docs.ai.lead')}
                    />

                    <Card>
                        <CardLabel>{t('marketing.docs.ai.tools')}</CardLabel>
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
