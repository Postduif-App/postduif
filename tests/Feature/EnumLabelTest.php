<?php

use Illuminate\Support\Facades\App;

/**
 * Every enum case, put through every method that hands back words.
 *
 * @return array<int, array{0: string, 1: string, 2: UnitEnum}>
 */
function enumCases(): array
{
    $rows = [];

    foreach (glob(app_path('Enums/*.php')) as $file) {
        $class = 'App\\Enums\\'.basename($file, '.php');

        if (! enum_exists($class)) {
            continue;
        }

        foreach (get_class_methods($class) as $method) {
            $reflection = new ReflectionMethod($class, $method);

            if ($reflection->isStatic() || $reflection->getNumberOfParameters() > 0) {
                continue;
            }

            // Only the ones that hand back words. An enum also carries
            // predicates and colours; those have nothing to translate and a
            // false is not an empty label.
            if ((string) $reflection->getReturnType() !== 'string') {
                continue;
            }

            foreach ($class::cases() as $case) {
                $rows[] = [$class, $method, $case];
            }
        }
    }

    return $rows;
}

it('has words for every case in every language', function () {
    /*
     * The failure this exists for: a match arm that loses a case. PHP throws
     * UnhandledMatchError at the moment somebody happens to hit it — a video
     * attachment, an urgent ticket — which is a runtime error in a method that
     * looks like a lookup table and is never otherwise exercised.
     *
     * It also catches a missing translation key, because __() hands back the
     * key itself and a key is not a label.
     */
    foreach (['nl', 'en'] as $locale) {
        App::setLocale($locale);

        foreach (enumCases() as [$class, $method, $case]) {
            $where = "{$class}::{$case->name}->{$method}() in {$locale}";

            expect($case->$method())
                ->not->toBeEmpty("Leeg: {$where}")
                ->not->toContain('enums.', "Onvertaalde sleutel: {$where}");
        }
    }
});
