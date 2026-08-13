import { useTranslate } from '@/hooks/use-translate';

/**
 * Het product, geschetst.
 *
 * Een landingspagina die alleen uit tekst bestaat vraagt van een bezoeker dat
 * hij zich voorstelt waar hij het over heeft. Dit is de kortste manier om die
 * vraag te beantwoorden: een kanaal, een bericht, en het ticket dat eruit
 * volgt — wat precies de zin is die in de kop staat.
 *
 * Nadrukkelijk een schets en geen schermafdruk. Een namaakscherm dat er echt
 * uit probeert te zien, belooft een interface die morgen anders is; dit belooft
 * alleen het idee, en het is opgebouwd uit dezelfde randen, radii en mono die
 * de rest van de pagina gebruikt. De namen erin zijn verzonnen en zien er ook
 * zo uit.
 *
 * Alle woorden komen uit lang/, want dit is copy en de site antwoordt in de
 * taal van de lezer — een Engelse bezoeker met een Nederlands schermpje ernaast
 * is precies de halve vertaling die de rest van deze site vermijdt.
 */
export function ChannelSketch() {
    const { t } = useTranslate();

    return (
        <div
            className="w-full"
            style={{
                background: 'var(--pd-wit)',
                /*
                 * Uitdrukkelijk inkt, en niet geërfd.
                 *
                 * Deze schets hangt in de hero, en die zet color op papier voor
                 * alles eronder. Een wit vlak dat zijn tekstkleur laat erven,
                 * krijgt daar dus papier op wit — wat precies zo onzichtbaar is
                 * als het klinkt, en alleen opvalt bij het onderdeel dat geen
                 * eigen kleur had staan.
                 */
                color: 'var(--pd-inkt)',
                borderRadius: 10,
                // Geen schaduw, nergens: de huisstijl bouwt diepte met randen.
                border: '1px solid var(--pd-zand)',
                overflow: 'hidden',
            }}
        >
            <div
                className="flex items-center gap-2 px-4 py-3"
                style={{
                    borderBottom: '1px solid var(--pd-zand)',
                    fontFamily: 'var(--pd-mono)',
                    fontSize: 12,
                    color: 'var(--pd-inkt)',
                }}
            >
                <span style={{ color: 'var(--pd-steen)' }}>#</span>
                {t('marketing.home.sketch.channel')}
                <span
                    className="ml-auto"
                    style={{ color: 'var(--pd-steen)', fontSize: 11 }}
                >
                    {t('marketing.home.sketch.guest')}
                </span>
            </div>

            <div className="grid gap-4 px-4 py-5">
                <div>
                    <div
                        className="mb-1.5 flex items-baseline gap-2"
                        style={{
                            fontFamily: 'var(--pd-mono)',
                            fontSize: 12,
                        }}
                    >
                        <span style={{ fontWeight: 600 }}>
                            {t('marketing.home.sketch.who')}
                        </span>
                        <span style={{ color: '#a3a294', fontSize: 11 }}>
                            {t('marketing.home.sketch.time')}
                        </span>
                    </div>
                    <p
                        className="m-0"
                        style={{
                            fontSize: 14,
                            lineHeight: 1.55,
                            color: 'var(--pd-inkt)',
                        }}
                    >
                        {t('marketing.home.sketch.message')}
                    </p>
                </div>

                {/*
                    Het ticket dat uit dat bericht komt, ingesprongen zoals het
                    in het gesprek ook onder het bericht hangt. De gele streep
                    links is de enige kleur in de schets, en staat op de plek
                    waar de huisstijl geel toestaat: als lijn, niet als vlak.
                */}
                <div
                    className="ml-4 p-3.5"
                    style={{
                        border: '1px solid var(--pd-zand)',
                        borderLeft: '3px solid var(--pd-geel)',
                        borderRadius: 6,
                        background: 'var(--pd-papier)',
                    }}
                >
                    <div
                        className="mb-1.5 flex items-center gap-2"
                        style={{
                            fontFamily: 'var(--pd-mono)',
                            fontSize: 10.5,
                            letterSpacing: '0.06em',
                            textTransform: 'uppercase',
                            color: 'var(--pd-steen)',
                        }}
                    >
                        {t('marketing.home.sketch.ticket_label')}
                        <span
                            style={{
                                width: 5,
                                height: 5,
                                borderRadius: '50%',
                                background: 'var(--pd-steen)',
                            }}
                        />
                        {t('marketing.home.sketch.ticket_status')}
                    </div>
                    <div
                        style={{
                            fontFamily: 'var(--pd-mono)',
                            fontSize: 13.5,
                            fontWeight: 600,
                        }}
                    >
                        {t('marketing.home.sketch.ticket_title')}
                    </div>
                    <div
                        className="mt-1"
                        style={{ fontSize: 13, color: 'var(--pd-steen)' }}
                    >
                        {t('marketing.home.sketch.ticket_assignee')}
                    </div>
                </div>
            </div>
        </div>
    );
}
