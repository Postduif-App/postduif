<?php

use Illuminate\Support\Facades\Storage;

/**
 * That a second suite running at the same time cannot reach into this one.
 *
 * PCOM_TEST_DB has always given a session its own database — see
 * tests/bootstrap.php. What it did not give was its own filesystem, and that
 * gap had a symptom nobody would trace back to it: Storage::fake() empties its
 * root before every use and works that root out from a fixed path, so one
 * suite wiped another's uploads mid-test and a download answered 404 in a test
 * about download logging.
 *
 * These run only for a session that asked for isolation. A single run on the
 * default database shares nothing with anybody and has nothing to prove.
 */
beforeEach(function () {
    if (($_SERVER['PCOM_TEST_DB'] ?? getenv('PCOM_TEST_DB') ?: '') === '') {
        $this->markTestSkipped('Only a session that asked for its own database has anything to isolate.');
    }
});

it('gives the faked disk a root of its own', function () {
    $database = $_SERVER['PCOM_TEST_DB'] ?? getenv('PCOM_TEST_DB');

    Storage::fake('local');

    // Laravel already namespaces this by TEST_TOKEN; bootstrap.php borrows that
    // rather than inventing a second mechanism, so the token is the database.
    expect(Storage::disk('local')->path(''))->toContain('_test_'.$database);
});

it('gives media-library a conversion directory of its own', function () {
    $database = $_SERVER['PCOM_TEST_DB'] ?? getenv('PCOM_TEST_DB');

    /*
     * The one path TEST_TOKEN does not reach: media-library resolves this
     * itself and falls back to a fixed address under storage.
     */
    expect(config('media-library.temporary_directory_path'))
        ->toContain('temp-'.$database);
});
