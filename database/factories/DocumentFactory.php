<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            // Declared before the two closures below, because attributes are
            // expanded in the order they are written and both of them need a
            // channel that has already resolved.
            'channel_id' => Channel::factory(),
            'workspace_id' => fn (array $attributes) => Channel::find((int) $attributes['channel_id'])?->workspace_id,

            // Through the counter rather than a random number, so a test that
            // makes three documents gets #1, #2 and #3 the way the application
            // hands them out.
            'number' => fn (array $attributes) => Workspace::find((int) $attributes['workspace_id'])?->claimDocumentNumber() ?? 1,

            'title' => $title,
            'body' => Document::emptyBody(),
            'body_text' => '',
            'created_by' => User::factory(),
        ];
    }

    /**
     * A document with something actually in it.
     *
     * The document shape is Yoopta's, taken from what its own buildBlockData()
     * produces rather than written from the docs: a map of block id to block,
     * each block holding Slate elements. Tests that only need a document to exist
     * should not pay for this; tests about reading, searching or rendering
     * should not have to hand-write it.
     *
     * @param  list<string>  $paragraphs
     */
    public function withBody(array $paragraphs = []): static
    {
        $paragraphs = $paragraphs === [] ? [fake()->paragraph(), fake()->paragraph()] : $paragraphs;

        $body = [];

        foreach ($paragraphs as $order => $text) {
            $blockId = (string) Str::uuid();

            $body[$blockId] = [
                'id' => $blockId,
                'type' => 'Paragraph',
                'meta' => ['order' => $order, 'depth' => 0],
                'value' => [[
                    'id' => (string) Str::uuid(),
                    'type' => 'paragraph',
                    'children' => [['text' => $text]],
                    'props' => ['nodeType' => 'block'],
                ]],
            ];
        }

        return $this->state([
            'body' => $body,
            // What the editor's getPlainText() would have sent along.
            'body_text' => implode("\n", $paragraphs),
        ]);
    }

    /**
     * A document somebody has already saved over, so version is past its start.
     *
     * The conflict check compares versions, and a test for it needs a document
     * whose version is not the one a fresh row happens to have.
     */
    public function atVersion(int $version): static
    {
        return $this->state(['version' => $version]);
    }
}
