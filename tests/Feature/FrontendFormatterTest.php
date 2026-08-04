<?php

use Symfony\Component\Finder\Finder;

/**
 * Every .tsx and .ts file under resources/js.
 *
 * @return array<int, SplFileInfo>
 */
function frontendSources(): array
{
    return iterator_to_array(
        Finder::create()->files()->in(resource_path('js'))->name(['*.ts', '*.tsx']),
        false
    );
}

it('builds no formatter with a language written into it', function () {
    /*
     * Intl takes a locale, and a locale typed in by hand is one the reader
     * never gets a say in — an English page reading "4 augustus" is the failure
     * this catches. useFormats() reads the locale the middleware settled on.
     *
     * Worth a test rather than a review note: these live in module constants,
     * outside any component, where a hook cannot reach. That is what made the
     * fixed locale look like the only option the first eight times.
     */
    $offenders = [];

    foreach (frontendSources() as $file) {
        $contents = $file->getContents();

        if ($file->getRelativePathname() === 'hooks/use-formats.ts') {
            continue;
        }

        if (preg_match("/(?:Intl\.\w+Format\(|toLocale\w*String\()\s*'[a-z]{2}(?:-[A-Z]{2})?'/", $contents)) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('offers a shape for every stamp the app shows', function () {
    $hook = file_get_contents(resource_path('js/hooks/use-formats.ts'));

    /*
     * The list is what stops the next date being formatted inline "just this
     * once". A name missing here is the moment somebody reaches for Intl again.
     */
    foreach ([
        'day', 'time', 'date', 'moment', 'dayTime',
        'shortDate', 'mediumDate', 'longDate',
        'shortDateTime', 'dateTime', 'longDateTime',
        'number', 'names',
    ] as $shape) {
        expect($hook)->toContain($shape.': new Intl');
    }
});
