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
        'uploads_off' => 'Bestanden delen staat uit in deze workspace.',
        'too_many' => 'Je kunt maximaal :count bestanden meesturen.',
        'too_large' => 'Dit bestand is groter dan in deze workspace is toegestaan.',
        'type_not_allowed' => 'Dit bestandstype is niet toegestaan in deze workspace.',
    ],

    /*
     * Gedeeld door elk formulier dat een afbeelding aanneemt: een profielfoto
     * en een werkruimtelogo stellen dezelfde eis, en twee kopieën van die zin
     * is hoe ze uiteindelijk van elkaar gaan verschillen.
     */
    'image' => [
        'type' => 'Kies een gewone afbeelding: png, jpg, gif of webp.',
    ],

    'message' => [
        'too_many_pinned' => 'Er kunnen maximaal :count berichten vastgepind zijn. Maak er eerst een los.',
        'parent_not_here' => 'Je kunt alleen antwoorden op een bericht in dit kanaal.',
        'quote_not_here' => 'Je kunt alleen een bericht uit dit kanaal citeren.',
        'empty' => 'Typ iets, of stuur een bestand mee.',
    ],

    /*
     * Making a channel and changing one, together: the two forms ask the same
     * questions and only part ways over what a direct message may not become.
     */
    'channel' => [
        'name_required' => 'Geef het kanaal een naam.',
        'name_taken' => 'Er bestaat al een kanaal met deze naam.',
        // Creating: somebody picked "direct" from the type list.
        'not_created_as_channel' => 'Een direct bericht maak je niet aan als kanaal.',
        // Editing: somebody tried to turn an existing channel into one.
        'not_made_from_channel' => 'Een direct bericht maak je niet van een kanaal.',
        'direct_has_no_name' => 'Een direct bericht heeft geen naam.',
        'direct_has_no_topic' => 'Een direct bericht heeft geen onderwerp.',
        'direct_visibility_fixed' => 'De zichtbaarheid van een direct bericht ligt vast.',
        'direct_has_no_layout' => 'Een direct bericht heeft geen andere weergave.',
        'invalid_setting' => 'Kies een geldige instelling.',
    ],

    'channel_link' => [
        'label_required' => 'Geef de knop een naam.',
        'url_required' => 'Geef een adres op.',
        'url_scheme' => 'Dit moet een adres zijn dat met http:// of https:// begint.',
        'workflow_unknown' => 'Kies een workflow die op de knop-trigger staat.',
    ],

    'channel_tags' => [
        // Twenty is written out rather than filled in: the ceiling is in the
        // rule itself, and the sentence is about what a label is for.
        'too_many' => 'Twintig tags op een kanaal is meer dan een label nog onderscheidt.',
    ],

    'secret_request' => [
        'keys_required' => 'Noem minstens één sleutel om naar te vragen.',
        'too_many_keys' => 'Maximaal :count sleutels per verzoek.',
        'key_shape' => 'Een sleutel begint met een letter en bevat verder letters, cijfers, _, . of -.',
        'open_too_long' => 'Een verzoek blijft maximaal :days dagen open.',
    ],

    /*
     * Handing a secret over — both the channel version and the one made from
     * the secrets page, which differ only in where the recipient must be.
     */
    'secret' => [
        'values_required' => 'Vul minstens één waarde in.',
        'label_required' => 'Zeg kort waar het geheim over gaat — niet wat het is.',
        'recipient_required' => 'Kies voor wie het geheim bedoeld is.',
        'recipient_not_in_channel' => 'Die persoon zit niet in dit kanaal.',
        'recipient_not_in_workspace' => 'Die persoon zit niet in deze workspace.',
        'stored_too_long' => 'Een geheim blijft maximaal :days dagen staan.',
    ],

    'broadcast' => [
        'body_required' => 'Schrijf eerst een bericht.',
        // One sentence for both fields: picking neither is a single mistake,
        // and saying it twice in different words would suggest two.
        'no_target' => 'Kies minstens één kanaal of tag.',
        'send_at_past' => 'Kies een moment dat nog moet komen.',
    ],

    'transfer' => [
        'wrong_password' => 'Dat wachtwoord klopt niet.',
        'files_required' => 'Kies minstens één bestand om te versturen.',
        'recipients_required' => 'Noem minstens één e-mailadres, of kies een ander publiek.',
        'invalid_email' => 'Dit is geen geldig e-mailadres.',
        'too_many_files' => 'Je kunt maximaal :count bestanden tegelijk versturen.',
        'file_too_large' => 'Dit bestand is groter dan in deze workspace is toegestaan.',
        // The one about the lot rather than about one file — see
        // StoreTransferRequest::withValidator for why they are two sentences.
        'too_large_together' => 'Samen zijn deze bestanden groter dan in deze workspace is toegestaan.',
        'valid_too_long' => 'Deze workspace laat een link maximaal :days dagen geldig zijn.',
    ],

    'reaction' => [
        // Channel reactions and board reactions, through one rule object.
        'emoji_only' => 'Een reactie moet een emoji zijn.',
        // Een shortcode die deze workspace niet kent. Meestal een emoji die
        // net verwijderd is, of er een uit een andere workspace.
        'unknown_emoji' => 'Deze workspace heeft geen emoji met die naam.',
    ],

    'custom_emoji' => [
        'name' => 'Gebruik alleen kleine letters, cijfers, - en _. Maximaal 30 tekens.',
        'taken' => 'Er is hier al een emoji met die naam.',
    ],

    'documents' => [
        'title_required' => 'Geef het document een korte titel.',
        'text_with_body' => 'Er kwam wel een document binnen maar geen platte tekst; die twee horen bij elkaar.',
        'body_shape' => 'Dit is geen document dat de editor kan lezen.',
        'body_too_large' => 'Dit document is te groot om op te slaan. Knip het op in meerdere documenten.',
        'body_too_deep' => 'Dit document zit te diep genest om op te slaan.',
    ],

    'ticket' => [
        'title_required' => 'Geef het ticket een korte titel.',
        'source_not_here' => 'Je kunt alleen een bericht uit dit kanaal promoveren.',
        'assignee_not_here' => 'Die persoon zit niet in dit kanaal.',
        'comment_empty' => 'Schrijf iets, of stuur een bestand mee.',
    ],

    'direct_message' => [
        'recipient_required' => 'Kies met wie je wilt praten.',
        'not_a_member' => 'Deze persoon hoort niet bij deze workspace.',
    ],

    'scheduled_message' => [
        'body_required' => 'Schrijf eerst een bericht.',
        'send_at_past' => 'Kies een moment dat nog moet komen.',
    ],

    'installation' => [
        'workspace_required' => 'Geef je eerste workspace een naam.',
    ],

    'board_post' => [
        'title_required' => 'Geef je bericht een korte titel.',
        'body_required' => 'Een leeg prikbordbericht zegt niemand iets.',
    ],

    'poll' => [
        'options_required' => 'Geef minstens twee antwoorden.',
        'options_min' => 'Een poll met één antwoord is geen vraag — geef er minstens twee.',
        'options_max' => 'Maximaal :count antwoorden.',
    ],

    /*
     * Wat een binnenkomende webhook terugkrijgt als er niets bruikbaars in zit.
     * De lezer is hier degene die de integratie instelt, met een HTTP-antwoord
     * voor zich: "422" op zichzelf laat diegene raden.
     */
    'webhook' => [
        'text_required' => 'Stuur een "text" mee met de inhoud van het bericht.',
        'path_empty' => 'Op het pad ":path" stond niets in wat je stuurde.',
        'path_not_text' => 'Het pad ":path" wijst naar een lijst of een object, niet naar tekst.',
        'message_empty' => 'Het bericht is leeg.',
        'message_too_long' => 'Het bericht is langer dan :count tekens.',
    ],

    'section' => [
        'name_taken' => 'Je hebt al een groep met deze naam.',
    ],

    /*
     * Een uitnodiging en een uitnodigingslink stellen dezelfde eis aan een
     * gast: zonder kanaal komt diegene binnen in een werkruimte waar niets te
     * zien is.
     */
    'invite' => [
        'channels_required' => 'Kies minstens één kanaal voor deze gast.',
        'already_member' => 'Deze persoon zit al in de workspace.',
    ],

    'member' => [
        'last_owner' => 'Er moet altijd minstens één eigenaar zijn. Wijs eerst iemand anders aan.',

        /*
         * Vier zinnen in plaats van één "ongeldig formaat": een handle wordt
         * op vier manieren afgekeurd en welke van de vier het was, is precies
         * wat iemand nodig heeft om het te herstellen.
         */
        'handle_shape' => 'Een handle bestaat uit letters, cijfers, streepjes en liggende streepjes, eventueel met punten ertussen — bijvoorbeeld fenna.jansen.',
        'handle_long' => 'Een handle is hoogstens 30 tekens lang.',
        'handle_taken' => 'Deze handle is al van iemand anders.',
        'handle_reserved' => 'Deze handle is gereserveerd: @here en @everyone spreken een hele groep aan.',
    ],

    'notifications' => [
        'invalid_window' => 'Kies een van de aangeboden termijnen.',
    ],
];
