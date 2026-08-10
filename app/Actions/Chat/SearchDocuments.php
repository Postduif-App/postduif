<?php

namespace App\Actions\Chat;

use App\Features\Documents as DocumentsFeature;
use App\Models\Channel;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Search the documents of a workspace, scoped to the channels the member may
 * read.
 *
 * Its own action beside SearchMessages rather than a branch inside it. The two
 * answer differently shaped questions — a message hit is a moment in a
 * conversation, a document hit is a document that is still being maintained — and
 * folding them into one result list would mean inventing a shape that fits
 * neither and ranking them against each other, which nothing sensible can do.
 *
 * @phpstan-type DocumentHit array{id: int, number: int, title: string, snippet: string, updatedAt: string|null, channel: array{id: int, name: string|null}}
 */
class SearchDocuments
{
    public function __construct(
        private readonly CensorBlockedWords $censorBlockedWords,
    ) {}

    /**
     * @param  Channel|null  $channel  Narrow to one channel, the way "in:" does
     *                                 for messages.
     * @return array<int, array<string, mixed>>
     */
    public function handle(
        Workspace $workspace,
        User $user,
        string $terms,
        ?Channel $channel = null,
        int $limit = 10,
    ): array {
        /*
         * Nothing at all when the workspace does not offer documents. The
         * documents may still exist — switching a feature off does not delete
         * anything — and a search that surfaced them would be the one door left
         * open in a feature that is supposed to be shut.
         */
        if (! $workspace->hasFeature(DocumentsFeature::class)) {
            return [];
        }

        $terms = trim($terms);

        if ($terms === '') {
            return [];
        }

        $visibleChannelIds = $workspace->channels()
            ->visibleTo($user)
            ->pluck('id');

        if ($channel !== null) {
            $visibleChannelIds = $visibleChannelIds->intersect([$channel->id]);
        }

        return Document::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('channel_id', $visibleChannelIds)
            /*
             * And the channel has to still keep documents. A channel that
             * switched them off keeps its documents but no longer shows them,
             * and a search hit would be a link to a tab that is not there.
             */
            ->whereIn('channel_id', $workspace->channels()
                ->whereNot('document_policy', 'disabled')
                ->select('id'))
            ->matching($terms)
            ->with('channel:id,name,type')
            ->inListOrder()
            ->limit($limit)
            ->get()
            ->map(fn (Document $document): array => [
                'id' => $document->id,
                'number' => $document->number,
                'title' => $this->censor($document->title, $workspace),
                /*
                 * A window around the match rather than the opening line: a
                 * runbook's first paragraph says the same thing for every hit
                 * in it, which tells the searcher nothing about why this one
                 * came back.
                 */
                'snippet' => $this->snippet($document->body_text, $terms, $workspace),
                'updatedAt' => $document->updated_at?->toIso8601String(),
                'channel' => [
                    'id' => $document->channel->id,
                    'name' => $document->channel->name,
                ],
            ])
            ->all();
    }

    /**
     * The part of the document around the first term that appears in it.
     */
    private function snippet(string $text, string $terms, Workspace $workspace): string
    {
        $text = Str::squish($text);

        if ($text === '') {
            return '';
        }

        $at = null;

        foreach (preg_split('/\s+/', $terms) ?: [] as $term) {
            if ($term === '') {
                continue;
            }

            $found = mb_stripos($text, $term);

            if ($found !== false) {
                $at = $found;

                break;
            }
        }

        /*
         * No term found in the body means the hit came from the title, which is
         * weighted above it. Then the opening line is the right thing to show.
         */
        if ($at === null) {
            return $this->censor(Str::limit($text, 160), $workspace);
        }

        $start = max(0, $at - 60);
        $window = mb_substr($text, $start, 200);

        return $this->censor(
            ($start > 0 ? '…' : '').$window.(mb_strlen($text) > $start + 200 ? '…' : ''),
            $workspace,
        );
    }

    /**
     * The blocklist, applied on the way out — the same as messages, and for the
     * same reason: a word added to the list today also disappears from a
     * document written last month.
     */
    private function censor(string $text, Workspace $workspace): string
    {
        return $this->censorBlockedWords->handle($text, $workspace->blocked_words);
    }
}
