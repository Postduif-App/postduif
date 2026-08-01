<?php

namespace App\Actions\Chat;

use App\Enums\WorkspaceRole;
use App\Models\LinkPreview;
use App\Models\Message;
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
     * Previews already looked up, keyed by URL.
     *
     * A page renders fifty messages, and a link pasted five times would
     * otherwise be five identical queries.
     *
     * @var array<string, LinkPreview|null>
     */
    private array $linkPreviews = [];

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
        $message->loadMissing(['author', 'reactions', 'quoted.author', 'pinner', 'media', 'workspace']);

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
        ];
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
        if (preg_match('/\bhttps?:\/\/[^\s<>"\']+/i', $message->body, $matches) !== 1) {
            return null;
        }

        $url = rtrim($matches[0], '.,;:!?)');

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
                // A bot has no face to set.
                'avatarUrl' => null,
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
            ->members()
            ->wherePivot('role', WorkspaceRole::Guest->value)
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
