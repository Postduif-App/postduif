<?php

/*
 * What the app says back after an action: the line above a form or the toast
 * in the corner.
 *
 * Grouped by what it is about rather than by which controller sends it, since
 * the same line comes from two places — scheduling a message happens both with
 * a toast and with an ordinary reply.
 *
 * Lines with a name in them sit here as one string with :name rather than as
 * separate pieces. Glued-together text is still followable in Dutch, but in a
 * language with a different word order it can no longer be built.
 */
return [
    'channel' => [
        'saved' => 'Channel settings saved.',
        'deleted' => '#:name has been deleted.',
        'unmuted' => 'Notifications for this channel are back on.',
        'muted' => 'Notifications for this channel are off.',
        'muted_until' => 'Notifications for this channel are off until :time.',
        'forwarded' => 'Forwarded to #:name.',
        'archived' => '#:name has been archived.',
        'reopened' => '#:name is open again.',

        /*
         * All three cases as whole sentences, the nought included. "Nobody
         * added" is not the same sentence with a different number in it:
         * nothing happened, and that should read differently from a count.
         */
        'members_added' => '{0}Nobody added.|{1}1 member added.|[2,*]:count members added.',
        'member_removed' => ':name has been removed from the channel.',
    ],

    'message' => [
        'scheduled' => 'Message scheduled.',
        'updated' => 'Message changed.',
        'withdrawn' => 'Message withdrawn.',
    ],

    'poll' => [
        'closed' => 'Poll closed.',
        'reopened' => 'Poll reopened.',
    ],

    'transfer' => [
        'created' => 'Files ready. The link is in the list.',
        'withdrawn' => 'Transfer withdrawn.',
        'link_withdrawn' => 'Link for :email withdrawn.',
    ],

    'secret' => [
        'withdrawn' => 'Request withdrawn.',
        'filled' => '{1}Thanks, the value has been saved. You can no longer look at it.|[2,*]Thanks, :count values have been saved. You can no longer look at them.',
    ],

    'invitation' => [
        'sent' => 'Invitation sent to :email.',
        'resent' => 'Invitation sent to :email again.',
        'withdrawn' => 'Invitation for :email withdrawn.',
        'link_created' => 'Invitation link created.',
        'link_withdrawn' => 'Invitation link withdrawn.',
        'welcome' => 'Welcome to :workspace.',
    ],

    'member' => [
        'channels_updated' => 'The channels of :name have been updated.',
        'channels_unchanged' => 'Nothing changed about the channels of :name.',

        /*
         * Every branch a whole sentence, even though the first part is written
         * three times. The alternative is a role sentence with a second one
         * glued on, and glued-together text cannot be built in a language with
         * a different word order.
         */
        'role_changed' => '{0}:name is now :role.|{1}:name is now :role. One public channel was unlinked in the process.|[2,*]:name is now :role. :count public channels were unlinked in the process.',
        'removed' => ':name has been removed from the workspace.',
    ],

    'settings' => [
        'saved' => 'Settings saved.',
        'permissions_saved' => 'Permissions saved.',
        'notifications_saved' => 'Notifications saved.',
        'theme_saved' => 'Theme saved.',
        'avatar_saved' => 'Photo saved.',
        'avatar_removed' => 'Photo removed.',
        'logo_saved' => 'Logo saved.',
        'logo_removed' => 'Logo removed.',
    ],

    'rule' => [
        'added' => 'Rule added.',
        'updated' => 'Rule updated.',
        'removed' => 'Rule removed.',
    ],

    'role' => [
        'created' => 'The role :name has been created.',
        'saved' => 'The role :name has been saved.',
        'deleted' => 'The role :name has been deleted.',
    ],
];
