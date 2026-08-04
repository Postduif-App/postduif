<?php

return [

    /*
     * What a workflow may do when it reaches outside this application.
     *
     * The HTTP step is the one action that talks to something we do not own, so
     * it is also the one place where a beheerder can point this server at an
     * address of their choosing. Everything here is a bound on how much that
     * can be worth to somebody who means it badly.
     */
    'http' => [

        /*
         * Long enough for an ordinary API, short enough that a queue worker is
         * never held for meaningful time by something that will not answer.
         */
        'timeout' => (int) env('WORKFLOW_HTTP_TIMEOUT', 10),

        /*
         * How much of an answer is read at all.
         *
         * Whatever comes back is written into the run's memory, which is a
         * jsonb column read on the run screen — so this is both a memory bound
         * and a bound on how much of somebody else's data ends up in our
         * database. The same ceiling the webhook trigger uses, for the same
         * reasons.
         */
        'max_response_bytes' => 16384,

        /*
         * Whether the step may reach addresses inside the network this server
         * stands in.
         *
         * Off, and it has to stay off anywhere real. A workspace beheerder is
         * not an infrastructure administrator: without this, "doe een verzoek
         * naar deze URL" is a way to read the cloud metadata endpoint, knock on
         * internal admin panels, and port-scan the private network from a
         * machine that is allowed to.
         *
         * On is for a development machine, where the thing worth calling is
         * usually running on localhost.
         */
        'allow_private_hosts' => (bool) env('WORKFLOW_HTTP_ALLOW_PRIVATE', false),
    ],
];
