<?php

namespace App\Http\Middleware;

use App\Enums\PlatformEdition;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * De publieke site, en alleen op de installatie die het product ook verkoopt.
 *
 * Op de route en niet in de controller, zoals EnsureInstallationIsPending: dit
 * is niet het verbergen van een pagina maar het antwoord op de vraag of dit
 * adres hier iets voorstelt.
 *
 * Een redirect en geen 404, anders dan bij het installatiescherm. Dit is het
 * hoofdadres van de installatie — iemand die postduif.eigenbedrijf.nl intypt
 * wil naar zijn chat, en die een foutpagina geven omdat er geen reclame te tonen
 * valt is de verkeerde uitkomst. Wie ingelogd is gaat door naar zijn workspace,
 * de rest naar het inlogscherm.
 */
class EnsureMarketingSiteIsShown
{
    public function handle(Request $request, Closure $next): Response
    {
        if (PlatformEdition::current()->showsMarketingSite()) {
            return $next($request);
        }

        return redirect()->route(
            $request->user() === null ? 'login' : 'chat.home',
        );
    }
}
