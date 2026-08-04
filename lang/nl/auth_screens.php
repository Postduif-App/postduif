<?php

/*
 * Every screen somebody meets from outside the application.
 *
 * Logging in, accepting an invitation, downloading files somebody sent, filling
 * in a request for secrets — the readers of these pages are the only ones who
 * never set a language preference, because they have no account to set it on.
 * HandleLocale falls back to their browser for them, which is exactly why these
 * lines matter more than any behind the login: leave them in one language and
 * the fallback has nothing to fall back to.
 *
 * Grouped by the screen a reader is looking at, with the labels that repeat on
 * all of them ('fields') shared — a field is the same field whichever form
 * draws it.
 */

return [
    'fields' => [
        'email' => 'E-mailadres',
        'name' => 'Naam',
        'name_placeholder' => 'Voor- en achternaam',
        'password' => 'Wachtwoord',
        'password_confirm' => 'Wachtwoord bevestigen',
    ],

    'login' => [
        /*
         * Wat er onder het e-mailveld verschijnt als een geschorst account
         * probeert binnen te komen. Bij het scherm en niet bij de middleware,
         * omdat dit de plek is waar de lezer het te zien krijgt — en het is een
         * fout onder het veld en geen groene statusregel, want dit is geen
         * goed nieuws.
         */
        'suspended' => 'Dit account is geschorst. Neem contact op met een beheerder.',

        'head' => 'Inloggen',
        'title' => 'Inloggen op je account',
        'description' => 'Vul hieronder je e-mailadres en wachtwoord in',
        'forgot_password' => 'Wachtwoord vergeten?',
        'remember' => 'Ingelogd blijven',
        /*
         * De standaardteksten van PasskeyVerify. Ze staan bij 'login' omdat het
         * component zonder routes precies dat doet — inloggen; wie het ergens
         * anders voor gebruikt, geeft zijn eigen teksten mee zoals het scherm
         * 'wachtwoord bevestigen' dat doet.
         */
        'passkey' => 'Inloggen met een passkey',
        'passkey_loading' => 'Bezig met inloggen…',
        'passkey_separator' => 'Of ga verder met e-mail',
        'submit' => 'Inloggen',
        'no_account' => 'Nog geen account?',
        'sign_up' => 'Registreren',
    ],

    'register' => [
        'head' => 'Registreren',
        'title' => 'Een account aanmaken',
        'description' => 'Vul hieronder je gegevens in om je account aan te maken',
        'submit' => 'Account aanmaken',
        'have_account' => 'Heb je al een account?',
        'log_in' => 'Inloggen',
    ],

    'forgot_password' => [
        'head' => 'Wachtwoord vergeten',
        'title' => 'Wachtwoord vergeten',
        'description' => 'Vul je e-mailadres in en je krijgt een link om een nieuw wachtwoord in te stellen',
        'submit' => 'Stuur me een herstellink',
        'back_to' => 'Of ga terug naar',
        'log_in' => 'inloggen',
    ],

    'reset_password' => [
        'head' => 'Nieuw wachtwoord',
        'title' => 'Nieuw wachtwoord instellen',
        'description' => 'Vul hieronder je nieuwe wachtwoord in',
        'submit' => 'Wachtwoord opslaan',
    ],

    'confirm_password' => [
        'head' => 'Wachtwoord bevestigen',
        'title' => 'Wachtwoord bevestigen',
        'description' => 'Dit is een afgeschermd deel van de applicatie. Bevestig je wachtwoord voor je verdergaat.',
        'passkey' => 'Bevestigen met een passkey',
        'passkey_loading' => 'Bezig met bevestigen…',
        'passkey_separator' => 'Of bevestig met je wachtwoord',
        'submit' => 'Wachtwoord bevestigen',
    ],

    'verify_email' => [
        'head' => 'E-mailadres bevestigen',
        'title' => 'E-mailadres bevestigen',
        'description' => 'Bevestig je e-mailadres door te klikken op de link die we je zojuist gestuurd hebben.',
        'sent' => 'Er is een nieuwe bevestigingslink gestuurd naar het e-mailadres dat je bij het registreren opgaf.',
        'resend' => 'Bevestigingsmail opnieuw sturen',
        'log_out' => 'Uitloggen',
    ],

    'two_factor' => [
        'head' => 'Verificatie in twee stappen',
        'code_title' => 'Verificatiecode',
        'code_description' => 'Vul de verificatiecode in die je authenticator-app laat zien.',
        'code_toggle' => 'inloggen met een verificatiecode',
        'recovery_title' => 'Herstelcode',
        'recovery_description' => 'Bevestig dat je bij je account kunt door een van je herstelcodes in te vullen.',
        'recovery_toggle' => 'inloggen met een herstelcode',
        'recovery_placeholder' => 'Vul een herstelcode in',
        'submit' => 'Doorgaan',
        'or_you_can' => 'of je kunt',
    ],

    /*
     * What a mailed invitation and an invitation link say in the same words.
     * The two screens differ in how somebody arrived, not in what they are
     * being offered.
     */
    'invite' => [
        'title' => 'Je bent uitgenodigd',
        'description' => 'Nog één stap en je zit erin',
        'channels_intro' => 'Je krijgt toegang tot',
        'as_guest' => 'Als gast',
        'guest_note' => 'Je ziet alleen de kanalen hieronder. De rest van :workspace blijft buiten beeld.',
        'invited_by' => ':name nodigt je uit voor',
        'to_login' => 'Naar het inlogscherm',
    ],

    'invitation' => [
        'head' => 'Uitnodiging',
        'expired_title' => 'Deze uitnodiging is verlopen',
        'expired_body' => 'Vraag degene die je uitnodigde om een nieuwe link te sturen. Die is daarna weer twee weken geldig.',
        'accepted_title' => 'Deze uitnodiging is al gebruikt',
        'accepted_body' => 'Er is al een account mee aangemaakt. Log in met het e-mailadres waarop je de uitnodiging kreeg.',
        'unknown_title' => 'Deze link werkt niet',
        'unknown_body' => 'Mogelijk is de uitnodiging ingetrokken of is de link onderweg afgekapt. Vraag om een nieuwe.',
        /*
         * Split in two because the address between them is set in bold on
         * screen. One line with a placeholder would read better here, but it
         * would take the emphasis with it, and the address is the whole point
         * of the sentence.
         */
        'mismatch_intro' => 'Deze uitnodiging is voor',
        'mismatch_rest' => ', maar je bent ingelogd als :email. Log uit en open de link opnieuw.',
        'log_out' => 'Uitloggen',
        'account_exists_intro' => 'Er bestaat al een account voor',
        'account_exists_rest' => '. Log in en je staat er meteen in.',
        'submit_login' => 'Inloggen en deelnemen',
        'submit_accept' => 'Uitnodiging accepteren',
    ],

    'join' => [
        'head' => 'Uitnodigingslink',
        'expired_title' => 'Deze uitnodigingslink is verlopen',
        'expired_body' => 'De link was maar een beperkte tijd geldig. Vraag degene die hem stuurde om een nieuwe.',
        'revoked_title' => 'Deze uitnodigingslink is ingetrokken',
        'revoked_body' => 'De link werkt niet meer omdat iemand hem heeft ingetrokken. Vraag om een nieuwe als je er nog bij moet.',
        'exhausted_title' => 'Deze uitnodigingslink is opgebruikt',
        'exhausted_body' => 'De link mocht een beperkt aantal keer gebruikt worden, en dat aantal is bereikt. Vraag om een nieuwe.',
        'unknown_title' => 'Deze link werkt niet',
        'unknown_body' => 'Mogelijk is de link onderweg afgekapt. Controleer of je hem in zijn geheel hebt geplakt, of vraag om een nieuwe.',
        'invited_generic' => 'Je bent uitgenodigd voor',
        'email_placeholder' => 'jij@voorbeeld.nl',
        'signed_in_as' => 'Je bent ingelogd als',
        'submit' => 'Deelnemen',
        'have_account' => 'Heb je al een account?',
        'log_in_first' => 'Log eerst in',
    ],

    'transfer' => [
        'title' => 'Bestanden voor jou',
        'description' => 'Klaargezet om te downloaden',
        'head' => 'Bestanden',
        'expired_title' => 'Deze bestanden zijn verlopen',
        'expired_body' => 'Een downloadlink is een beperkte tijd geldig; daarna worden de bestanden opgeruimd. Vraag de afzender om ze opnieuw te versturen.',
        'revoked_title' => 'Deze verzending is ingetrokken',
        'revoked_body' => 'De afzender heeft de link ingetrokken. Neem contact op als je de bestanden nog nodig hebt.',
        'exhausted_title' => 'Deze link is opgebruikt',
        'exhausted_body' => 'De link mocht een beperkt aantal keer gebruikt worden, en dat aantal is bereikt. Vraag de afzender om een nieuwe.',
        'sender_sent_files' => ':name stuurde je bestanden',
        'files_waiting' => 'Er staan bestanden voor je klaar',
        'password_needed' => 'Deze verzending heeft een wachtwoord',
        'unlock' => 'Bestanden bekijken',
        'password_note' => 'De afzender heeft je het wachtwoord apart gestuurd — niet in dezelfde mail als deze link, want dan zou het geen tweede slot zijn.',
        'sender_sent' => ':name stuurde je',
        'something_waiting' => 'Er staat iets voor je klaar',
        'file_count' => '{1}1 bestand|[2,*]:count bestanden',
        'via' => 'via :workspace',
        'download_file' => ':name downloaden',
        'download_all' => 'Alles downloaden (:size)',
        'available_until' => 'Beschikbaar tot :date',
        'downloads_left' => '{1}Nog 1 download beschikbaar. Alles in één keer downloaden telt als één.|[2,*]Nog :count downloads beschikbaar. Alles in één keer downloaden telt als één.',
    ],

    'secret_fill' => [
        'title' => 'Gevraagd om gegevens',
        'description' => 'Vul in wat er gevraagd wordt',
        'expired' => 'Dit verzoek is verlopen. Vraag degene die het stuurde om een nieuw verzoek.',
        'revoked' => 'Dit verzoek is ingetrokken. Neem contact op als je nog iets moet doorgeven.',
        'requested_by' => ':name vraagt je om',
        'all_filled' => 'Alles is al ingevuld. Er is niets meer voor je te doen.',
        'answered' => 'Al ingevuld',
        'warning' => 'Wat je invult is daarna alleen nog voor :name te zien — voor jou niet meer, en voor de rest van dit kanaal nooit. Controleer het dus voor je verstuurt.',
        'burn_note' => 'Zodra :name het bekeken heeft, wordt het verwijderd.',
        'submit' => 'Versturen',
        'expires_on' => 'Dit verzoek verloopt op :date.',
    ],
];
