<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ghostscript
    |--------------------------------------------------------------------------
    |
    | Every uploaded PDF is rewritten by Ghostscript before it is stored, and
    | the reason is security before it is anything else. A PDF is an executable
    | format: it can carry JavaScript, launch actions and whole files embedded
    | inside it, and this particular document is going to be opened by people
    | outside the workspace who were told to trust it. Ghostscript's pdfwrite
    | device rebuilds the file from its page content, and what does not survive
    | that trip is exactly the part nobody wanted.
    |
    | The second thing it buys is that the rewrite comes out at PDF 1.4, which
    | is the version the free FPDI parser can read. The overlay that produces
    | the signed copy would otherwise fail on any modern PDF — and it would fail
    | at the worst possible moment, after somebody had already signed. Doing it
    | here means a file we cannot process is refused at upload, while the author
    | is still standing there and can pick another one.
    |
    | A path rather than a bare "gs" is worth setting on a server, where the
    | web user's PATH is not the shell's.
    |
    */

    'ghostscript' => env('GHOSTSCRIPT_PATH', 'gs'),

    /*
    |--------------------------------------------------------------------------
    | Wat een contract mag zijn
    |--------------------------------------------------------------------------
    |
    | An upper bound on both the bytes and the pages. The size limit is the
    | ordinary one — somebody dragging in a scanned brochure at 300 dpi. The
    | page limit is about what happens later: the signed copy is composed page
    | by page on a queue, and the editor renders every page in the browser, so a
    | four-hundred-page document is a request that times out at one end and a
    | tab that dies at the other.
    |
    */

    'max_upload_kilobytes' => (int) env('CONTRACTS_MAX_UPLOAD_KB', 20 * 1024),

    'max_pages' => (int) env('CONTRACTS_MAX_PAGES', 50),

    /*
    |--------------------------------------------------------------------------
    | Hoe lang Ghostscript mag doen
    |--------------------------------------------------------------------------
    |
    | Seconds. This runs inside the upload request, so it cannot be allowed to
    | sit there: a malformed PDF that sends the rewriter round in circles would
    | otherwise hold a php-fpm worker until the web server gives up on it.
    |
    */

    'normalise_timeout' => (int) env('CONTRACTS_NORMALISE_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Bewaartermijn
    |--------------------------------------------------------------------------
    |
    | How long an unfinished contract stays around after its deadline passed or
    | it was withdrawn, before the document is removed along with it. Long
    | enough for the ordinary "kun je die van vorige maand nog een keer
    | sturen", short enough that a folder of other people's paperwork is not
    | kept indefinitely.
    |
    | A completed contract is never touched by this. See PruneContracts.
    |
    */

    'grace_days' => (int) env('CONTRACTS_GRACE_DAYS', 30),

];
