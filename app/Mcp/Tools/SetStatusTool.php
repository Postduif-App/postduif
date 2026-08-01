<?php

namespace App\Mcp\Tools;

use App\Actions\Users\SetStatus;
use App\Enums\Availability;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * Say what this member is doing.
 *
 * Through the same action the status picker uses, so it is announced to the
 * people who can see it and lands in their own recent list — a status set here
 * is in every way a status they set, because they asked for it.
 *
 * That also means it interacts with their schedule exactly the way typing one
 * does: it wins until the rule window it was set in ends, and then the schedule
 * takes back over. See ApplyStatusRules.
 */
#[Description('Zet de status van deze gebruiker: een emoji, een tekst, en of ze bereikbaar zijn. Laat text leeg om de status weg te halen.')]
class SetStatusTool extends Tool
{
    public function __construct(private readonly SetStatus $setStatus) {}

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        /*
         * A status is one person's, but it is shown to everybody who shares a
         * channel with them — so it is only this tool's business where some
         * workspace has let AI clients in. Where none has, changing how
         * somebody appears to their colleagues would be going around the very
         * switch that says no.
         */
        if ($user->workspacesOpenToAi()->isEmpty()) {
            return Response::error('AI-toegang staat niet aan in een workspace van deze gebruiker.');
        }

        $availability = Availability::tryFrom((string) $request->get('availability', ''))
            // Left as it is when nothing is said: being away is a separate
            // thing from having a status, and "koffie" is no reason to decide
            // somebody is reachable again.
            ?? $user->availability;

        $text = trim((string) $request->get('text', ''));
        $emoji = trim((string) $request->get('emoji', ''));

        $this->setStatus->handle(
            $user,
            $emoji === '' ? null : $emoji,
            $text === '' ? null : $text,
            $availability,
        );

        return Response::json([
            'status' => $text === '' ? null : $text,
            'emoji' => $emoji === '' ? null : $emoji,
            'availability' => $availability->value,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()
                ->description('Waar deze gebruiker mee bezig is. Leeg betekent: haal de status weg.'),
            'emoji' => $schema->string()
                ->description('Eén emoji bij de status, bijvoorbeeld ☕.'),
            'availability' => $schema->string()
                ->description('available, away of do-not-disturb. Laat weg om te houden wat het was.'),
        ];
    }
}
