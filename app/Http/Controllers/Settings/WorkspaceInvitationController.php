<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\ChannelType;
use App\Features\InviteLinks;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Invitation;
use App\Models\InviteLink;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The standing list of invitations that were sent and not yet used.
 *
 * Sending one is a quick action that belongs where you are working — the
 * workspace menu in the chat has a button for it. Keeping track of the ones
 * still out there is administration, and administration belongs here.
 */
class WorkspaceInvitationController extends Controller
{
    use ResolvesCurrentWorkspace;

    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request, 'invite');

        return Inertia::render('settings/invitations', [
            'workspaceName' => $workspace->name,
            'workspaceSlug' => $workspace->slug,
            'invitations' => $workspace->invitations()
                ->with(['channels', 'inviter'])
                ->whereNull('accepted_at')
                ->orderBy('email')
                ->get()
                ->map(fn (Invitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'roleLabel' => $invitation->role->getLabel(),
                    'invitedBy' => $invitation->inviter->name,
                    'expiresAt' => $invitation->expires_at,
                    // Expired ones stay on the list: the row still occupies
                    // that address, so it has to be visible before it can be
                    // sent again or withdrawn.
                    'hasExpired' => $invitation->hasExpired(),
                    'channels' => $invitation->channels
                        ->map(fn (Channel $channel): string => (string) $channel->name)
                        ->sort()
                        ->values()
                        ->all(),
                ])
                ->all(),
            /*
             * Empty rather than absent when links are switched off: the section
             * disappears from the page, and there is nothing left to draw that
             * points at an endpoint which would refuse it.
             */
            'inviteLinksEnabled' => $workspace->hasFeature(InviteLinks::class),
            'inviteLinks' => $workspace->hasFeature(InviteLinks::class)
                ? $this->linksFor($workspace)
                : [],
            'channels' => $this->invitableChannels($workspace),
        ]);
    }

    /**
     * The shareable links, newest first.
     *
     * The URL is built here and handed over in full. The token is the secret,
     * and it is hidden on the model precisely so it never leaves by accident —
     * but a link nobody can read again after making it is a link you lose the
     * moment you close the tab, which is the one thing a shareable link may not
     * be. So it is asked for by name, here, on a page only somebody who may
     * invite can open.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linksFor(Workspace $workspace): array
    {
        return $workspace->inviteLinks()
            ->with(['channels', 'creator'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (InviteLink $link): array => [
                'id' => $link->id,
                'url' => route('invite-links.show', $link->token),
                'roleLabel' => $link->role->getLabel(),
                'isGuest' => $link->role->isGuest(),
                'createdBy' => $link->creator?->name,
                'uses' => $link->uses,
                'maxUses' => $link->max_uses,
                'expiresAt' => $link->expires_at,
                // One word for why it is on the list but not working, so the
                // page does not have to re-derive it from three nullable
                // fields and get it subtly different.
                'state' => match (true) {
                    $link->isRevoked() => 'revoked',
                    $link->hasExpired() => 'expired',
                    $link->isExhausted() => 'exhausted',
                    default => 'usable',
                },
                'channels' => $link->channels
                    ->map(fn (Channel $channel): string => (string) $channel->name)
                    ->sort()
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * The channels a new link may be pointed at — the same set the server will
     * accept, so the form cannot offer something the endpoint then drops.
     *
     * @return array<int, array<string, mixed>>
     */
    private function invitableChannels(Workspace $workspace): array
    {
        return $workspace->channels()
            ->where('type', '!=', ChannelType::Direct)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'name' => (string) $channel->name,
                'isPrivate' => $channel->type === ChannelType::Private,
            ])
            ->all();
    }
}
