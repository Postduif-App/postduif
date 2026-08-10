<?php

/*
 * The public site: the shell around every page, and the copy on them.
 *
 * Here rather than inline in the components, because the site answers in the
 * reader's language — see HandleLocale, which picks it off Accept-Language for
 * somebody who has no account and so has never set anything.
 *
 * What comes from the application is deliberately not repeated here. Every
 * feature, role and channel setting the home page lists is read off the classes
 * that implement them and is already translated where those live; see
 * BuildFeatureInventory for why that is a rule rather than a convenience.
 */

return [
    'nav' => [
        'to_app' => 'Go to the app',
        'login' => 'Log in',
        'start' => 'Get started',
        'api' => 'API',
    ],

    'footer' => [
        'tagline' => 'A workplace for conversations, the work that follows from them and the files that go with it.',
        // The arrow says you are leaving the site; the link opens in a tab.
        'source' => 'Source on GitHub ↗',
        // Which edition this site belongs to. A month name, so it is translated.
        'edition' => 'August 2026',
    ],

    'home' => [
        'eyebrow' => 'Guests from outside, without showing them the rest',
        // De titel in het tabblad; zonder punt, anders dan de kop op de pagina.
        'head' => 'The conversation and the work in one place',
        'headline' => 'The conversation and the work in one place.',
        'intro' => 'Channels and threads, tickets for what would otherwise be forgotten, and files too big to email. Customers join as guests and see only their own channels.',
        'cta_start' => 'Get started →',
        'cta_login' => 'Log in →',
        /*
         * Beside the button rather than in the intro: for most visitors, that
         * it costs nothing is the answer to the question they are asking at
         * the button, and the source is where that can be checked.
         */
        'source' => 'Free and open source — source on GitHub ↗',
        /*
         * Two counts in one sentence, so two keys: the number of features
         * decides one plural, and "off by default" hangs off a different
         * number that would take the wrong one in the same line.
         */
        'feature_count' => '{1} :count feature|[0,*] :count features',
        'opt_in_count' => '{1} :count off by default|[0,*] :count off by default',

        'features' => [
            'title' => 'What is in it',
            'lead' => 'Every feature below exists as a class in the code, under this name and with this description. What is not listed is not there.',
            'off_by_default' => 'OFF BY DEFAULT',
        ],

        'opt_in' => [
            'title' => 'You switch it on',
            'lead' => 'These stay off until somebody turns them on. They are precisely the ones that let something reach beyond your workspace.',
        ],

        'channels' => [
            'title' => 'A channel shaped like the conversation',
            'lead' => 'A channel is not something you switch on, so it is not in the list above — even though it is where you spend the whole day. These are the knobs underneath it.',
            'layout' => 'Layout',
            'posting' => 'Who posts',
            'tickets' => 'Tickets',
        ],

        'workflow' => [
            'title' => 'Things your workspace does by itself',
            // The two counts come from the registry, not from this sentence.
            'lead' => 'A workflow is one trigger followed by a run of steps, with conditions and branches in between. :triggers triggers and :actions steps to choose from.',
            'when' => 'When',
            'then' => 'What happens next',
        ],

        'api' => [
            'title' => 'For your own script and your AI client',
            'lead' => 'Two doors, each with its own key: a personal token for your own script, OAuth for an AI client that signs itself up. What is behind them is exactly what you are allowed to see — every call goes through the same rules as the screen.',
            'endpoints' => 'The API',
            'tools' => 'What an AI client can do, once you allow it',
            'note' => 'An AI client signs in with OAuth and asks your permission; you see on a Postduif screen what it may do, and withdraw it again in one click. And it is off per workspace by default: while that switch is off, nothing comes in or goes out this way.',
        ],

        'roles' => [
            'title' => 'Who may do what',
            'lead' => 'Four roles to start with, and after that you make your own. A role is no more than a name and a handful of rights from the list below — a guest is one of them: somebody from outside, who sees only the channels they were invited to.',
            'ability' => 'Right',
            'browse' => 'See the workspace',
            'note' => 'This is where a workspace begins, not where it stays. The roles live in the workspace itself: an administrator renames them, ticks rights on and off, and adds as many as they need — a Supplier, an Intern, a Bookkeeper. The list of rights is fixed, though, because every right here is enforced somewhere; one you could invent yourself would be a tickbox that does nothing.',
            'ceiling' => 'One rule keeps it closed: nobody can hand out a right they do not hold themselves. That is why the owner starts with everything — a right nobody held could never be switched on by anyone again.',
            'browse_note' => 'The top row is not a right but a property of the role. It decides not what you may do with the workspace but whether it is there for you at all: what a guest may not see does not exist for them — and that is a question asked in the database, not in a tickbox.',
            'yes' => 'yes',
            'no' => 'no',
        ],
    ],

    'docs' => [
        'head' => 'API',
        'title' => 'The API',
        'intro' => 'Small, and deliberately kept that way. Every call goes through the same rules as the screen: what you may not see, this will not return either, and a message arriving here goes through the same action as one you type.',

        'wants' => 'What it wants',
        'returns' => 'What it returns',
        // The number comes from the rate limiter, not from this sentence.
        'rate_limit' => '{1} At most :count per minute|[0,*] At most :count per minute',

        'auth' => [
            'title' => 'Signing in',
            'lead' => 'A personal token belongs to you, not to a workspace. You make one under Settings → API tokens, and you see it once.',
            'header' => 'The header',
            'note' => 'Every failure gives the same answer: 401, without saying whether the token does not exist, has been revoked, or belongs to a deleted account. That is deliberate — telling them apart is telling somebody which of their guesses was closer.',
        ],

        'token' => [
            'title' => 'With your own token',
            'lead' => 'Everything below is about the member whose token you send. That is why there is no id anywhere in the path: there is no way to reach somebody else.',
            'note' => 'A workspace lets nothing in with a token by default. While that switch is off, the channel list returns nothing from it and posting there answers 404 — the same answer as a channel that does not exist, because the difference would give away what does.',
        ],

        'webhooks' => [
            'title' => 'Without anybody\'s token',
            'lead' => 'A webhook carries its key in the path, because that is what the tools pointing at it expect. So it ends up in logs — and that is exactly why it can be revoked and minted again.',
        ],

        'ai' => [
            'title' => 'For an AI client',
            'lead' => 'An AI client signs in with OAuth and asks your permission. This is what it can do afterwards — the same rules, the same limits.',
            'tools' => 'The tools',
        ],
    ],

    /*
     * What each endpoint does, alongside BuildApiReference — that holds the
     * shape (which keys exist, what they take, what comes back) and this is the
     * prose around it. The key is the route name with its dots as underscores.
     */
    'api' => [
        'api_v1_status_show' => [
            'summary' => 'The status of the member whose token you send.',
        ],
        'api_v1_status_update' => [
            'summary' => 'Set your own status. It goes through the same action as the screen, so a status rule whose turn comes later takes over again.',
            'params' => [
                'availability' => ['rule' => 'required', 'about' => 'available, away or do-not-disturb'],
                'emoji' => ['rule' => 'optional, max 16', 'about' => 'One emoji; several code points do not count as one character'],
                'text' => ['rule' => 'optional, max 100', 'about' => 'What you are doing'],
            ],
        ],
        'api_v1_channels_index' => [
            'summary' => 'The channels this token can see, to get an id out of. The chat screen shows no ids, so without this list the next call cannot be made. Fifty at most.',
            'params' => [
                'search' => ['rule' => 'optional, query', 'about' => 'Filters by name, case-insensitive'],
            ],
        ],
        'api_v1_messages_store' => [
            'summary' => 'Say something in a channel. The same message as from the screen: it carries your name, you can edit and delete it, and nothing marks it as having come from a script.',
            'params' => [
                'channel_id' => ['rule' => 'required', 'about' => 'From GET /v1/channels'],
                'body' => ['rule' => 'required, max 4000', 'about' => 'The text itself; attachments cannot go here'],
                'parent_id' => ['rule' => 'optional, ULID', 'about' => 'A reply in an existing thread in the same channel'],
            ],
        ],
        'webhooks_messages_store' => [
            'summary' => 'Posting a message without anybody\'s token. The key sits in the path, because that is what the tools pointing at this expect — and that is also why a webhook can be revoked and minted again.',
        ],
        'workflows_webhook' => [
            'summary' => 'Set a workflow off. On a shorter leash than a message webhook: behind this is not one message but a run of steps that can post in several channels and add people to them.',
        ],
    ],

    /* What a crawler and a chat client are told about these pages. */
    'seo' => [
        'home' => [
            'title' => 'Postduif — the conversation and the work in one place',
            'description' => 'Channels and threads, tickets for what would otherwise be forgotten, and files too big to email. Customers join as guests and see only their own channels.',
        ],
        'docs' => [
            'title' => 'The Postduif API',
            'description' => 'Every call goes through the same rules as the screen. Methods, paths, parameters and the per-minute limits — read from the router, not typed out.',
        ],
    ],
];
