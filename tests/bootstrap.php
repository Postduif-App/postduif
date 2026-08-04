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
}
