<?php

/*
 * Het scherm waarop een workspace zijn eigen rollen schrijft.
 *
 * Apart bestand omdat het een scherm met een eigen onderwerp is: hier staat wie
 * wat mag, en dat is iets anders dan de instellingen eromheen.
 */

return [
    'title' => 'Rollen',
    'description' => 'Wie wat mag in :workspace',

    'explanation' => 'Een rol is een naam en een setje rechten. Je kunt er zelf een maken — een leverancier, een vrijwilliger, een bestuurslid — en precies aanvinken wat die mag.',

    'system' => 'Meegeleverd',
    'external' => 'Van buiten',
    'external_hint' => 'Iemand van buiten ziet alleen de kanalen waarvoor hij is uitgenodigd. Openbare kanalen bestaan voor hem niet.',
    'external_locked' => 'Dit ligt vast zodra iemand de rol heeft: het verplaatst mensen over de grens die bepaalt welke kanalen voor hen bestaan.',

    'holders' => '{0}Niemand heeft deze rol|{1}1 persoon|[2,*]:count mensen',
    'name' => 'Naam',
    'abilities' => 'Wat deze rol mag',
    'not_yours' => 'Deze rol reikt verder dan die van jou, dus je kunt hem niet aanpassen.',
    'beyond_your_own' => 'Je kunt een rol niet meer geven dan je zelf hebt.',
    'still_held' => 'Er zitten nog mensen in deze rol. Geef ze eerst een andere.',
    'system_role' => 'Een meegeleverde rol kun je niet verwijderen.',
    'too_many' => 'Meer dan :count rollen in één workspace is meer dan iemand overziet.',

    'new' => 'Nieuwe rol',
    'new_name' => 'Hoe heet de rol?',
    'create' => 'Aanmaken',
    'save' => 'Opslaan',
    'delete' => 'Rol verwijderen',
];
