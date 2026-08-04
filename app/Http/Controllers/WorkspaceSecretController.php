<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Actions\Secrets\SendSecret;
use App\Http\Requests\StoreWorkspaceSecretRequest;
use App\Models\SentSecret;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything this member has put aside, and the place to put aside one more.
 *
 * Inside the chat shell rather than on a page of its own, the same choice the
 * ticket, transfer and prikbord lists make: the same sidebar, the same unread
 * counts, the same live connection.
 *
 * The list is one person's own and is not narrowed by a beheerder flag the way
 * the transfer list is. That is deliberate, and it is the difference between a
 * transfer and a secret: a transfer is a file with somebody's name on it that
 * the workspace can reasonably audit, while this list is a record of which
 * credentials one person handed to whom. Nothing here would be readable to a
 * beheerder anyway — the ciphertext is shut to us — so the only thing a
 * workspace-wide view would add is the metadata, which is exactly the sensitive
 * part.
 */
class WorkspaceSecretController extends Controller
{
    public function __construct(
        private readonly BuildChatShell $buildChatShell,
        private readonly SendSecret $sendSecret,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        $user = $request->user();

        abort_unless($workspace->hasMember($user), 403, __('chat.not_a_member'));

        $secrets = SentSecret::query()
            ->where('workspace_id', $workspace->id)
            ->sentBy($user)
            ->with(['recipient', 'channel'])
            ->limit(200)
            ->get();

        return Inertia::render('chat/secrets', [
            ...$this->buildChatShell->handle($workspace, $user),
            /*
             * Everything, expired and picked up included. That is the whole
             * point of the page: "is dit al opgehaald" and "is dit nog geldig"
             * are the two questions somebody comes here with, and a list that
             * had already dropped those rows answers neither.
             */
            'secrets' => $secrets->map(fn (SentSecret $secret): array => [
                'id' => $secret->id,
                'label' => $secret->label,
                // Null where nobody was named, which is the ordinary case for a
                // link made here.
                'recipientName' => $secret->recipient?->name,
                // And null where it was never announced anywhere. The list says
                // "in #kanaal" or nothing, rather than pretending to a room.
                'channelLabel' => $secret->channel?->displayNameFor($user),
                'state' => $secret->state(),
                'needsPassword' => $secret->needsPassword(),
                'createdAt' => $secret->created_at?->toIso8601String(),
                'expiresAt' => $secret->expires_at->toIso8601String(),
                'revealedAt' => $secret->revealed_at?->toIso8601String(),
            ])->values()->all(),
            /*
             * Everybody in the workspace, so a link can optionally be addressed
             * to one of them. Guests are in this list on purpose, unlike the
             * member panel's: handing a customer a password is precisely what
             * this is for.
             */
            'people' => $workspace->members()
                ->orderBy('name')
                ->get()
                ->reject(fn (User $member): bool => $member->is($user))
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                ])->values()->all(),
        ]);
    }

    /**
     * Make a link without saying anything anywhere.
     *
     * The channel is null, so SendSecret posts no message — see the guard there.
     * Everything else is identical to sending one from a conversation, right
     * down to the server never seeing the key.
     */
    public function store(StoreWorkspaceSecretRequest $request, Workspace $workspace): RedirectResponse
    {
        $recipient = $request->filled('recipient_id')
            ? User::query()->findOrFail($request->integer('recipient_id'))
            : null;

        $secret = $this->sendSecret->handle(
            workspace: $workspace,
            channel: null,
            sender: $request->user(),
            recipient: $recipient,
            label: $request->string('label')->toString(),
            ciphertext: $request->string('ciphertext')->toString(),
            iv: $request->string('iv')->toString(),
            validForDays: $request->integer('valid_for_days'),
            password: $request->filled('password')
                ? $request->string('password')->toString()
                : null,
        );

        // The same flash the channel version uses, for the same reason: only the
        // address travels, and the browser holding the key builds the link.
        Inertia::flash('sentSecret', [
            'id' => $secret->id,
            'url' => route('sent-secrets.show', $secret->id),
        ]);

        return back();
    }
}
