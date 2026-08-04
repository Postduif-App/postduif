<?php

use App\Actions\Chat\SendMessage;
use App\Enums\InboxItemType;
use App\Models\Channel;
use App\Models\InboxItem;
use App\Models\User;
use App\Models\Workspace;

/**
 * What a code snippet does to the mention parser.
 *
 * Code is full of things shaped like a handle that address nobody — `@media` in
 * a stylesheet, `@param` in a docblock, a Blade `@if`. A chat that notifies on
 * those punishes exactly the member who did the right thing by wrapping their
 * paste in a fence.
 *
 * MessageBody applies the same rule in the browser — see lib/code-blocks.ts and
 * lib/inline-markdown.ts — and the two must not drift: the interface must never
 * highlight a mention the server refuses to send, nor stay quiet about one it
 * does.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function snippetFixture(): array
{
    $author = User::factory()->create();
    $workspace = workspaceWithMember($author);
    $channel = channelWithMember($workspace, $author);

    // Somebody whose handle is a CSS at-rule. Contrived as a name, not as a
    // problem: "media", "if", "param" and "import" are all real usernames.
    $media = User::factory()->create(['username' => 'media']);
    $workspace->members()->attach($media->id, ['joined_at' => now()]);
    $channel->members()->attach($media->id, ['joined_at' => now()]);

    return [$author, $media, $workspace, $channel];
}

/** How many times this person has been named. */
function mentionsOf(User $user): int
{
    return InboxItem::query()
        ->where('user_id', $user->id)
        ->where('type', InboxItemType::Mention)
        ->count();
}

/**
 * The control. Without it the two tests below would pass just as well against a
 * parser that had stopped recording mentions altogether.
 */
it('notifies somebody named in ordinary text', function () {
    [$author, $media, , $channel] = snippetFixture();

    app(SendMessage::class)->handle($channel, $author, '@media kun jij kijken?');

    expect(mentionsOf($media))->toBe(1);
});

it('does not notify somebody named by a fenced code block', function () {
    [$author, $media, , $channel] = snippetFixture();

    app(SendMessage::class)->handle(
        $channel,
        $author,
        "Zo doe je dat:\n```css\n@media (min-width: 40rem) {\n  a { color: red }\n}\n```",
    );

    expect(mentionsOf($media))->toBe(0);
});

it('does not notify somebody named inside backticks', function () {
    [$author, $media, , $channel] = snippetFixture();

    app(SendMessage::class)->handle(
        $channel,
        $author,
        'De regel begint met `@media` en dan een breakpoint.',
    );

    expect(mentionsOf($media))->toBe(0);
});

/**
 * The other half of the rule, and what keeps a fence from becoming a way to
 * silence a mention by accident: the text around a snippet still counts.
 */
it('still notifies somebody named outside the code', function () {
    [$author, $media, , $channel] = snippetFixture();

    app(SendMessage::class)->handle(
        $channel,
        $author,
        "@media kun jij hiernaar kijken?\n```css\n@media screen {}\n```",
    );

    expect(mentionsOf($media))->toBe(1);
});

/**
 * The code span is replaced by a space rather than removed, so the words either
 * side of it cannot fuse into a handle belonging to neither of them.
 */
it('does not weld the text either side of a code span into one handle', function () {
    [$author, $media, , $channel] = snippetFixture();

    app(SendMessage::class)->handle($channel, $author, 'kijk `x`@media even mee');

    expect(mentionsOf($media))->toBe(1);
});
