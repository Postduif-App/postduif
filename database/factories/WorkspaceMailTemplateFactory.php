<?php

namespace Database\Factories;

use App\Enums\MailTemplateKind;
use App\Models\Workspace;
use App\Models\WorkspaceMailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceMailTemplate>
 */
class WorkspaceMailTemplateFactory extends Factory
{
    /**
     * A row that says nothing, which behaves exactly like no row.
     *
     * The default on purpose: most tests here are about what happens when one
     * field is filled in, and starting from "everything overridden" would make
     * every one of them prove several things at once.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'kind' => MailTemplateKind::ContractRequest,
            'locale' => 'nl',
        ];
    }

    public function kind(MailTemplateKind $kind): static
    {
        return $this->state(['kind' => $kind]);
    }

    public function locale(string $locale): static
    {
        return $this->state(['locale' => $locale]);
    }

    /** A text of one's own, button placeholder and all. */
    public function written(): static
    {
        return $this->state([
            'subject' => 'Graag je handtekening onder {{titel}}',
            'heading' => 'Even tekenen',
            'body' => "Beste {{ondertekenaar}},\n\n{{afzender}} heeft \"{{titel}}\" voor je klaargezet.\n\n{{knop}}\n\nTot ziens.",
            'button_label' => 'Nu tekenen',
        ]);
    }
}
