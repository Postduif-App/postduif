<?php

namespace App\Http\Controllers;

use App\Actions\Marketing\BuildApiReference;
use App\Actions\Marketing\BuildFeatureInventory;
use App\Workflows\WorkflowRegistry;
use Illuminate\Routing\Router;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public face of Postduif, for somebody who has never logged in.
 *
 * Everything factual on these pages comes from BuildFeatureInventory, which
 * reads the application rather than describing it. That is a deliberate
 * constraint and not a convenience: a marketing page written by hand becomes a
 * promise the code has stopped keeping, and nobody notices until a customer
 * does.
 */
class MarketingController extends Controller
{
    public function home(BuildFeatureInventory $inventory, WorkflowRegistry $registry, Router $router): Response
    {
        return Inertia::render('marketing/home', [
            'features' => $inventory->handle(),

            /*
             * De koppen waaronder die lijst uiteenvalt, en de drie die er
             * bovenuit steken. Achttien gelijke kaartjes zijn achttien keer
             * hetzelfde gewicht, en een lezer die niet weet waar hij voor komt
             * haakt af voor hij bij het onderdeel is waarvoor hij bleef.
             *
             * Twee props naast de lijst en niet één genest antwoord: de
             * volgorde van de features is een keuze van de applicatie en blijft
             * die van WorkspaceFeature::ALL, terwijl de indeling en de nadruk
             * van deze pagina zijn.
             */
            'featureGroups' => $inventory->featureGroups(),
            'spotlight' => $inventory->spotlight(),

            'roles' => $inventory->roles(),

            /*
             * The rights themselves, beside the roles that start with them. Two
             * props rather than one table built here, because the page is what
             * decides which of the two becomes a row — and because the
             * catalogue is the more important half: it is what a workspace
             * composes a role of its own out of.
             */
            'abilities' => $inventory->abilities(),

            /*
             * The three that have no feature switch of their own: how a channel
             * is set up, what a workflow can be built from, and what a personal
             * token opens. Together they are most of what somebody is buying,
             * and none of it would appear on a page that only listed the
             * switches.
             */
            'channelSettings' => $inventory->channelSettings(),
            'workflow' => $inventory->workflowVocabulary($registry),
            'token' => $inventory->tokenSurface($router),

            'seo' => $this->seo(
                route('home'),
                __('marketing.seo.home.title'),
                __('marketing.seo.home.description'),
                [
                    /*
                     * SoftwareApplication rather than Organization: what is
                     * being described here is the thing somebody installs, and
                     * there is no company behind this to describe.
                     */
                    '@context' => 'https://schema.org',
                    '@type' => 'SoftwareApplication',
                    'name' => config('app.name'),
                    'applicationCategory' => 'CommunicationApplication',
                    'operatingSystem' => 'Web',
                    'url' => route('home'),
                    /*
                     * Read off the same inventory the page renders, so the
                     * count in the structured data cannot claim a feature the
                     * page does not list.
                     */
                    'featureList' => array_column($inventory->handle(), 'label'),
                ],
            ),
        ]);
    }

    /**
     * The reference for the API, for somebody about to point a script at it.
     *
     * Its own page rather than more of the landing page: the home page answers
     * "is there an API" in one card, and the person who has decided there is
     * wants the method, the path, the parameters and the ceiling — which is a
     * different reader entirely, and a landing page that served both would
     * serve neither.
     *
     * Public, like the rest of this controller. The endpoints are guarded by a
     * token nobody gets from reading about them, and an API whose shape is a
     * secret is one nobody can build against.
     */
    public function docs(BuildApiReference $reference, BuildFeatureInventory $inventory, Router $router): Response
    {
        return Inertia::render('marketing/docs', [
            ...$reference->handle($router),

            /*
             * The MCP tools alongside the plain endpoints. They are the other
             * half of what a token opens, and somebody weighing "can I automate
             * this" is asking one question rather than two.
             */
            'tools' => $inventory->tokenSurface($router)['tools'],

            'seo' => $this->seo(
                route('docs'),
                __('marketing.seo.docs.title'),
                __('marketing.seo.docs.description'),
            ),
        ]);
    }

    /**
     * What a crawler and a chat client are told about a page.
     *
     * One shape for every public page, because the alternative is a page that
     * quietly ships without a description — and the tags themselves are
     * rendered in the Blade template rather than through Inertia's head, so
     * they survive a client that never runs JavaScript.
     *
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    private function seo(string $url, string $title, string $description, ?array $schema = null): array
    {
        return [
            'url' => $url,
            'title' => $title,
            'description' => $description,
            // Absolute, because a preview card is fetched by something that has
            // no idea what this site's root is.
            'image' => url('/og.png'),
            ...$schema === null ? [] : ['schema' => $schema],
        ];
    }
}
