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
 * PCOM_TEST_DB in the real environment takes over. Nothing to set up for one
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

$database = getenv('PCOM_TEST_DB');

if (is_string($database) && $database !== '') {
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
     */
    $_SERVER['TEST_TOKEN'] = $database;

    /*
     * And the one place TEST_TOKEN does not reach: media-library writes its
     * image conversions through a temporary directory of its own, which
     * defaults to a fixed path under storage. Same hazard, so the same answer.
     */
    $temp = __DIR__."/../storage/media-library/temp-{$database}";

    $_ENV['MEDIA_TEMPORARY_DIRECTORY_PATH'] = $temp;
    $_SERVER['MEDIA_TEMPORARY_DIRECTORY_PATH'] = $temp;
    putenv("MEDIA_TEMPORARY_DIRECTORY_PATH={$temp}");
}
