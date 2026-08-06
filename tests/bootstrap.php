<?php

/*
 * Loaded by PHPUnit before the test suite, after the <php> block in
 * phpunit.xml has been applied — which is what makes this the one place that
 * can override it.
 *
 * It exists for a single problem: phpunit.xml pins DB_DATABASE, so two people
 * running the suite at the same time share one database. Their
 * RefreshDatabase transactions then fight over the same tables and Postgres
 * reports "deadlock detected" — dozens of red tests that have nothing to do
 * with the code, and a round of investigating before anybody realises.
 *
 * POSTDUIF_TEST_DB in the real environment takes over. Nothing to set up for one
 * person working alone; a second one exports it and gets their own.
 */

require __DIR__.'/../vendor/autoload.php';

/*
 * PHPUnit writes the <env> block to $_ENV and putenv() but not to $_SERVER,
 * and Laravel's env() reads $_SERVER first. A real environment variable of the
 * same name therefore beats phpunit.xml — which is what a container does: it
 * passes APP_ENV and DB_DATABASE to the application, and the suite would
 * quietly run as `local`, with CSRF enforced, against the development
 * database. Hundreds of red tests and an emptied database, neither of which
 * points at the cause.
 */
foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
}

$database = getenv('POSTDUIF_TEST_DB');
$database = is_string($database) && $database !== '' ? $database : null;

/*
 * The parallel runner hands every worker process a TEST_TOKEN of its own, and
 * Laravel appends it to the database name and to the Storage::fake() root. That
 * is the same mechanism this file borrows below, so the two have to share it
 * rather than take turns overwriting each other.
 */
$worker = getenv('TEST_TOKEN');
$worker = is_string($worker) && $worker !== '' ? $worker : null;

$token = null;

if ($database !== null) {
    $_ENV['DB_DATABASE'] = $database;
    $_SERVER['DB_DATABASE'] = $database;
    putenv("DB_DATABASE={$database}");

    /*
     * The same isolation for the filesystem, which the database alone does not
     * give. Storage::fake() empties its root before every use and works out
     * that root from storage_path() — a fixed address, so two suites running at
     * once share it whatever databases they were pointed at. One wipes the
     * other's uploads mid-test, and what surfaces is a download answering 404
     * in a test about something else entirely.
     *
     * TEST_TOKEN is what Laravel already namespaces that root with; setting it
     * here borrows the mechanism rather than inventing a second one. On a
     * sequential run nothing else reads it: the provider that would rename the
     * database is deferred, and its callbacks only fire under the parallel
     * runner.
     *
     * Under that runner both halves have to hold at once, so the worker's own
     * number goes on the end rather than in place of the database: two people
     * each running --parallel would otherwise land on tokens 1..n apiece and
     * share every faked disk between them, which is the exact hazard above.
     */
    $token = $worker === null ? $database : "{$database}_{$worker}";

    $_SERVER['TEST_TOKEN'] = $token;
} elseif ($worker !== null) {
    $token = $worker;
}

/*
 * The one place TEST_TOKEN does not reach on its own: media-library writes its
 * image conversions through a temporary directory of its own, which defaults to
 * a fixed path under storage. Same hazard, so the same answer — and it applies
 * to a parallel worker just as much as to a second person running the suite,
 * which is why it sits outside the branch above.
 */
if ($token !== null) {
    $temp = __DIR__."/../storage/media-library/temp-{$token}";

    $_ENV['MEDIA_TEMPORARY_DIRECTORY_PATH'] = $temp;
    $_SERVER['MEDIA_TEMPORARY_DIRECTORY_PATH'] = $temp;
    putenv("MEDIA_TEMPORARY_DIRECTORY_PATH={$temp}");
}
