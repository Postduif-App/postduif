<?php

namespace App\Support\Dns;

/**
 * What a hostname stands for.
 *
 * An interface because the answer is the security decision: whether a URL may
 * be opened turns on which addresses its name resolves to, and a test that has
 * to ask the real DNS to check that is a test that depends on somebody else's
 * zone file — and on this machine's resolver, which happily points *.test at
 * localhost.
 */
interface HostResolver
{
    /**
     * Every address this name stands for, v4 and v6, or an empty list when it
     * stands for nothing.
     *
     * @return array<int, string>
     */
    public function resolve(string $host): array;
}
