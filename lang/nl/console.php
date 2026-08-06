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
];
