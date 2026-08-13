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
         * The four numbers under the hero, as a unit under a figure.
         *
         * No sentences with :count in them: the number is right there beside it
         * and repeating it would say it twice. Hence no plural forms either —
         * this is a column heading, not a sentence, and headings do not inflect.
         */
        'tally' => [
            'features' => 'features',
            'roles' => 'roles to start with',
            'triggers' => 'triggers',
            'actions' => 'steps',
        ],

        /*
         * The sketch beside the headline. Made-up names, and they may look it:
         * this is a drawing of the idea, not a screenshot.
         */
        'sketch' => [
            'channel' => 'client-vandenberg',
            'guest' => '2 members · 1 guest',
            'who' => 'Marieke',
            'time' => '09:14',
            'message' => 'The export has been stuck on the new integration since this morning.',
            'ticket_label' => 'Ticket 124',
            'ticket_status' => 'Open',
            'ticket_title' => 'Export is stuck',
            'ticket_assignee' => 'Picked up by Sam',
        ],

        /*
         * The three cards above the list. Their names and descriptions come off
         * the feature classes — see BuildFeatureInventory::spotlight() — and
         * only the sentence underneath lives here, because that one is a
         * judgement: why this is the feature somebody came for. The keys are
         * the features' own keys, so they cannot come to point at something
         * else without the feature itself being renamed.
         */
        'spotlight' => [
            'title' => 'What people stay for',
            'lead' => 'Three things an ordinary chat app does not have, and which otherwise cost you three more subscriptions.',
            'items' => [
                'tickets' => 'The conversation moves on; the work stays put. One click turns a message into a ticket in the channel\'s own list, with a status and somebody who picked it up — and the discussion underneath stays where it was.',
                'transfers' => 'Put files behind a single link, for somebody with no account too. With a password in front of it and a date after which the link opens nothing, instead of a folder still sitting there two years later.',
                'contracts' => 'A PDF with fields goes out and comes back signed. Every recipient gets their own link by email, you see who has been, and the signed document lands back in the workspace with the audit sheet behind it.',
            ],
        ],

        'features' => [
            'title' => 'What is in it',
            'lead' => 'Every feature below exists as a class in the code, under this name and with this description. What is not listed is not there.',
            // Beside a group's heading, so the list can be taken in before it
            // is read. The number comes from the inventory, not from this line.
            'group_count' => '{1} :count feature|[0,*] :count features',
        ],

        'channels' => [
            'title' => 'A channel shaped like the conversation',
            'lead' => 'You do not switch a channel on, so it is not in the list above — even though it is where you spend the whole day. These are the knobs underneath it.',
            'layout' => 'Layout',
            'posting' => 'Who posts',
            'tickets' => 'Tickets',
            'documents' => 'Documents',
        ],

        'workflow' => [
            'title' => 'Things your workspace does by itself',
            'lead' => 'One trigger, then a run of steps, with conditions and branches in between. This is what you pick from.',
            // A unit under a figure, not a heading over a list.
            'when' => 'triggers',
            'then' => 'steps',
        ],

        'api' => [
            'title' => 'For your own script and your AI client',
            'lead' => 'A personal token for your own script, OAuth for an AI client. Every call goes through the same rules as the screen: what you may not see, this does not return either.',
            'endpoints' => 'The API',
            'reference' => 'The full reference →',
            'tools' => 'What an AI client can do',
            'note' => 'Once you allow it, withdrawn again in one click, and off per workspace by default.',
        ],

        'roles' => [
            'title' => 'Who may do what',
            'lead' => 'Four roles to start with, and after that you make your own. A role is a name with a handful of rights from this list — a guest is one of them, and sees only the channels they were invited to.',
            'ability' => 'Right',
            'browse' => 'See the workspace',
            /*
             * One aside instead of three. What went: the explanation that the
             * top row is a property of the role rather than a right, and that
             * nobody can hand out a right they do not hold themselves. Both
             * true and both still spelled out where it counts — in the code and
             * in the roles screen — but neither is why anybody is on this page.
             */
            'note' => 'This is where a workspace begins, not where it stays: an administrator renames the roles, ticks rights on and off, and adds as many as they need. The list of rights is fixed, though, because every right here is enforced somewhere.',
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

        'contracts' => [
            'title' => 'Getting contracts signed',
            'lead' => 'Somebody in the workspace prepares a template once: the PDF, the fields, how many recipients it has, and their own signature under it. After that a contract goes out on your call, with nobody opening a screen. This needs a token tied to one workspace and carrying the contracts scope — you make one at Settings → API tokens.',
            'callback' => 'What arrives at your end',
            'verify' => 'Checking the signature',
            'note' => 'There are three events: signed when somebody signs, declined when somebody refuses, and completed once everybody has been round — that last one only when the signed document is ready, so you can fetch it straight away. Sign over the raw body rather than a re-encoded one: a single space of difference and the comparison no longer holds. A delivery that fails is tried again, and a failure at your end never holds up the signing.',
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
        'api_v1_contract-templates_index' => [
            'summary' => 'The templates this token may send. Per template: how many recipients it expects, whether the sender has already signed it, and which fields you may fill in ahead of time. readyToSend is the field to branch on — when it is false, sending will be refused.',
        ],
        'api_v1_contracts_store' => [
            'summary' => 'Send a template to the people who have to sign it. A new contract is made from it — the same document, the same fields, and the signature the sender put under the template once — and every recipient gets their own signing link by mail. The sender is not asked again.',
            'params' => [
                'template_id' => ['rule' => 'required, ULID', 'about' => 'From GET /v1/contract-templates'],
                'recipients' => ['rule' => 'required, exactly requiredSigners', 'about' => 'A list of {name, email} in the order the fields were drawn; optionally values with field id → value'],
                'title' => ['rule' => 'optional, max 200', 'about' => 'Otherwise the template\'s own title'],
                'message' => ['rule' => 'optional, max 2000', 'about' => 'The line in the invitation mail; never printed on the PDF'],
                'locale' => ['rule' => 'optional, nl or en', 'about' => 'The language of the invitation, the reminder and the signed copy. Otherwise the language of the account behind the token'],
                'valid_for_days' => ['rule' => 'optional, 1–365', 'about' => 'Counted from now; after that the link opens nothing'],
                'callback_url' => ['rule' => 'optional, https', 'about' => 'Where signing, refusal and completion are reported — for this contract only'],
                'callback_secret' => ['rule' => 'optional, min 16', 'about' => 'What X-Postduif-Signature is taken with. Leave it out and one comes back in the response — the only time you see it. Without a callback_url it means nothing'],
            ],
        ],
        'api_v1_contracts_index' => [
            'summary' => 'What is running. Without the drafts by default; pass status to ask something else.',
            'params' => [
                'status' => ['rule' => 'optional', 'about' => 'draft, sent, completed, cancelled or expired'],
            ],
        ],
        'api_v1_contracts_show' => [
            'summary' => 'How far one contract has got: per signer when they opened, signed or refused it, and whether the signed document is ready. The signing links are deliberately not here — those go to the recipient and nowhere else.',
        ],
        'api_v1_contracts_document' => [
            'summary' => 'The signed PDF, with every signature on it and the audit page behind it. Answers 409 while there is nothing to fetch yet; signedCopy on the contract says whether it is still coming or went wrong.',
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
