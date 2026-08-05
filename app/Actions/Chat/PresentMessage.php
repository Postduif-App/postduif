<?php

namespace App\Actions\Chat;

use App\Models\Form;
use App\Models\LinkPreview;
use App\Models\Message;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\SecretRequest;
use App\Models\SentSecret;
use App\Models\Transfer;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PresentMessage
{
    public function __construct(
        private readonly CensorBlockedWords $censorBlockedWords,
    ) {}

    /**
     * Blocklists already fetched, keyed by workspace.
     *
     * A page renders fifty messages that all belong to the same workspace, and
     * without this each of them would go and ask the database for the same
     * list.
     *
     * @var array<int, array<int, string>>
     */
    private array $blockedWords = [];

    /**
     * Which members of a workspace are guests, keyed by workspace.
     *
     * Same reason as the blocklist above: fifty messages from one workspace
     * would otherwise ask the same question fifty times. A set rather than a
     * list, so the lookup stays a hash hit.
     *
     * @var array<int, array<int, true>>
     */
    private array $guestIds = [];

    /**
     * Polls already looked up, keyed by id.
     *
     * @var array<string, Poll|null>
     */
    private array $polls = [];

    /**
     * Secret requests already looked up, keyed by id.
     *
     * @var array<string, SecretRequest|null>
     */
    private array $secretRequests = [];

    /**
     * Forms already looked up, keyed by id.
     *
     * @var array<string, Form|null>
     */
    private array $forms = [];

    /**
     * Transfers already looked up, keyed by token.
     *
     * Same reason as the previews below: one link pasted five times is one
     * query, not five.
     *
     * @var array<string, Transfer|null>
     */
    private array $transfers = [];

    /**
     * Secrets already fetched, keyed by id — the same reason the lists above
     * exist: a channel can hold several links to the same one.
     *
     * @var array<string, SentSecret|null>
     */
    private array $sentSecrets = [];

    /**
     * Previews already looked up, keyed by URL.
     *
     * A page renders fifty messages, and a link pasted five times would
     * otherwise be five identical queries.
     *
     * @var array<string, LinkPreview|null>
     */
    private array $linkPreviews = [];

    /**
     * Which workspaces show link cards, keyed by workspace.
     *
     * @var array<int, bool>
     */
    private array $linkPreviewsEnabled = [];

    /**
     * Shape a message for the frontend.
     *
     * Both the Inertia page and the broadcast payload go through here, because
     * the browser merges the two into one list: if the shapes drift apart, a
     * message changes appearance the moment the page reloads.
     *
     * @return array<string, mixed>
     */
    public function handle(Message $message): array
    {
        $message->loadMissing(['author', 'reactions', 'quoted.author', 'pinner', 'media', 'workspace', 'workflow']);

        $deleted = $message->isDeleted();

        return [
            'id' => $message->id,
            'parentId' => $message->parent_id,
            'quoted' => $this->quoted($message),
            // A deleted message is soft-deleted, so its text is still in the
            // database — but it must not travel any further. The browser only
            // gets what it needs to draw the tombstone.
            'body' => $deleted ? '' : $this->censor($message),
            'createdAt' => $message->created_at?->toIso8601String(),
            'editedAt' => $message->edited_at?->toIso8601String(),
            'deletedAt' => $message->deleted_at?->toIso8601String(),
            'replyCount' => $message->reply_count,
            // Both halves of the pin, because the row shows it as "vastgepind
            // door X": the timestamp alone would leave the marker unexplained,
            // and a name without a moment says nothing about how old the rule
            // on screen is. Null for the pinner when that member is gone — the
            // pin outlives them, see the migration.
            'pinnedAt' => $message->pinned_at?->toIso8601String(),
            'pinnedBy' => $message->pinner?->name,
            // Whose words these were, on a message that was carried here from
            // another conversation. Null on everything else, which is most of
            // them — see ForwardMessage for why it is a name and not a link.
            'forwardedFrom' => $deleted ? null : $message->forwarded_from,
            'author' => $this->author($message),
            'reactions' => $deleted ? [] : $this->reactions($message),
            // Nothing for a deleted message, for the same reason its text is
            // withheld: the tombstone stands for what was there, and a row of
            // files under it would be half of the thing that was taken back.
            'attachments' => $deleted ? [] : $this->attachments($message),
            // What the first link in the message turned out to be, when it has
            // been looked up and the look-up produced something.
            'linkPreview' => $deleted ? null : $this->linkPreview($message),
            // What a link to our own transfer route is carrying. Kept apart
            // from linkPreview because it is a different kind of thing: that
            // one is what somebody else's page said about itself, this one is
            // our own database.
            'transferCard' => $deleted ? null : $this->transferCard($message),
            // The same idea for a request for secrets: a bare link to a form
            // says nothing about what is being asked or whether anybody still
            // needs to answer it.
            'secretCard' => $deleted ? null : $this->secretCard($message),
            // And a question put to the channel, with where the votes stand.
            'pollCard' => $deleted ? null : $this->pollCard($message),
            // And a form put to the channel — the questions it asks, and
            // nothing whatever about the answers.
            'formCard' => $deleted ? null : $this->formCard($message),
            // And a secret put aside for one person: who it is for, never what.
            'sentSecretCard' => $deleted ? null : $this->sentSecretCard($message),
        ];
    }

    /**
     * A poll, and how the channel has answered so far.
     *
     * Who voted for what is carried along rather than kept back, because that
     * is what this feature decided a poll is — see the polls migration. It also
     * solves a problem the transfer card ran into: this output is the broadcast
     * payload as well, sent to everybody at once, so it cannot hold "what you
     * chose". The browser works that out from the voters below and the user it
     * already knows it is.
     *
     * @return array<string, mixed>|null
     */
    private function pollCard(Message $message): ?array
    {
        $id = $this->pollIdIn($message->body);

        if ($id === null) {
            return null;
        }

        $poll = $this->polls[$id] ??= Poll::query()
            ->with(['options.votes.voter'])
            ->find($id);

        if ($poll === null || $poll->workspace_id !== $message->workspace_id) {
            return null;
        }

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'allowsMultiple' => $poll->allows_multiple,
            'isClosed' => $poll->isClosed(),
            // Which of the two it was, so the card can say "gesloten" where
            // somebody stopped it and "verlopen" where the moment passed.
            'state' => match (true) {
                $poll->closed_at !== null => 'closed',
                $poll->isClosed() => 'expired',
                default => 'open',
            },
            'closesAt' => $poll->closes_at,

            // Who asked, rather than "may you close this": the same payload
            // goes to everybody, so the question of whose poll it is has to be
            // answered in the browser, against the user it already knows it is.
            // A channel manager may close somebody else's poll — the policy
            // says so — but there is no way to offer them the button from here
            // without a payload per viewer, and the asker is the case that
            // matters.
            'askedBy' => $poll->created_by,

            // People, not ticks: on a multiple-choice poll one person may
            // appear under three answers.
            'voterCount' => $poll->options
                ->flatMap(fn (PollOption $option) => $option->votes->pluck('user_id'))
                ->unique()
                ->count(),

            'options' => $poll->options->map(fn (PollOption $option): array => [
                'id' => $option->id,
                'label' => $option->label,
                'voters' => $option->votes
                    ->map(fn (PollVote $vote): array => [
                        'id' => $vote->user_id,
                        'name' => $vote->voter?->name,
                        // Faces rather than a tally: who answered what is the
                        // thing this poll shows, and a name only appears on
                        // hover. The initials fall back to the name.
                        'avatarUrl' => $vote->voter?->avatarUrl(),
                    ])->all(),
            ])->all(),
        ];
    }

    /**
     * A form somebody put in this channel.
     *
     * The opposite decision to the poll card above, and the reason is worth
     * spelling out. A poll shows who voted for what, because that is what a
     * poll in a work channel is for. A form is the other thing entirely: it
     * exists so that answers go to one named person, and this payload is the
     * broadcast that goes to everybody in the room at once.
     *
     * So nothing about the answers travels — not the values, not the names of
     * who sent one in, not even how many there are. "Vier collega's hebben
     * vakantie aangevraagd" is not the channel's business, and a count is the
     * kind of thing that looks harmless right up until the form is called
     * "Melding ongewenst gedrag".
     *
     * What the card does carry is enough to decide whether to walk in: the
     * title, the explanation, how many questions, and whether it still takes
     * answers. Whether *you* already sent one in is answered on the fill screen,
     * which is rendered per person and can afford to know.
     *
     * @return array<string, mixed>|null
     */
    private function formCard(Message $message): ?array
    {
        $id = $this->formIdIn($message->body);

        if ($id === null) {
            return null;
        }

        $form = $this->forms[$id] ??= Form::query()
            ->withCount('fields')
            ->find($id);

        if ($form === null || $form->workspace_id !== $message->workspace_id) {
            return null;
        }

        return [
            'id' => $form->id,
            'title' => $form->title,
            'description' => $form->description,

            // Which of the two ways of being shut it was, exactly as a poll
            // distinguishes them.
            'state' => match (true) {
                $form->closed_at !== null => 'closed',
                $form->isClosed() => 'expired',
                default => 'open',
            },

            'closesAt' => $form->closes_at?->toIso8601String(),

            // A form with no questions cannot be filled in, and the card says
            // so rather than offering a button that leads to an empty page.
            'fieldCount' => $form->fields_count,
            'isFillable' => $form->acceptsAnswers(),
        ];
    }

    /**
     * The id of the first link in this body pointing at a poll, or null.
     *
     * Built from the route, as the transfer and secret matchers are.
     */
    private function pollIdIn(string $body): ?string
    {
        $prefix = route('chat.polls.show', ['__WS__', '__ID__']);
        [$before] = explode('__ID__', $prefix, 2);

        // The workspace slug sits inside the prefix, so match it as a segment
        // rather than pinning the one this message happens to be in.
        $pattern = '/'.preg_quote($before, '/').'([0-9a-hjkmnp-tv-z]{26})\b/i';
        $pattern = str_replace(preg_quote('__WS__', '/'), '[a-z0-9-]+', $pattern);

        return preg_match($pattern, $body, $matches) === 1
            ? mb_strtolower($matches[1])
            : null;
    }

    /**
     * The id of the first link in this body pointing at a form, or null.
     *
     * The same construction as the poll matcher, and it has to be: the route is
     * the single source for what a form's address looks like, so changing the
     * path in routes/chat.php cannot leave the cards behind.
     */
    private function formIdIn(string $body): ?string
    {
        $prefix = route('chat.forms.show', ['__WS__', '__ID__']);
        [$before] = explode('__ID__', $prefix, 2);

        $pattern = '/'.preg_quote($before, '/').'([0-9a-hjkmnp-tv-z]{26})\b/i';
        $pattern = str_replace(preg_quote('__WS__', '/'), '[a-z0-9-]+', $pattern);

        return preg_match($pattern, $body, $matches) === 1
            ? mb_strtolower($matches[1])
            : null;
    }

    /**
     * What a link to one of our own secret requests is asking for.
     *
     * Note what is deliberately absent: which key was answered by whom, and of
     * course any value. The count is enough to tell somebody in the channel
     * whether there is still something for them to do, and anything finer would
     * be saying who holds which credential in front of everyone.
     *
     * @return array<string, mixed>|null
     */
    private function secretCard(Message $message): ?array
    {
        $id = $this->secretRequestIdIn($message->body);

        if ($id === null) {
            return null;
        }

        $request = $this->secretRequests[$id] ??= SecretRequest::query()
            ->withCount(['keys', 'values'])
            ->find($id);

        if ($request === null) {
            return null;
        }

        // Only a request from the workspace this message is in, for the reason
        // the transfer card checks the same thing.
        if ($request->workspace_id !== $message->workspace_id) {
            return null;
        }

        return [
            'id' => $request->id,
            'title' => $request->title,
            'keyCount' => $request->keys_count,
            'answeredCount' => $request->values_count,
            'expiresAt' => $request->expires_at,
            'state' => match (true) {
                $request->isRevoked() => 'revoked',
                $request->hasExpired() => 'expired',
                default => 'open',
            },
            /*
             * One link for everybody, and the server decides where it lands —
             * see SecretFillController::show(), which sends the person who
             * asked to the answers instead of to the form.
             *
             * It has to work that way round rather than being decided here:
             * this output is also the broadcast payload, which goes to every
             * member of the channel at once. Anything that differs per viewer
             * would be wrong for all but one of them.
             */
            'url' => route('secrets.show', $request->id),
        ];
    }

    /**
     * A secret somebody put aside for one person in this channel.
     *
     * Says who it is for and whether it is still there — never what it is, and
     * this card could not tell you if it wanted to: the server holds ciphertext
     * it has no key for. Note that the url below is deliberately not enough to
     * open anything. The key lives in the fragment, which only the sender's
     * browser ever had, so this link is an announcement rather than a way in.
     *
     * @return array<string, mixed>|null
     */
    private function sentSecretCard(Message $message): ?array
    {
        $id = $this->sentSecretIdIn($message->body);

        if ($id === null) {
            return null;
        }

        $secret = $this->sentSecrets[$id] ??= SentSecret::query()
            ->with('recipient')
            ->find($id);

        if ($secret === null || $secret->workspace_id !== $message->workspace_id) {
            return null;
        }

        return [
            'id' => $secret->id,
            'label' => $secret->label,
            // The name rather than the id: the card is read, not clicked
            // through, and everybody in the channel gets the same payload.
            'recipientName' => $secret->recipient->name,
            'senderId' => $secret->created_by,
            'expiresAt' => $secret->expires_at,
            'revealedAt' => $secret->revealed_at,
            'state' => $secret->state(),
            'url' => route('sent-secrets.show', $secret->id),
        ];
    }

    /** The id of the first link in this body pointing at one of our secrets. */
    private function sentSecretIdIn(string $body): ?string
    {
        $prefix = route('sent-secrets.show', '__ID__');
        [$before] = explode('__ID__', $prefix, 2);

        $pattern = '/'.preg_quote($before, '/').'([0-9a-hjkmnp-tv-z]{26})\b/i';

        return preg_match($pattern, $body, $matches) === 1
            ? mb_strtolower($matches[1])
            : null;
    }

    /**
     * The id of the first link in this body pointing at our own secret form.
     *
     * Built from the route rather than matched by pattern, for the reason
     * transferTokenIn() is: a change to the URL shape must not leave this
     * quietly matching nothing.
     */
    private function secretRequestIdIn(string $body): ?string
    {
        $prefix = route('secrets.show', '__ID__');
        [$before] = explode('__ID__', $prefix, 2);

        $pattern = '/'.preg_quote($before, '/').'([0-9a-hjkmnp-tv-z]{26})\b/i';

        return preg_match($pattern, $body, $matches) === 1
            ? mb_strtolower($matches[1])
            : null;
    }

    /**
     * What a link to one of our own transfers is holding.
     *
     * The mirror of linkPreview() below, and worth having as its own thing: a
     * transfer link is a token and nothing else, so a channel full of them
     * reads as a wall of noise. What makes this cheap is that nothing is
     * fetched — the route is ours and the answer is a row we already have,
     * where a link preview has to go and ask somebody else's server.
     *
     * @return array<string, mixed>|null
     */
    private function transferCard(Message $message): ?array
    {
        $token = $this->transferTokenIn($message->body);

        if ($token === null) {
            return null;
        }

        $transfer = $this->transfers[$token] ??= Transfer::query()
            ->with('media')
            ->where('token', $token)
            ->first();

        if ($transfer === null) {
            return null;
        }

        /*
         * Only a transfer from the workspace this message is in. A link pasted
         * from elsewhere is somebody else's, and drawing its title here would
         * carry the contents of one workspace into another on the strength of a
         * pasted URL.
         */
        if ($transfer->workspace_id !== $message->workspace_id) {
            return null;
        }

        /*
         * Nothing for a transfer addressed to named people: the shared token
         * opens nothing for that audience, so a card would be advertising
         * something no one reading the channel can open.
         */
        if (! $transfer->audience->opensWithTransferToken()) {
            return null;
        }

        return [
            'title' => $transfer->title,
            'fileCount' => $transfer->files()->count(),
            'size' => $transfer->size(),
            'expiresAt' => $transfer->expires_at,
            // Said here so the channel shows a dead link as dead, rather than
            // leaving somebody to click through and find out.
            'state' => match (true) {
                $transfer->isRevoked() => 'revoked',
                $transfer->hasExpired() => 'expired',
                $transfer->isExhausted() => 'exhausted',
                default => 'usable',
            },
            'isLocked' => $transfer->isLocked(),
            'url' => route('transfers.show', $transfer->token),
        ];
    }

    /**
     * The token of the first link in this body that points at our own transfer
     * page, or null when there is none.
     *
     * Matched against the route rather than by pattern, so a change to the URL
     * shape cannot leave this quietly matching nothing.
     */
    private function transferTokenIn(string $body): ?string
    {
        $prefix = route('transfers.show', '__TOKEN__');
        [$before] = explode('__TOKEN__', $prefix, 2);

        $pattern = '/'.preg_quote($before, '/').'([A-Za-z0-9]{64})\b/';

        return preg_match($pattern, $body, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * The preview for the first link in the message, or null.
     *
     * Read from the cache and never fetched here: this runs while a page is
     * being rendered, and a page that waits on somebody else's server is a page
     * that sometimes does not arrive. Whether anything was ever fetched is the
     * workspace's own decision — see QueueLinkPreviews.
     *
     * @return array<string, mixed>|null
     */
    private function linkPreview(Message $message): ?array
    {
        /*
         * The workspace's own switch, asked here and not only where a look-up
         * is queued.
         *
         * The cache is shared by the whole platform — that is the point of it,
         * one fetch per link rather than one per workspace — so a workspace
         * that never turned previews on would otherwise start showing cards the
         * moment somebody elsewhere pasted the same link. Nothing was fetched
         * on their behalf, but the setting reads as "wij doen dit niet" and it
         * has to mean that on the screen too.
         */
        if (! $this->previewsAllowedIn($message)) {
            return null;
        }

        $url = LinkPreview::firstUrlIn($message->body);

        if ($url === null) {
            return null;
        }

        $preview = $this->linkPreviews[$url] ??= LinkPreview::query()
            ->where('url_hash', LinkPreview::hash($url))
            ->first();

        if ($preview === null || ! $preview->isUsable()) {
            return null;
        }

        return [
            'url' => $preview->url,
            'title' => $preview->title,
            'description' => $preview->description,
            'imageUrl' => $preview->image_url,
            'siteName' => $preview->site_name,
        ];
    }

    /**
     * Whether this message's workspace shows link cards at all.
     *
     * Memoised per workspace for the same reason the blocklist above is: a page
     * draws fifty messages from one workspace, and this must not be fifty
     * reads of the same column.
     */
    private function previewsAllowedIn(Message $message): bool
    {
        return $this->linkPreviewsEnabled[$message->workspace_id] ??=
            (bool) $message->workspace->link_previews_enabled;
    }

    /**
     * The files sent along with the message.
     *
     * Every one of them carries its own URL rather than a raw path: the disk is
     * private, so a file is only reachable through the route that asks the
     * channel whether this reader may see it. Images carry a second URL for the
     * smaller copy, which is what the conversation actually renders.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attachments(Message $message): array
    {
        // The workspace as a model, not as an id: it is addressed by slug in
        // the URL, and an id there resolves to nothing.
        $base = [$message->workspace, $message->channel_id, $message->id];

        return $message->getMedia(Message::ATTACHMENTS)
            ->map(fn (Media $media): array => [
                'id' => $media->id,
                'name' => $media->file_name,
                'mimeType' => $media->mime_type,
                'size' => $media->size,
                'url' => route('chat.messages.attachments.show', [...$base, $media->id]),
                'previewUrl' => $media->hasGeneratedConversion('preview')
                    ? route('chat.messages.attachments.show', [...$base, $media->id, 'c' => 'preview'])
                    : null,
            ])
            ->all();
    }

    /**
     * Who sent this: a member, or a webhook posting under a bot name.
     *
     * A bot carries no id. That is deliberate — the browser compares this id
     * against the signed-in member to decide what it may act on, and a bot must
     * never come out equal to anyone.
     *
     * A guest is marked as one. Somebody reading along in a channel with people
     * from outside should be able to see that at a glance rather than having to
     * remember who was invited for what — the same reason a bot is labelled.
     *
     * @return array{id: int|null, name: string, isBot: bool, isGuest: bool, avatarUrl: string|null}
     */
    private function author(Message $message): array
    {
        if ($message->isFromBot()) {
            return [
                'id' => null,
                'name' => (string) $message->bot_name,
                'isBot' => true,
                'isGuest' => false,

                /*
                 * The face of the workflow that posted it, where there is one.
                 *
                 * Read through the workflow rather than copied onto the message
                 * — the opposite of the name beside it — so that changing the
                 * picture changes it everywhere at once. Null covers three
                 * cases that all draw the same default mark: a workflow with no
                 * avatar, a workflow since deleted, and the application
                 * speaking for itself.
                 */
                'avatarUrl' => $message->workflow?->avatarUrl(),
            ];
        }

        return [
            'id' => $message->author->id,
            'name' => $message->author->name,
            'isBot' => false,
            'isGuest' => isset($this->guestsIn($message->workspace_id)[$message->author->id]),
            'avatarUrl' => $message->author->avatarUrl(),
        ];
    }

    /**
     * The message this one quotes, trimmed to what a quote block shows.
     *
     * One level deep on purpose: quoting a quote would otherwise drag a whole
     * chain of older messages into every payload, and the block only ever draws
     * the top one anyway.
     *
     * A deleted original keeps its place. The reader needs to know that this
     * was an answer to something, and the answer reads as a non sequitur
     * without it — but the text itself stays behind, exactly as it does for a
     * tombstone in the channel.
     *
     * @return array{id: string, author: string, snippet: string, deleted: bool}|null
     */
    private function quoted(Message $message): ?array
    {
        $quoted = $message->quoted;

        if ($quoted === null) {
            return null;
        }

        $deleted = $quoted->isDeleted();

        return [
            'id' => $quoted->id,
            'author' => $this->author($quoted)['name'],
            'snippet' => $deleted ? '' : Str::limit($this->censor($quoted), 160),
            'deleted' => $deleted,
        ];
    }

    /**
     * @return array<int, true>
     */
    private function guestsIn(int $workspaceId): array
    {
        if (isset($this->guestIds[$workspaceId])) {
            return $this->guestIds[$workspaceId];
        }

        $ids = Workspace::query()
            ->whereKey($workspaceId)
            ->firstOrFail()
            ->externalMembers()
            ->pluck('users.id');

        // Built by hand rather than through flip()->map(): a set of ids is what
        // the callers look things up in, and spelling it out is the only way to
        // say "the value is always true" in a type.
        $guests = [];

        foreach ($ids as $id) {
            $guests[(int) $id] = true;
        }

        return $this->guestIds[$workspaceId] = $guests;
    }

    /**
     * The body with the workspace's blocked words masked.
     *
     * Moderation happens on the way out rather than on the way in: the message
     * is stored as it was typed, so a word added to the list later still
     * disappears from everything already said, and an admin can always see what
     * somebody actually wrote.
     */
    private function censor(Message $message): string
    {
        $words = $this->blockedWords[$message->workspace_id] ??=
            $message->workspace->blocked_words;

        return $this->censorBlockedWords->handle($message->body, $words);
    }

    /**
     * Every reaction on a message, grouped by emoji.
     *
     * The user ids travel along rather than a "you reacted" flag. The same
     * summary is broadcast to every subscriber of a channel, so it cannot be
     * written from one member's point of view — each browser decides for itself
     * which pills are its own.
     *
     * @return array<int, array{emoji: string, count: int, userIds: array<int, int>}>
     */
    public function reactions(Message $message): array
    {
        $message->loadMissing('reactions');

        return $message->reactions
            ->sortBy('id')
            ->groupBy('emoji')
            ->map(fn (Collection $group, string $emoji): array => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'userIds' => $group->pluck('user_id')->values()->all(),
            ])->values()->all();
    }

    /**
     * A thread as the sidebar lists it.
     *
     * Deliberately not handle(): that shape carries reactions, and loading
     * those for every row of a list nobody reacts from would cost a query per
     * thread for something the sidebar never draws. What stays shared is the
     * censoring and the author rules — the two things that must never differ
     * between one view of a message and another.
     *
     * The channel is named by id alone: the sidebar hangs each thread under the
     * channel row it belongs to, which already carries the label and the icon.
     *
     * @return array{id: string, channelId: int, author: string, snippet: string, replyCount: int, lastReplyAt: string|null}
     */
    public function threadSummary(Message $message): array
    {
        $deleted = $message->isDeleted();

        return [
            'id' => $message->id,
            'channelId' => $message->channel_id,
            'author' => $this->author($message)['name'],
            // A tombstone keeps its thread reachable but says nothing: the text
            // is still in the database and must not travel out of it.
            'snippet' => $deleted ? '' : Str::limit($this->censor($message), 120),
            'replyCount' => $message->reply_count,
            'lastReplyAt' => ($message->last_reply_at ?? $message->created_at)?->toIso8601String(),
        ];
    }

    /**
     * A pinned message as the bar and the panel list it.
     *
     * Short on purpose, for the same reason threadSummary() is: this list is
     * drawn on every page load of every channel, and reactions nobody shows
     * would cost a query apiece. The censoring and the author rules do stay
     * shared — a word masked in the channel must not reappear in the bar above
     * it.
     *
     * A snippet rather than the whole body: the bar has one line, and the panel
     * links through to the message itself for the rest.
     *
     * @return array{id: string, author: string, snippet: string, pinnedAt: string|null, pinnedBy: string|null}
     */
    public function pinSummary(Message $message): array
    {
        return [
            'id' => $message->id,
            'author' => $this->author($message)['name'],
            'snippet' => Str::limit($this->censor($message), 160),
            'pinnedAt' => $message->pinned_at?->toIso8601String(),
            'pinnedBy' => $message->pinner?->name,
        ];
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    public function pins(Collection $messages): array
    {
        return $messages->map(fn (Message $message) => $this->pinSummary($message))->all();
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    public function collection(Collection $messages): array
    {
        return $messages->map(fn (Message $message) => $this->handle($message))->all();
    }
}
