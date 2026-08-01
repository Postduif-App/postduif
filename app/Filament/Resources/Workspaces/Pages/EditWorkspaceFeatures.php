<?php

namespace App\Filament\Resources\Workspaces\Pages;

use App\Features\WorkspaceFeature;
use App\Filament\Resources\Workspaces\WorkspaceResource;
use App\Models\Workspace;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Pennant\Feature;

/**
 * Which parts of the product a workspace offers.
 *
 * A page of its own rather than a section on the edit form, because none of it
 * is a column: the values live in Pennant's own table, and mixing them into a
 * form that saves the model would mean a save doing two unrelated things.
 */
class EditWorkspaceFeatures extends EditRecord
{
    protected static string $resource = WorkspaceResource::class;

    protected static ?string $title = 'Features';

    protected static ?string $navigationLabel = 'Features';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->description('Wat deze workspace aanbiedt. Uitzetten laat bestaande gegevens staan — een uitgezette feature is onbereikbaar, niet weg.')
                ->schema(array_map(
                    fn (string $feature) => Toggle::make($feature::key())
                        ->label($feature::label())
                        ->helperText($feature::description()),
                    WorkspaceFeature::ALL,
                )),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->getRecord();

        foreach ($workspace->featureStates() as $feature => $active) {
            $data[$feature::key()] = $active;
        }

        return $data;
    }

    /**
     * Writes the switches and nothing else.
     *
     * The record is deliberately not saved: every field on this page is a
     * feature, so an $record->update($data) here would be handing the model
     * attributes that are not its own.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        foreach (WorkspaceFeature::ALL as $feature) {
            $active = (bool) ($data[$feature::key()] ?? $feature::default());

            $active
                ? Feature::for($record)->activate($feature)
                : Feature::for($record)->deactivate($feature);
        }

        return $record;
    }
}
