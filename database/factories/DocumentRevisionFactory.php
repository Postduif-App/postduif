<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentRevision;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRevision>
 */
class DocumentRevisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = $this->faker->sentence();

        return [
            'document_id' => Document::factory(),
            'created_by' => null,
            'body' => [
                'blok' => [
                    'id' => 'blok',
                    'type' => 'Paragraph',
                    'meta' => ['order' => 0, 'depth' => 0, 'align' => 'left'],
                    'value' => [[
                        'id' => 'el',
                        'type' => 'paragraph',
                        'children' => [['text' => $text]],
                    ]],
                ],
            ],
            'body_text' => $text,
        ];
    }

    /**
     * A revision from a particular moment.
     *
     * A state rather than something a test passes to create(), because
     * created_at is not fillable — a revision records when it happened and
     * nothing outside should be able to say otherwise. Tests about ageing and
     * pruning need to, so they say so here, once, out loud.
     */
    public function writtenAt(CarbonInterface $moment): self
    {
        return $this->state(fn (): array => ['created_at' => $moment]);
    }

    /** With text a test can recognise again. */
    public function saying(string $text): self
    {
        return $this->state(fn (): array => [
            'body' => [
                'blok' => [
                    'id' => 'blok',
                    'type' => 'Paragraph',
                    'meta' => ['order' => 0, 'depth' => 0, 'align' => 'left'],
                    'value' => [[
                        'id' => 'el',
                        'type' => 'paragraph',
                        'children' => [['text' => $text]],
                    ]],
                ],
            ],
            'body_text' => $text,
        ]);
    }
}
