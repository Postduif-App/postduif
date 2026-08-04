<?php

use App\Features\Transfers;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Finder\Finder;

use function Pest\Laravel\actingAs;

/**
 * The sentences a call hands the reader, per file, that are typed out rather
 * than looked up.
 *
 * Read from the tokens rather than with a regular expression: the argument that
 * matters is a string among other arguments, sometimes several calls deep, and
 * a pattern that could tell those apart would be harder to trust than this.
 *
 * @param  list<string>  $calls  The function or method names to follow into.
 * @return array<int, string>
 */
function typedOutInCalls(string $file, array $calls): array
{
    $tokens = readableTokens($file);

    $found = [];
    $depth = null;
    $translatedFrom = null;

    foreach ($tokens as $index => $token) {
        $name = is_array($token) && $token[0] === T_STRING ? $token[1] : null;
        $opens = ($tokens[$index + 1] ?? null) === '(';

        if ($name !== null && $opens && in_array($name, $calls, true)) {
            $depth = 0;

            continue;
        }

        if ($depth === null) {
            continue;
        }

        /*
         * __('chat.…') inside the call is the whole point, so the strings it
         * holds are its keys and not the message. Anything nested inside it is
         * skipped until its own parenthesis closes again.
         */
        if ($name !== null && $opens && in_array($name, ['__', 'trans', 'trans_choice'], true) && $translatedFrom === null) {
            $translatedFrom = $depth;
        }

        if ($token === '(') {
            $depth++;
        }

        if ($token === ')') {
            $depth--;

            if ($translatedFrom !== null && $depth <= $translatedFrom) {
                $translatedFrom = null;
            }

            if ($depth <= 0) {
                $depth = null;
            }

            continue;
        }

        if ($translatedFrom !== null) {
            continue;
        }

        if (isSentence($token)) {
            $found[] = trim($token[1], "'\"");
        }
    }

    return $found;
}

/**
 * The same, for whole method bodies rather than for one call.
 *
 * messages() and attributes() on a Form Request are a return statement and
 * nothing else, so there is no call to follow into — the sentence is the value
 * of an array entry. Which makes the method itself the thing to watch.
 *
 * @param  list<string>  $methods
 * @return array<int, string>
 */
function typedOutInMethods(string $file, array $methods): array
{
    $tokens = readableTokens($file);

    $found = [];
    $inside = false;
    $braces = 0;
    $opened = false;
    $translatedFrom = null;
    $depth = 0;

    foreach ($tokens as $index => $token) {
        $name = is_array($token) && $token[0] === T_STRING ? $token[1] : null;
        $declared = is_array($tokens[$index - 1] ?? null) && $tokens[$index - 1][0] === T_FUNCTION;

        if (! $inside && $name !== null && $declared && in_array($name, $methods, true)) {
            $inside = true;
            $braces = 0;
            $opened = false;

            continue;
        }

        if (! $inside) {
            continue;
        }

        if ($token === '{') {
            $braces++;
            $opened = true;
        }

        if ($token === '}') {
            $braces--;

            if ($opened && $braces <= 0) {
                $inside = false;
            }

            continue;
        }

        // Still in the signature: a default value there is not a sentence
        // anybody reads, and the return type may name a class.
        if (! $opened) {
            continue;
        }

        $opens = ($tokens[$index + 1] ?? null) === '(';

        if ($name !== null && $opens && in_array($name, ['__', 'trans', 'trans_choice'], true) && $translatedFrom === null) {
            $translatedFrom = $depth;
        }

        if ($token === '(') {
            $depth++;
        }

        if ($token === ')') {
            $depth--;

            if ($translatedFrom !== null && $depth <= $translatedFrom) {
                $translatedFrom = null;
            }

            continue;
        }

        if ($translatedFrom !== null) {
            continue;
        }

        if (isSentence($token)) {
            $found[] = trim($token[1], "'\"");
        }
    }

    return $found;
}

/** @return array<int, array{0: int, 1: string}|string> */
function readableTokens(string $file): array
{
    return array_values(array_filter(
        token_get_all(file_get_contents($file)),
        fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
}

/**
 * Whether this token is something written for a reader.
 *
 * A space between two letters, which is what separates a sentence from a
 * validation rule ('max:60'), a key ('name.unique') and a class name. Crude on
 * purpose: a rule that needs a dictionary to apply is a rule nobody can predict
 * the verdict of while writing the line.
 */
function isSentence(mixed $token): bool
{
    if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
        return false;
    }

    return preg_match('/\p{L}\s\p{L}/u', trim($token[1], "'\"")) === 1;
}

it('says why in the language of the reader', function () {
    $stranger = User::factory()->create();
    $workspace = workspaceWithMember(User::factory()->create());

    actingAs($stranger)
        ->withHeader('Accept-Language', 'en')
        ->get(route('chat.inbox.index', $workspace))
        ->assertForbidden()
        // The message on a refusal is the one place somebody is stuck, so it is
        // the last place that should still be in a language they cannot read.
        ->assertSee("You're not a member of this workspace.");
});

it('names a feature in the language of the reader', function () {
    App::setLocale('nl');
    expect(Transfers::label())->toBe('Bestanden versturen');

    App::setLocale('en');
    expect(Transfers::label())->toBe('Sending files');
});

it('counts in words the language actually uses', function () {
    App::setLocale('nl');

    // The reason for the explicit {1}/[2,*] form rather than one|many: "Eén" is
    // not "1", and only the spelled-out branch can say so.
    expect(trans_choice('notifications.activity.subject_unread', 1, ['workspace' => 'Postduif']))
        ->toBe('Eén nieuw bericht in Postduif')
        ->and(trans_choice('notifications.activity.subject_unread', 4, ['workspace' => 'Postduif']))
        ->toBe('4 nieuwe berichten in Postduif');

    App::setLocale('en');

    expect(trans_choice('notifications.activity.subject_unread', 1, ['workspace' => 'Postduif']))
        ->toBe('One new message in Postduif');
});

it('has an English word for every Dutch one', function () {
    $missing = [];

    foreach (glob(lang_path('nl/*.php')) as $file) {
        $group = basename($file, '.php');

        $dutch = Arr::dot(require $file);
        $english = Arr::dot(Lang::get($group, [], 'en'));

        foreach (array_keys($dutch) as $key) {
            if (! array_key_exists($key, $english)) {
                $missing[] = "{$group}.{$key}";
            }
        }
    }

    /*
     * The failure this catches is silent in every other test: a key with no
     * English translation falls back to the Dutch one, so an English reader
     * gets a page that is quietly half in the wrong language and nothing
     * anywhere goes red.
     */
    expect($missing)->toBe([]);
});

it('keeps the generated key type in step with the lang files', function () {
    $before = file_get_contents(resource_path('js/types/translations.d.ts'));

    Artisan::call('translations:types');

    /*
     * The file is committed rather than generated at build time, so the type
     * check has something to read on a clean checkout. That trade buys drift:
     * a key added to lang/nl without rerunning the command would leave the
     * frontend unable to name it, and nothing else would say so.
     */
    expect(file_get_contents(resource_path('js/types/translations.d.ts')))
        ->toBe($before, 'Draai `php artisan translations:types` — lang/nl is veranderd.');
});

it('hands the frontend its words in the right language', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    /*
     * The whole chain in one assertion: HandleLocale reads the header,
     * HandleInertiaRequests flattens lang/<locale> into a prop, and the
     * frontend's t() looks the key up in exactly that object. Break any link
     * and this goes red — which no test of the pieces separately would.
     */
    actingAs($user)
        ->withHeader('Accept-Language', 'en')
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale', 'en')
            /*
             * Read out of the whole array rather than by dotted path: the keys
             * themselves contain dots, which is exactly what makes them a flat
             * lookup for t() — and exactly what Inertia's dotted accessor would
             * read as nesting.
             */
            ->where('translations', fn (Collection $lines): bool => $lines['sidebar.headings.channels'] === 'Channels'));

    actingAs($user)
        ->withHeader('Accept-Language', 'nl')
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale', 'nl')
            ->where('translations', fn (Collection $lines): bool => $lines['sidebar.headings.channels'] === 'Kanalen'));
});

/**
 * Everywhere the server writes a sentence that ends up in front of somebody.
 *
 * Named calls rather than every string in app/: the admin panel and the console
 * commands are Dutch on purpose — one is read by whoever runs this application
 * and the other by whoever runs the server — and a rule that could not tell
 * those apart would be a rule nobody could keep.
 *
 * @return array<string, list<string>>
 */
function readerFacingCalls(): array
{
    return [
        // A refusal is the last place somebody can afford not to understand.
        'refusal' => ['abort', 'abort_if', 'abort_unless'],

        /*
         * A validation error is the opposite case and needs the same rule:
         * somebody who has just been stopped is reading every word. The rules
         * and the keys inside these calls are safe — 'max:60' and
         * 'name.unique' have no space between two letters.
         */
        'validation' => ['validate', 'validateWithBag', 'withMessages', 'withErrors'],

        // What comes back with the redirect: the line above a form, the toast
        // in the corner.
        'flash' => ['flash', 'with'],
    ];
}

it('says everything it says to a reader in the language they chose', function (string $what) {
    $typedOut = [];

    foreach (Finder::create()->files()->name('*.php')->in([app_path(), base_path('routes')]) as $file) {
        foreach (typedOutInCalls($file->getRealPath(), readerFacingCalls()[$what]) as $message) {
            $typedOut[] = str_replace(base_path().'/', '', $file->getRealPath()).': '.$message;
        }
    }

    /*
     * The counterpart of the frontend's jsx-no-literals. A sentence typed
     * straight into one of these reaches the reader in whatever language the
     * author happened to think in, and nothing anywhere goes red — which is
     * exactly how the backend came to hold ninety of them while the frontend
     * held none. Put it in lang/nl and lang/en and pass __('chat.…') instead.
     */
    expect($typedOut)->toBe([]);
})->with(['refusal', 'validation', 'flash']);

it('names a field and says what is wrong with it in the reader his language', function () {
    $typedOut = [];

    /*
     * messages() and attributes() are a return statement and nothing else, so
     * there is no call to follow into — which is why these are watched by the
     * method rather than by the call. attributes() matters as much as
     * messages(): a half-translated sentence ("The kanaalnaam field is
     * required") is worse than either language on its own.
     */
    foreach (Finder::create()->files()->name('*.php')->in(app_path('Http/Requests')) as $file) {
        foreach (typedOutInMethods($file->getRealPath(), ['messages', 'attributes']) as $message) {
            $typedOut[] = str_replace(base_path().'/', '', $file->getRealPath()).': '.$message;
        }
    }

    expect($typedOut)->toBe([]);
});
