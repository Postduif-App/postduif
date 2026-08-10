<?php

namespace App\Support;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * What is on this platform, in the numbers whoever runs it would ask for,
 * shown under `php artisan about`.
 *
 * That is a deliberate home rather than a new command. Somebody who has just
 * pulled a deploy or opened a shell on a server they administer for somebody
 * else already runs `about` to find out what they are standing in; the answer
 * to "and how much is in it" belongs on the same screen as the PHP version and
 * the queue driver, not behind a name they would have to know to type.
 *
 * The counts are of the platform, not of a workspace. Nothing here is scoped
 * to a member and nothing here is reachable from the web — a shell on the
 * server is already past every door this application has, which is why these
 * are safe to state plainly and why the same numbers per workspace would not
 * belong here.
 *
 * Registered by class name in AppServiceProvider, which the about command
 * resolves through the container and calls only while rendering. Five queries
 * that never run during a web request.
 *
 * The order of the keys below is not the order they appear in: the about
 * command sorts every section but Environment alphabetically. Labels are
 * therefore chosen to fall beside what they belong with — the channel kinds all
 * start with "Kanalen" — rather than arranged here, where it would have no
 * effect.
 */
class PlatformStatistics
{
    public function __construct(private SocketPresence $sockets) {}

    /**
     * @return array<string, string>
     */
    public function __invoke(): array
    {
        return [
            ...$this->counted(),
            ...$this->socketLines(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function counted(): array
    {
        try {
            return $this->gather();
        } catch (QueryException) {
            /*
             * `about` is one of the first things somebody runs on a checkout
             * that has never been migrated, and one of the few things left that
             * still runs when the database is down — which is exactly when
             * somebody is looking at it. Losing the PHP version and the cache
             * driver to a failed count would take the screen away at the moment
             * it is most wanted, so the section says so and stands aside.
             */
            return ['Statistics' => 'database unreachable'];
        }
    }

    /**
     * @return array<string, string>
     */
    private function gather(): array
    {
        $users = $this->users();
        $channels = $this->channels();
        $messages = $this->messages();
        $media = $this->media();

        return [
            'Workspaces' => $this->number(Workspace::query()->count()),
            'Users' => $this->usersLine($users),
            'Channels' => $this->number(array_sum($channels)),
            ...$this->channelsPerType($channels),
            'Messages' => $this->messagesLine($messages),
            'Attachments' => $this->mediaLine($media[Message::ATTACHMENTS] ?? null),
            'Transfers' => $this->mediaLine($media[Transfer::FILES] ?? null),
        ];
    }

    /**
     * `count(suspended_at)` rather than a second query: counting a column
     * counts the rows where it is not null, which is the definition of a
     * suspension, and it comes back in the same trip as the total.
     *
     * @return array{totaal: int, geschorst: int}
     */
    private function users(): array
    {
        $row = User::query()
            ->toBase()
            ->selectRaw('count(*) as totaal, count(suspended_at) as geschorst')
            ->first();

        return [
            'totaal' => (int) ($row->totaal ?? 0),
            'geschorst' => (int) ($row->geschorst ?? 0),
        ];
    }

    /**
     * @return array<string, int> Keyed by ChannelType value.
     */
    private function channels(): array
    {
        return Channel::query()
            ->toBase()
            ->groupBy('type')
            ->selectRaw('type, count(*) as aantal')
            ->pluck('aantal', 'type')
            ->map(fn ($aantal): int => (int) $aantal)
            ->all();
    }

    /**
     * Through the Eloquent builder, so the soft-delete scope comes with it: a
     * message somebody has taken back is not one this platform is carrying.
     *
     * `count(parent_id)` is the thread replies, on the same principle as the
     * suspension count above — a reply is a message with a parent.
     *
     * @return array{totaal: int, antwoorden: int}
     */
    private function messages(): array
    {
        $row = Message::query()
            ->toBase()
            ->selectRaw('count(*) as totaal, count(parent_id) as antwoorden')
            ->first();

        return [
            'totaal' => (int) ($row->totaal ?? 0),
            'antwoorden' => (int) ($row->antwoorden ?? 0),
        ];
    }

    /**
     * Both media collections in one grouped query — message attachments and
     * transfer files live in the same table and differ only by collection.
     *
     * Deliberately not filtered by whether the owning message still stands.
     * Media library keeps the file when a message is soft-deleted, so a number
     * that skipped those would describe the chat rather than the disk, and the
     * disk is what somebody reading this is about to have to pay for.
     *
     * @return array<string, array{aantal: int, bytes: int}>
     */
    private function media(): array
    {
        return Media::query()
            ->toBase()
            ->groupBy('collection_name')
            ->selectRaw('collection_name, count(*) as aantal, coalesce(sum(size), 0) as bytes')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $row->collection_name => [
                    'aantal' => (int) $row->aantal,
                    'bytes' => (int) $row->bytes,
                ],
            ])
            ->all();
    }

    /**
     * @param  array{totaal: int, geschorst: int}  $users
     */
    private function usersLine(array $users): string
    {
        return $this->number($users['totaal'])
            .$this->parenthetical($users['geschorst'] > 0
                ? $this->number($users['geschorst']).' suspended'
                : null);
    }

    /**
     * A line per kind, each one nothing but a number — the same shape as every
     * other line in the section, so the column reads as a column instead of as
     * a total with a sentence hanging off it.
     *
     * A kind with nothing in it still gets its line: the point of the split is
     * that the parts add up to the total above them, and a kind that vanishes
     * when it is empty makes the reader work out which one is missing.
     *
     * Prefixed with "Channels" so the four land together — the about command
     * sorts a section's lines alphabetically, so shared prefixes are the only
     * grouping there is.
     *
     * The kind comes from the case name rather than getLabel(): the labels are
     * translated and this screen is not, so a Dutch installation would
     * otherwise get "Channels Openbaar". The name is still the enum's own word,
     * so a kind renamed once is renamed here too.
     *
     * @param  array<string, int>  $channels
     * @return array<string, string>
     */
    private function channelsPerType(array $channels): array
    {
        $lines = [];

        foreach (ChannelType::cases() as $type) {
            $label = 'Channels '.Str::lower($type->name);

            $lines[$label] = $this->number($channels[$type->value] ?? 0);
        }

        return $lines;
    }

    /**
     * @param  array{totaal: int, antwoorden: int}  $messages
     */
    private function messagesLine(array $messages): string
    {
        return $this->number($messages['totaal'])
            .$this->parenthetical($messages['antwoorden'] > 0
                ? $this->number($messages['antwoorden']).' in threads'
                : null);
    }

    /**
     * @param  array{aantal: int, bytes: int}|null  $collection
     */
    private function mediaLine(?array $collection): string
    {
        if ($collection === null) {
            return '0';
        }

        /*
         * maxPrecision rather than precision, so the decimal appears only when
         * it carries something: "1,4 GB" is the number somebody came for and
         * "64,0 B" is a rounding artefact wearing its clothes.
         */
        return $this->number($collection['aantal'])
            .$this->parenthetical(Number::fileSize($collection['bytes'], maxPrecision: 1));
    }

    /**
     * The only two lines here that do not come from the database.
     *
     * Kept inside the same section rather than given one of their own, because
     * "how much is on this platform" and "how much of it is in use right now"
     * are read together — and outside the try/catch above, because a websocket
     * server that is down is a different failure from a database that is, and
     * it says so on its own line instead of taking the counts with it.
     *
     * @return array<string, string>
     */
    private function socketLines(): array
    {
        $snapshot = $this->sockets->snapshot();

        if ($snapshot === null) {
            /*
             * Not "0". Nobody online and no server running are the same number
             * and opposite situations, and this line exists for somebody
             * working out which one they are looking at.
             */
            return ['Online' => 'reverb unreachable'];
        }

        return [
            'Online' => $this->number($snapshot['people']),
            'Socket connections' => $this->number($snapshot['connections']),
        ];
    }

    /**
     * A detail worth reading only when there is one — "(0 geschorst)" on every
     * healthy platform is noise that teaches the eye to skip the brackets.
     */
    private function parenthetical(?string $detail): string
    {
        return $detail === null || $detail === '' ? '' : ' ('.$detail.')';
    }

    /**
     * `Number::format` hands back `string|false`, because the intl formatter
     * underneath it is allowed to fail. It will not here — these are integers
     * counted a line ago — but the type is honest and every line in this
     * section is a string, so the widening is settled once here rather than
     * cast at each of the ten call sites.
     */
    private function number(int|float $value): string
    {
        return (string) Number::format($value);
    }
}
