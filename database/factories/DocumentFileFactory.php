<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentFile>
 */
class DocumentFileFactory extends Factory
{
    /**
     * A picture in a document, described but not written.
     *
     * No bytes are put on the disk here: most tests care about the row and the
     * rules around it, and the two that care about the file itself say so by
     * putting one there. A test that expects bytes and finds none fails
     * loudly, which is better than every unrelated test paying for a write.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'uploaded_by' => null,
            'disk' => 'local',
            'path' => 'documents/1/'.$this->faker->uuid().'.png',
            'name' => $this->faker->word().'.png',
            'mime_type' => 'image/png',
            'size' => $this->faker->numberBetween(1_000, 500_000),
            'width' => 800,
            'height' => 600,
        ];
    }

    /** Something that is not a picture, for the half of the rules that differ. */
    public function pdf(): self
    {
        return $this->state(fn (): array => [
            'name' => 'contract.pdf',
            'mime_type' => 'application/pdf',
            'width' => null,
            'height' => null,
        ]);
    }

    /** Older than the hour of grace ReconcileDocumentFiles gives a new file. */
    public function abandoned(): self
    {
        return $this->state(fn (): array => [
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
    }
}
