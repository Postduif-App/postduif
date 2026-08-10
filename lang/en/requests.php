<?php

/*
 * What a form says when what arrived cannot be accepted.
 *
 * Grouped by the thing somebody was filling in rather than by the class that
 * validates it, so the messages a member reads while making one channel sit
 * together — StoreChannelRequest and UpdateChannelRequest say the same sentence
 * and keeping two copies of it is how they eventually come to disagree.
 *
 * Only the sentences this application wrote itself live here. Everything a rule
 * says by default ("The name field is required") is still Laravel's own, in
 * English, in both locales: neither lang directory has a validation.php yet.
 */

return [
    /*
     * Shared by every form that takes a file, because these are the workspace's
     * rules rather than any one form's — see App\Concerns\ValidatesAttachments.
     */
    'attachments' => [
        'uploads_off' => 'Sharing files is switched off in this workspace.',
        'too_many' => 'You can send at most :count files along.',
        'too_large' => 'This file is larger than this workspace allows.',
        'type_not_allowed' => 'This file type is not allowed in this workspace.',
    ],

    /*
     * Shared by every form that takes an image: a profile picture and a
     * workspace logo ask the same thing, and two copies of that sentence is how
     * they eventually come to differ.
     */
    'image' => [
        'type' => 'Choose an ordinary image: png, jpg, gif or webp.',
    ],

    'message' => [
        'too_many_pinned' => 'At most :count messages can be pinned. Unpin one first.',
        'parent_not_here' => 'You can only reply to a message in this channel.',
        'quote_not_here' => 'You can only quote a message from this channel.',
        'empty' => 'Type something, or send a file along.',
    ],

    /*
     * Making a channel and changing one, together: the two forms ask the same
     * questions and only part ways over what a direct message may not become.
     */
    'channel' => [
        'name_required' => 'Give the channel a name.',
        'name_taken' => 'A channel with this name already exists.',
        // Creating: somebody picked "direct" from the type list.
        'not_created_as_channel' => 'You do not create a direct message as a channel.',
        // Editing: somebody tried to turn an existing channel into one.
        'not_made_from_channel' => 'You do not make a direct message out of a channel.',
        'direct_has_no_name' => 'A direct message has no name.',
        'direct_has_no_topic' => 'A direct message has no topic.',
        'direct_visibility_fixed' => 'The visibility of a direct message is fixed.',
        'direct_has_no_layout' => 'A direct message has no other layout.',
        'invalid_setting' => 'Pick a valid setting.',
    ],

    'channel_link' => [
        'label_required' => 'Give the button a name.',
        'url_required' => 'Enter an address.',
        'url_scheme' => 'This has to be an address starting with http:// or https://.',
        'workflow_unknown' => 'Pick a workflow that runs on the button trigger.',
    ],

    'channel_tags' => [
        // Twenty is written out rather than filled in: the ceiling is in the
        // rule itself, and the sentence is about what a label is for.
        'too_many' => 'Twenty tags on a channel is more than a label still tells apart.',
    ],

    'secret_request' => [
        'keys_required' => 'Name at least one key to ask for.',
        'too_many_keys' => 'At most :count keys per request.',
        'key_shape' => 'A key starts with a letter and holds letters, digits, _, . or - after that.',
        'open_too_long' => 'A request stays open for :days days at most.',
    ],

    /*
     * Handing a secret over — both the channel version and the one made from
     * the secrets page, which differ only in where the recipient must be.
     */
    'secret' => [
        'values_required' => 'Fill in at least one value.',
        'label_required' => 'Say briefly what the secret is about — not what it is.',
        'recipient_required' => 'Pick who the secret is meant for.',
        'recipient_not_in_channel' => 'That person is not in this channel.',
        'recipient_not_in_workspace' => 'That person is not in this workspace.',
        'stored_too_long' => 'A secret stays up for :days days at most.',
    ],

    'broadcast' => [
        'body_required' => 'Write a message first.',
        // One sentence for both fields: picking neither is a single mistake,
        // and saying it twice in different words would suggest two.
        'no_target' => 'Pick at least one channel or tag.',
        'send_at_past' => 'Pick a moment that is still to come.',
    ],

    'transfer' => [
        'wrong_password' => 'That password is not right.',
        'files_required' => 'Pick at least one file to send.',
        'recipients_required' => 'Name at least one email address, or pick a different audience.',
        'invalid_email' => 'This is not a valid email address.',
        'too_many_files' => 'You can send at most :count files at once.',
        'file_too_large' => 'This file is larger than this workspace allows.',
        // The one about the lot rather than about one file — see
        // StoreTransferRequest::withValidator for why they are two sentences.
        'too_large_together' => 'Together these files are larger than this workspace allows.',
        'valid_too_long' => 'This workspace lets a link stay valid for :days days at most.',
    ],

    'reaction' => [
        // Channel reactions and board reactions, through one rule object.
        'emoji_only' => 'A reaction has to be an emoji.',
        // A shortcode this workspace does not know. Usually one that was just
        // deleted, or one carried over from somewhere else.
        'unknown_emoji' => 'This workspace has no emoji by that name.',
    ],

    'custom_emoji' => [
        'name' => 'Use lower case letters, digits, - and _ only. 30 characters at most.',
        'taken' => 'There is already an emoji here by that name.',
    ],

    'documents' => [
        'title_required' => 'Give the document a short title.',
        'text_with_body' => 'A document arrived without its plain text; the two belong together.',
        'body_shape' => 'This is not a document the editor can read.',
        'body_too_large' => 'This document is too large to save. Split it into several documents.',
        'body_too_deep' => 'This document is nested too deeply to save.',
    ],

    'ticket' => [
        'title_required' => 'Give the ticket a short title.',
        'source_not_here' => 'You can only promote a message from this channel.',
        'assignee_not_here' => 'That person is not in this channel.',
        'comment_empty' => 'Write something, or send a file along.',
    ],

    'direct_message' => [
        'recipient_required' => 'Pick who you want to talk to.',
        'not_a_member' => 'This person does not belong to this workspace.',
    ],

    'scheduled_message' => [
        'body_required' => 'Write a message first.',
        'send_at_past' => 'Pick a moment that is still to come.',
    ],

    'installation' => [
        'workspace_required' => 'Give your first workspace a name.',
    ],

    'board_post' => [
        'title_required' => 'Give your notice a short title.',
        'body_required' => 'An empty board notice tells nobody anything.',
    ],

    'poll' => [
        'options_required' => 'Give at least two answers.',
        'options_min' => 'A poll with one answer is not a question — give at least two.',
        'options_max' => 'At most :count answers.',
    ],

    /*
     * What an incoming webhook gets back when there is nothing usable in it.
     * The reader here is whoever is setting the integration up, with an HTTP
     * response in front of them: "422" on its own leaves them guessing.
     */
    'webhook' => [
        'text_required' => 'Send a "text" along with the contents of the message.',
        'path_empty' => 'There was nothing at the path ":path" in what you sent.',
        'path_not_text' => 'The path ":path" points at a list or an object, not at text.',
        'message_empty' => 'The message is empty.',
        'message_too_long' => 'The message is longer than :count characters.',
    ],

    'section' => [
        'name_taken' => 'You already have a group by that name.',
    ],

    /*
     * An invitation and an invite link ask the same thing of a guest: without a
     * channel they arrive in a workspace with nothing to see.
     */
    'invite' => [
        'channels_required' => 'Choose at least one channel for this guest.',
        'already_member' => 'This person is already in the workspace.',
    ],

    'member' => [
        'last_owner' => 'There has to be at least one owner. Point somebody else at it first.',
    ],

    'notifications' => [
        'invalid_window' => 'Choose one of the offered windows.',
    ],
];
