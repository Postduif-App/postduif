<?php

namespace App\Enums;

/**
 * Waar een onderdeel over gaat, voor iemand die de lijst voor het eerst leest.
 *
 * Achttien features achter elkaar zijn achttien even zware kaartjes, en een
 * lezer die niet weet wat hij zoekt haakt bij de zesde af. Dit is de enige
 * indeling die de pagina zelf niet mag verzinnen: hij hangt aan de featureklasse
 * — zie WorkspaceFeature::group(), dat abstract is — zodat een nieuw onderdeel
 * niet stilletjes buiten de lijst kan vallen. Een vergeten groep is een fatal
 * error bij het aanmaken van de klasse, niet een gat op een pagina dat niemand
 * ziet.
 *
 * De volgorde hieronder is de volgorde op de pagina: eerst waar iedereen de dag
 * in zit, dan wat daaruit volgt, dan de mensen van buiten, en als laatste de
 * dingen die zonder mens gebeuren.
 */
enum FeatureGroup: string
{
    case Conversation = 'conversation';
    case Work = 'work';
    case Outside = 'outside';
    case Automation = 'automation';

    /** Hoe de groep heet boven de kaarten die eronder staan. */
    public function label(): string
    {
        return match ($this) {
            self::Conversation => __('enums.feature-group.label.Conversation'),
            self::Work => __('enums.feature-group.label.Work'),
            self::Outside => __('enums.feature-group.label.Outside'),
            self::Automation => __('enums.feature-group.label.Automation'),
        };
    }

    /**
     * De zin eronder, die zegt wat de onderdelen in deze groep gemeen hebben.
     *
     * Dit is het enige stuk van de featurelijst dat een oordeel bevat en niet
     * uit een klasse te lezen valt — de features zelf beschrijven wat ze doen,
     * niet waarom ze bij elkaar horen.
     */
    public function description(): string
    {
        return match ($this) {
            self::Conversation => __('enums.feature-group.description.Conversation'),
            self::Work => __('enums.feature-group.description.Work'),
            self::Outside => __('enums.feature-group.description.Outside'),
            self::Automation => __('enums.feature-group.description.Automation'),
        };
    }
}
