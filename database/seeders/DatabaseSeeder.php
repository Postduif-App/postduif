<?php

namespace Database\Seeders;

use App\Actions\Chat\SendMessage;
use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Handles are spelled out rather than left to the factory: the seeded
        // conversations mention them by name, and a random handle would make
        // those mentions silently render as plain text.
        $sebastiaan = User::factory()->create([
            'name' => 'Sebastiaan',
            'username' => 'sebastiaan',
            'email' => 'test@example.com',
        ]);

        [$fenna, $joris, $amara] = User::factory()->createMany([
            ['name' => 'Fenna de Vries', 'username' => 'fenna', 'email' => 'fenna@example.com'],
            ['name' => 'Joris Bakker', 'username' => 'joris', 'email' => 'joris@example.com'],
            ['name' => 'Amara Okafor', 'username' => 'amara', 'email' => 'amara@example.com'],
        ])->all();

        $teammates = collect([$fenna, $joris, $amara]);

        $workspace = Workspace::factory()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'owner_id' => $sebastiaan->id,
        ]);

        $workspace->members()->attach($sebastiaan->id, [
            'role' => WorkspaceRole::Owner->value,
            'joined_at' => now(),
        ]);

        foreach ($teammates as $teammate) {
            $workspace->members()->attach($teammate->id, [
                'role' => WorkspaceRole::Member->value,
                'joined_at' => now(),
            ]);
        }

        $everyone = collect([$sebastiaan, $fenna, $joris, $amara]);

        $general = $this->channel($workspace, 'algemeen', 'Alles wat niet ergens anders past', $everyone);
        $dev = $this->channel($workspace, 'development', 'Deploys, PRs en gezeur over types', $everyone);
        $random = $this->channel($workspace, 'random', 'Koffie, katten, kerstborrel', $everyone->take(3));

        $design = Channel::factory()->private()->create([
            'workspace_id' => $workspace->id,
            'name' => 'design-intern',
            'slug' => 'design-intern',
            'topic' => 'Nog niet klaar om te delen',
            'created_by' => $sebastiaan->id,
        ]);
        $this->addMembers($design, collect([$sebastiaan, $fenna]));

        $dm = Channel::factory()->direct()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $sebastiaan->id,
        ]);
        $this->addMembers($dm, collect([$sebastiaan, $joris]));

        $this->conversation($general, [
            [$sebastiaan, 'Goedemorgen allemaal — de nieuwe werkplek staat live.'],
            [$fenna, 'Ziet er strak uit. Zoeken werkt ook al?'],
            [$sebastiaan, 'Ja, full-text via Postgres. Probeer maar eens iets te zoeken.'],
            [$amara, 'Mooi. Ik zet de onboarding-notities er zo in.'],
            [$joris, 'Kun jij daar even naar kijken @sebastiaan? Dan doe ik de rest.'],
        ]);

        $thread = $this->conversation($dev, [
            [$joris, 'De migratie naar Postgres is doorgevoerd op staging.'],
            [$sebastiaan, 'Top. Draaien de tests er ook tegenaan?'],
            [$joris, 'Ja, tegen pcom_testing. Wel wat trager dan SQLite, maar realistischer.'],
        ])->first();

        $this->replies($thread, [
            [$fenna, 'Hoeveel trager ongeveer?'],
            [$joris, 'Van 0,4s naar 2,3s voor de hele suite. Prima te doen.'],
        ]);

        $this->conversation($random, [
            [$amara, 'Iemand nog zin in koffie?'],
            [$fenna, 'Altijd.'],
        ]);

        $this->conversation($design, [
            [$sebastiaan, 'Eerste opzet voor de nieuwe sidebar staat in Figma.'],
            [$fenna, 'Ik kijk er vanmiddag naar, @sebastiaan.'],
        ]);

        $this->conversation($dm, [
            [$joris, 'Heb je de deploy-sleutels al rondgestuurd?'],
            [$sebastiaan, 'Komt eraan, vanmiddag.'],
        ]);
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function channel(Workspace $workspace, string $name, string $topic, Collection $members): Channel
    {
        $channel = Channel::factory()->create([
            'workspace_id' => $workspace->id,
            'type' => ChannelType::Public,
            'name' => $name,
            'slug' => $name,
            'topic' => $topic,
            'created_by' => $members->first()->id,
        ]);

        $this->addMembers($channel, $members);

        return $channel;
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function addMembers(Channel $channel, Collection $members): void
    {
        foreach ($members as $member) {
            $channel->members()->attach($member->id, ['joined_at' => now()]);
        }
    }

    /**
     * @param  array<int, array{0: User, 1: string}>  $lines
     * @return Collection<int, Message>
     */
    private function conversation(Channel $channel, array $lines): Collection
    {
        $sendMessage = app(SendMessage::class);

        return collect($lines)->map(
            fn (array $line): Message => $sendMessage->handle($channel, $line[0], $line[1])
        );
    }

    /**
     * @param  array<int, array{0: User, 1: string}>  $lines
     */
    private function replies(Message $parent, array $lines): void
    {
        $sendMessage = app(SendMessage::class);

        foreach ($lines as $line) {
            $reply = $sendMessage->handle($parent->channel, $line[0], $line[1], $parent->id);

            Reaction::create([
                'message_id' => $reply->id,
                'user_id' => $line[0]->id,
                'emoji' => '👍',
            ]);
        }
    }
}
