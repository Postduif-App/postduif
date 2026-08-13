<?php

namespace App\Enums;

/**
 * Welke uitgave van Postduif hier draait.
 *
 * Er zijn er twee, en het verschil is precies één ding: de gehoste uitgave op
 * postduif.app verkoopt het product en heeft dus een publieke site, een
 * zelfgehoste installatie is het product en heeft die niet. Een bedrijf dat
 * Postduif op zijn eigen server zet, zet daarmee ongewild een landingspagina
 * online die zijn eigen chat aanprijst — met een aanmeldknop erop.
 *
 * Zelfgehost is de standaard, en dat is de veilige kant op: wie niets instelt
 * krijgt geen publieke site. De enige installatie die de andere kant op moet,
 * is er één, en die kan het zelf zeggen.
 *
 * Een enum en geen kale bool in config, omdat "wel of geen marketingsite" niet
 * de vraag is maar het gevolg. De vraag is welke uitgave dit is, en het antwoord
 * daarop bepaalt straks misschien meer dan één ding — een bool zou dan een
 * tweede bool krijgen die er los naast staat en per ongeluk anders kan staan.
 */
enum PlatformEdition: string
{
    /** postduif.app: de installatie die het product ook verkoopt. */
    case Hosted = 'hosted';

    /** Iemands eigen server. De standaard, want de meeste installaties zijn dit. */
    case SelfHosted = 'self-hosted';

    /**
     * Wat er in de omgeving staat, en zelfgehost bij alles wat daar niet op lijkt.
     *
     * tryFrom en geen from(): een typefout in .env moet deze installatie niet
     * plat leggen, en al helemaal niet de andere kant op vallen. Een onbekende
     * waarde betekent dat er niemand bewust "hosted" heeft gezegd, en dan is
     * zelfgehost het antwoord.
     */
    public static function current(): self
    {
        return self::tryFrom((string) config('app.edition')) ?? self::SelfHosted;
    }

    /**
     * Of de publieke site hier hoort te staan.
     *
     * Alleen de gehoste uitgave. Zie EnsureMarketingSiteIsShown voor wat er
     * gebeurt als iemand er toch op afkomt, en IndexingController voor wat een
     * crawler in dat geval te horen krijgt.
     */
    public function showsMarketingSite(): bool
    {
        return $this === self::Hosted;
    }
}
