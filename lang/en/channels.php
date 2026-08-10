<?php

return [
    /*
     * The channel every workspace starts with. Slugged on the way in, so
     * this is a name rather than an address.
     */
    'home' => [
        'name' => 'general',
        'topic' => 'Anything that does not belong somewhere else',
    ],

    'actions' => [
        'cancel' => 'Cancel',
        'save' => 'Save',
        'create' => 'Create',
        'archive' => 'Archive',
        'remove' => 'Remove',
    ],

    'fields' => [
        'name' => 'Name',
        'topic' => 'Topic',
        'topic_optional' => '(optional)',
        'topic_placeholder' => 'What is this channel about?',
    ],

    'visibility' => [
        'heading' => 'Visibility',
        'public' => 'Public',
        'private' => 'Private',
        'public_hint' => 'Anyone in the workspace can read along and join.',
        'private_hint' => 'Only the people you add know this channel exists.',
        'public_explained' => 'Anyone in the workspace can find this channel, read it and join. Guests cannot: they only see what has been set out for them.',
        'private_explained' => 'Only members see this channel. Whoever is in it stays in; everybody else loses it.',
        'opening_up' => 'Careful: everything said here so far becomes readable for the whole workspace. Making the channel private again does not undo that.',
    ],

    'layout' => [
        'heading' => 'Layout',
        'chat' => 'Conversation',
        'chat_hint' => 'Messages one under the other, like an ordinary channel.',
        'feed' => 'Feed',
        'feed_hint' => 'Longer messages with more room, like a newsletter or a blog.',
    ],

    'create' => [
        'title' => 'Make a channel',
        'description' => 'Channels are usually about one subject, project or team.',
        'name_placeholder' => 'e.g. marketing',
        'slug_hint' => 'Lowercase letters and dashes.',
        'slug_preview' => 'Becomes #:slug',
    ],

    'settings' => [
        'title' => 'Settings for #:channel',
        'tablist' => 'Channel settings',

        'tabs' => [
            'general' => 'General',
            'general_description' => 'What this channel is called and what it is about.',
            'messages' => 'Messages',
            'messages_description' => 'Decide who may post messages in this channel.',
            'tickets' => 'Tickets',
            'tickets_description' => 'Whether this channel keeps tickets, who may open them, and what of that shows up in the conversation.',
            'document' => 'Documents',
            'document_description' => 'Whether this channel keeps documents, who may write in them, and what of that ends up in the conversation.',
            'links' => 'Buttons',
            'links_description' => 'Shortcuts to places outside the app, in a bar above the conversation.',
            'webhooks' => 'Webhooks',
            'webhooks_description' => 'What may post into this channel from the outside.',
        ],

        'name_hint_lead' => 'Spaces and capitals become dashes and lowercase letters. Links to this channel keep working, but an',
        'name_hint_example' => '#old-name',
        'name_hint_tail' => 'in older messages turns into plain text.',

        'topic_hint' => 'Sits under the name at the top of the conversation.',
    ],

    'posting' => [
        'heading' => 'Who may post messages',
        'everyone' => 'Everyone in this channel',
        'everyone_hint' => 'An ordinary conversation: every member can post.',
        'admins' => 'Only admins and whoever made the channel',
        'admins_hint' => 'A broadcast channel. Others can still react with an emoji and reply in threads.',
        'replies_open' => 'Allow replies in a thread',
        'replies_open_hint' => 'Switching this off makes it a channel that announces rather than discusses. Existing threads stay readable.',
    ],

    'documents' => [
        'heading' => 'Who may write in documents',
        'disabled' => 'No documents',
        'disabled_hint' => 'This channel is only a conversation.',
        'everyone' => 'Everyone in this channel',
        'everyone_hint' => 'Guests included. The place for agreements both sides keep.',
        'members' => 'Members only, no guests',
        'members_hint' => 'Guests do read the documents, but do not write in them.',
        'announce' => 'Say so in the conversation',
        'announce_hint' => 'A message in the channel when a document starts or is renamed. Not on every change: it saves by itself while somebody types.',
    ],

    'tickets' => [
        'heading' => 'Who may open tickets',
        'disabled' => 'No tickets',
        'disabled_hint' => 'This channel is only a conversation.',
        'everyone' => 'Everyone in this channel',
        'everyone_hint' => 'A customer channel: the customer can open tickets themselves.',
        'members' => 'Members only, no guests',
        'members_hint' => 'Guests do read the tickets, but do not open them.',
        'announce' => 'Mention tickets in the conversation',
        'announce_hint' => 'A short message in the channel as soon as a ticket is opened or closed, so the people only reading along see it too.',
        'announce_status' => 'Every status change as well',
        'announce_status_hint' => 'Off by default: a channel that reports every step is a channel people mute. Switch it on when the work happens in the conversation rather than on the board.',
    ],

    'archive' => [
        'heading' => 'Archive channel',
        'explanation' => 'Everything stays readable, but nothing can be posted any more. The channel leaves the sidebar and can be fetched back under "Archived".',
    ],

    'delete' => [
        'heading' => 'Delete channel',
        'explanation' => 'Every message, thread, ticket and webhook of this channel goes with it. There is no undoing this.',
        'confirm_lead' => 'Type',
        'confirm_tail' => 'to confirm',
        'confirm_button' => 'Delete for good',
    ],

    'members' => [
        'title' => 'Members of :channel',
        'private_note' => 'This channel is private — only the people listed here know it exists.',
        'public_note' => 'Anyone in the workspace can read this channel.',
        'in_channel' => 'In this channel (:count)',
        'owner' => 'owner',
        'add' => 'Add',
        'add_selected' => 'Add :count',
        'search_placeholder' => 'Find a teammate…',
        'all_in' => 'Everybody is already in.',
        'none_found' => 'Nobody found.',
        'leave' => 'Leave channel',
        'cannot_leave' => 'You made this channel, which is why you cannot leave it.',
        'remove' => 'Remove :name',
        'remove_title' => 'Remove from channel',
        'remove_question' => 'Remove :name?',
        'remove_private' => 'This takes away :name\'s access to #:channel and the channel disappears from their sidebar. Earlier messages stay.',
        'remove_public' => ':name can still read #:channel afterwards, but cannot join in until they sign up again.',
    ],
];
