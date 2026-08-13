<?php

/*
 * What the scheduled clean-up commands report.
 *
 * On the command line rather than on a screen, but translated all the same:
 * these run under the application's own locale, and a Dutch installation whose
 * cron mail comes back in English is a small daily annoyance for no reason.
 */

return [
    'nothing_to_prune' => 'Niets om op te ruimen.',
    'inbox_pruned' => '{1}1 inboxregel opgeruimd.|[2,*]:count inboxregels opgeruimd.',
    'transfers_pruned' => '{1}1 verzending opgeruimd.|[2,*]:count verzendingen opgeruimd.',
    'secrets_pruned' => '{1}1 verzoek opgeruimd.|[2,*]:count verzoeken opgeruimd.',
    'documents_pruned' => '{1}1 document definitief verwijderd.|[2,*]:count documenten definitief verwijderd.',
    'contracts_expired' => '{0}Geen enkel contract verlopen.|{1}1 contract op verlopen gezet.|[2,*]:count contracten op verlopen gezet.',
    'contracts_pruned' => '{0}Geen contracten opgeruimd.|{1}1 contract opgeruimd.|[2,*]:count contracten opgeruimd.',
    'contracts_check_missing' => 'Ghostscript is niet te starten via :binary. Zonder deze binary weigert de contract-upload elk bestand.',
    'contracts_check_hint' => 'Installeer ghostscript en zet GHOSTSCRIPT_PATH in .env op het volledige pad — het PATH van php-fpm is niet dat van je shell.',
    'contracts_check_failed' => 'De proef-PDF kwam er niet doorheen: :reason',
    'contracts_check_unreadable' => 'Ghostscript liep, maar het resultaat is niet te importeren: :reason',
    'contracts_check_ok' => 'In orde: de proef-PDF is herschreven en weer ingelezen (:pages pagina).',
    'broadcasts_none' => 'Niets staat klaar.',
    'broadcasts_sent' => '{1}1 rondzending verstuurd.|[2,*]:count rondzendingen verstuurd.',
    'broadcasts_failed' => '{1}1 rondzending is niet gelukt.|[2,*]:count rondzendingen zijn niet gelukt.',
    'workflows_none_waiting' => 'Geen workflow staat te wachten.',
    'workflows_resumed' => '{1}1 workflow opgepakt.|[2,*]:count workflows opgepakt.',
    'workflows_none_due' => 'Geen workflow staat gepland voor nu.',
    'workflows_started' => '{1}1 workflow gestart.|[2,*]:count workflows gestart.',
    'workflow_runs_pruned' => '{1}1 workflowrun opgeruimd.|[2,*]:count workflowruns opgeruimd.',
    'no_stale_huddles' => 'Geen huddles om op te ruimen.',
    'huddles_swept' => '{1}1 huddle gesloten.|[2,*]:count huddles gesloten.',
    'notices_pruned' => '{1}1 melding opgeruimd.|[2,*]:count meldingen opgeruimd.',
    'role_abilities_in_sync' => 'Alle systeemrollen hebben de rechten die ze horen te hebben.',
    'role_abilities_owners_synced' => '{0}Geen eigenaarsrol aangepast.|{1}1 eigenaarsrol bijgewerkt.|[2,*]:count eigenaarsrollen bijgewerkt.',
    'role_abilities_others_synced' => '{0}Geen overige systeemrollen aangepast.|{1}1 overige systeemrol bijgewerkt.|[2,*]:count overige systeemrollen bijgewerkt.',
    'role_abilities_owners_pending' => '{0}Geen eigenaarsrol zou veranderen.|{1}1 eigenaarsrol zou bijgewerkt worden.|[2,*]:count eigenaarsrollen zouden bijgewerkt worden.',
    'role_abilities_others_pending' => '{0}Geen overige systeemrollen zouden veranderen.|{1}1 overige systeemrol zou bijgewerkt worden.|[2,*]:count overige systeemrollen zouden bijgewerkt worden.',
];
