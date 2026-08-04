<?php

return [
    'fields' => [
        'email' => 'Email address',
        'name' => 'Name',
        'name_placeholder' => 'First and last name',
        'password' => 'Password',
        'password_confirm' => 'Confirm password',
    ],

    'login' => [
        /*
         * What appears under the email field when a suspended account tries to
         * come in. With the screen rather than with the middleware, because
         * this is where the reader meets it — and it is an error under the
         * field rather than a green status line, since this is not good news.
         */
        'suspended' => 'This account has been suspended. Get in touch with an administrator.',

        'head' => 'Log in',
        'title' => 'Log in to your account',
        'description' => 'Enter your email and password below to log in',
        'forgot_password' => 'Forgot your password?',
        'remember' => 'Stay signed in',
        'passkey' => 'Sign in with a passkey',
        'passkey_loading' => 'Authenticating…',
        'passkey_separator' => 'Or continue with email',
        'submit' => 'Log in',
        'no_account' => "Don't have an account?",
        'sign_up' => 'Sign up',
    ],

    'register' => [
        'head' => 'Register',
        'title' => 'Create an account',
        'description' => 'Enter your details below to create your account',
        'submit' => 'Create account',
        'have_account' => 'Already have an account?',
        'log_in' => 'Log in',
    ],

    'forgot_password' => [
        'head' => 'Forgot password',
        'title' => 'Forgot password',
        'description' => 'Enter your email and you will get a link to set a new password',
        'submit' => 'Email me a reset link',
        'back_to' => 'Or, return to',
        'log_in' => 'log in',
    ],

    'reset_password' => [
        'head' => 'New password',
        'title' => 'Reset your password',
        'description' => 'Enter your new password below',
        'submit' => 'Save password',
    ],

    'confirm_password' => [
        'head' => 'Confirm password',
        'title' => 'Confirm password',
        'description' => 'This is a secured part of the application. Please confirm your password before continuing.',
        'passkey' => 'Confirm with a passkey',
        'passkey_loading' => 'Confirming…',
        'passkey_separator' => 'Or confirm with your password',
        'submit' => 'Confirm password',
    ],

    'verify_email' => [
        'head' => 'Verify email address',
        'title' => 'Verify email address',
        'description' => 'Please verify your email address by clicking the link we just sent you.',
        'sent' => 'A new verification link has been sent to the email address you gave when you registered.',
        'resend' => 'Resend verification email',
        'log_out' => 'Log out',
    ],

    'two_factor' => [
        'head' => 'Two-step verification',
        'code_title' => 'Verification code',
        'code_description' => 'Enter the verification code shown by your authenticator app.',
        'code_toggle' => 'log in using a verification code',
        'recovery_title' => 'Recovery code',
        'recovery_description' => 'Please confirm access to your account by entering one of your emergency recovery codes.',
        'recovery_toggle' => 'log in using a recovery code',
        'recovery_placeholder' => 'Enter a recovery code',
        'submit' => 'Continue',
        'or_you_can' => 'or you can',
    ],

    'invite' => [
        'title' => "You've been invited",
        'description' => 'One more step and you are in',
        'channels_intro' => 'You will get access to',
        'as_guest' => 'As a guest',
        'guest_note' => 'You only see the channels below. The rest of :workspace stays out of sight.',
        'invited_by' => ':name is inviting you to',
        'to_login' => 'Go to the login screen',
    ],

    'invitation' => [
        'head' => 'Invitation',
        'expired_title' => 'This invitation has expired',
        'expired_body' => 'Ask whoever invited you to send a new link. It will be valid for another two weeks.',
        'accepted_title' => 'This invitation has already been used',
        'accepted_body' => 'An account has already been created with it. Log in with the email address the invitation was sent to.',
        'unknown_title' => 'This link does not work',
        'unknown_body' => 'The invitation may have been withdrawn, or the link may have been cut off along the way. Ask for a new one.',
        'mismatch_intro' => 'This invitation is for',
        'mismatch_rest' => ', but you are logged in as :email. Log out and open the link again.',
        'log_out' => 'Log out',
        'account_exists_intro' => 'An account already exists for',
        'account_exists_rest' => '. Log in and you are straight in.',
        'submit_login' => 'Log in and join',
        'submit_accept' => 'Accept invitation',
    ],

    'join' => [
        'head' => 'Invitation link',
        'expired_title' => 'This invitation link has expired',
        'expired_body' => 'The link was only valid for a limited time. Ask whoever sent it for a new one.',
        'revoked_title' => 'This invitation link has been withdrawn',
        'revoked_body' => 'The link no longer works because somebody withdrew it. Ask for a new one if you still need to get in.',
        'exhausted_title' => 'This invitation link has been used up',
        'exhausted_body' => 'The link could be used a limited number of times, and that number has been reached. Ask for a new one.',
        'unknown_title' => 'This link does not work',
        'unknown_body' => 'The link may have been cut off along the way. Check that you pasted all of it, or ask for a new one.',
        'invited_generic' => "You've been invited to",
        'email_placeholder' => 'you@example.com',
        'signed_in_as' => 'You are logged in as',
        'submit' => 'Join',
        'have_account' => 'Already have an account?',
        'log_in_first' => 'Log in first',
    ],

    'transfer' => [
        'title' => 'Files for you',
        'description' => 'Ready for you to download',
        'head' => 'Files',
        'expired_title' => 'These files have expired',
        'expired_body' => 'A download link is valid for a limited time; after that the files are cleaned up. Ask the sender to send them again.',
        'revoked_title' => 'This transfer has been withdrawn',
        'revoked_body' => 'The sender withdrew the link. Get in touch if you still need the files.',
        'exhausted_title' => 'This link has been used up',
        'exhausted_body' => 'The link could be used a limited number of times, and that number has been reached. Ask the sender for a new one.',
        'sender_sent_files' => ':name sent you files',
        'files_waiting' => 'There are files waiting for you',
        'password_needed' => 'This transfer has a password',
        'unlock' => 'View files',
        'password_note' => 'The sender gave you the password separately — not in the same email as this link, because then it would not be a second lock.',
        'sender_sent' => ':name sent you',
        'something_waiting' => 'There is something waiting for you',
        'file_count' => '{1}1 file|[2,*]:count files',
        'via' => 'via :workspace',
        'download_file' => 'Download :name',
        'download_all' => 'Download everything (:size)',
        'available_until' => 'Available until :date',
        'downloads_left' => '{1}1 download left. Downloading everything at once counts as one.|[2,*]:count downloads left. Downloading everything at once counts as one.',
    ],

    'secret_fill' => [
        'title' => 'Asked for details',
        'description' => 'Fill in what is being asked',
        'expired' => 'This request has expired. Ask whoever sent it for a new one.',
        'revoked' => 'This request has been withdrawn. Get in touch if you still need to pass something on.',
        'requested_by' => ':name is asking you for',
        'all_filled' => 'Everything has been filled in. There is nothing left for you to do.',
        'answered' => 'Already filled in',
        'warning' => 'What you fill in is afterwards only visible to :name — not to you any more, and never to the rest of this channel. So check it before you send.',
        'burn_note' => 'As soon as :name has read it, it is deleted.',
        'submit' => 'Send',
        'expires_on' => 'This request expires on :date.',
    ],
];
