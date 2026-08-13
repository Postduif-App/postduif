<?php

namespace App\Actions\Marketing;

use App\Enums\ChannelDocumentPolicy;
use App\Enums\ChannelLayout;
use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelTicketPolicy;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Features\WorkspaceFeature;
use App\Mcp\Servers\ChatServer;
use App\Workflows\WorkflowRegistry;
use Illuminate\Routing\Router;
use Laravel\Mcp\Server\Tool;
use ReflectionProperty;

class BuildFeatureInventory
{
    /**
     * What this application can actually do, taken from the application.
     *
     * Derived rather than written out, and that is the whole point of it. A
     * marketing page maintained by hand starts as a description and becomes a
     * claim: the feature gets renamed, switched off by default, or dropped, and
     * the page goes on promising it. Here the labels and the sentences are the
     * same ones a beheerder reads in their own settings screen, because they
     * are read from the same classes.
     *
     * What it cannot derive is tone. Anything on the marketing site that is a
     * judgement — "the fastest", "the easiest" — has to be written by a person
     * and is not this action's business.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(): array
    {
        return array_map(fn (string $feature): array => [
            'key' => $feature::key(),
            'label' => $feature::label(),
            'description' => $feature::description(),
            /*
             * Worth saying out loud on a public page rather than hiding: three
             * of these start switched off, and each one is off because it lets
             * something reach past the workspace — an AI client reading along,
             * a download link for the outside world, a store of other people's
             * passwords. "You decide, and nothing is decided for you" is a
             * stronger claim than any of the features individually.
             */
            'onByDefault' => $feature::default(),
        ], WorkspaceFeature::ALL);
    }

    /**
     * How a channel can be set up, in the words the settings screen uses.
     *
     * The half of this application that has no feature switch: a channel is not
     * something you turn on, so none of it appears in the inventory above. It
     * is still most of what somebody is buying, and every answer here is an
     * enum case with a label and a sentence already written for a beheerder.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    public function channelSettings(): array
    {
        return [
            'layout' => array_map(fn (ChannelLayout $case): array => [
                'label' => $case->getLabel(),
                'description' => $case->description(),
            ], ChannelLayout::cases()),

            'posting' => array_map(fn (ChannelPostingPolicy $case): array => [
                'label' => $case->label(),
                'description' => $case->description(),
            ], ChannelPostingPolicy::cases()),

            'tickets' => array_map(fn (ChannelTicketPolicy $case): array => [
                'label' => $case->label(),
                'description' => $case->description(),
            ], ChannelTicketPolicy::cases()),

            'documents' => array_map(fn (ChannelDocumentPolicy $case): array => [
                'label' => $case->label(),
                'description' => $case->description(),
            ], ChannelDocumentPolicy::cases()),
        ];
    }

    /**
     * Every trigger and every action a workflow can be built from.
     *
     * Read from the register the runner reads, which is the same argument as
     * everywhere else on this page one step further: a page that listed these
     * by hand would keep advertising an action the day it was taken out, and
     * the register is the one thing that cannot be wrong about it.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    public function workflowVocabulary(WorkflowRegistry $registry): array
    {
        $described = $registry->toArray();

        $plain = fn (array $entries): array => array_map(fn (array $entry): array => [
            'label' => $entry['label'],
            'description' => $entry['description'],
        ], $entries);

        return [
            'triggers' => $plain($described['triggers']),
            'actions' => $plain($described['actions']),
        ];
    }

    /**
     * What a personal token opens, as the router and the MCP server have it.
     *
     * Two doors onto the same application and both are worth showing: the
     * endpoints because somebody writing a script wants to see the shape before
     * they sign up, and the tools because "works with an AI client" is a claim
     * that ought to name what the client can actually do.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    public function tokenSurface(Router $router): array
    {
        $endpoints = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'api.v1.')) {
                continue;
            }

            $endpoints[] = [
                // HEAD comes along with every GET and says nothing anybody
                // needs to read.
                'method' => implode(', ', array_diff($route->methods(), ['HEAD'])),
                'path' => '/'.ltrim($route->uri(), '/'),
            ];
        }

        return [
            'endpoints' => $endpoints,

            /*
             * The tool's own name and the sentence it hands the client, which
             * is the sentence that decides whether a model reaches for it. If
             * it does not read well here it does not read well there either.
             *
             * Read off the server through reflection, because the list is
             * protected and rightly so — making it public to feed a marketing
             * page would be the page dictating the shape of the server.
             */
            'tools' => array_map(function (string $tool): array {
                $instance = app($tool);

                return [
                    'name' => $instance->name(),
                    'description' => $instance->description(),
                ];
            }, $this->toolsOf(ChatServer::class)),
        ];
    }

    /**
     * @param  class-string  $server
     * @return array<int, class-string<Tool>>
     */
    private function toolsOf(string $server): array
    {
        $property = new ReflectionProperty($server, 'tools');

        return $property->getDefaultValue() ?? [];
    }

    /**
     * The roles a workspace is given when it is made, and what each starts with.
     *
     * Deliberately not "what a role may do". A workspace's roles live in its own
     * table and a beheerder edits them, so this enum stopped being the answer to
     * "may they?" the day that screen shipped — see Workspace::seedSystemRoles,
     * which is the only caller of defaultAbilities() besides this page. Read off
     * that same method rather than off the predicates beside it, so the table
     * shows the row a workspace actually gets rather than a second opinion about
     * it.
     *
     * The guest row is still the interesting one, and browsing is still its own
     * answer: it is a column on the role and not an entry in the bag, because it
     * decides what exists for somebody rather than what they may do with it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function roles(): array
    {
        return array_map(fn (SystemRole $role): array => [
            'value' => $role->value,
            'label' => $role->getLabel(),
            'canBrowseWorkspace' => $role->canBrowseWorkspace(),
            'abilities' => array_map(
                fn (WorkspaceAbility $ability): string => $ability->value,
                $role->defaultAbilities(),
            ),
        ], SystemRole::cases());
    }

    /**
     * Every right a workspace can hand out, in the order the catalogue lists it.
     *
     * The rows of the table on the public page, and the same closed list the
     * settings screen draws its tickboxes from. Worth showing in full rather
     * than summarising: a workspace composes its own roles out of exactly these
     * and nothing else, so the list *is* the answer to what a custom role can be
     * made to mean.
     *
     * @return array<int, array<string, string>>
     */
    public function abilities(): array
    {
        return array_map(fn (WorkspaceAbility $ability): array => [
            'value' => $ability->value,
            'label' => $ability->label(),
        ], WorkspaceAbility::cases());
    }
}
