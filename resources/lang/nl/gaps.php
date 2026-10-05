<?php

/*
 * Dutch for the SEO layer's "Finish this page" strings (keys without the `gaps.` prefix).
 * Only the SEO strings are translated (decision 18); a key that isn't
 * here falls back to English. Parameters are kept as they are (`:label`).
 */

return [
    'seo-length' => ':label is te lang.',
    'seo-missing' => 'De SEO-beschrijving is leeg, dus zoekmachines kiezen zelf een tekst.',
    'seo-missing.draft' => 'De SEO-beschrijving is leeg, dus zoekmachines kiezen zelf een tekst. Hier is die uit het concept: ‘:text’',
    'seo-missing.short' => 'De SEO-beschrijving is maar :length tekens. Zoekresultaten tonen er tot ongeveer :limit.',
    'seo-missing.short-draft' => 'De SEO-beschrijving is maar :length tekens. Hier is die uit het concept: ‘:text’',
    'seo-missing.inherited' => 'De SEO-beschrijving komt uit :field, en dat is leeg, dus zoekmachines kiezen zelf een tekst.',
    'seo-missing.inherited-draft' => 'De SEO-beschrijving komt uit :field, en dat is leeg. Hier is er een voor deze pagina: ‘:text’',
    'heading-long' => 'Deze kop in :label is :length tekens. Boven de 70 is hij lastig te scannen en wordt hij in zoekresultaten afgekapt.',
    'few-links' => 'Deze pagina linkt naar geen enkele van uw andere pagina’s. Een paar links helpen lezers, en zoekmachines, verwante pagina’s te vinden.',
    'links-added' => 'Controleer de :count links die Ghostwriter heeft toegevoegd. ‘:words’ gaat naar :title (:url). Houd hem, of verwijder de link en houd de woorden.',
    'links-added-one' => 'Controleer de link die Ghostwriter heeft toegevoegd. ‘:words’ gaat naar :title (:url). Houd hem, of verwijder de link en houd de woorden.',
    'speech.seo-length' => 'Te lang',
    'speech.seo-missing' => 'Leeg!',
    'speech.links-added' => 'Gelinkt',
    'speech.heading-long' => 'Te lang',
    'speech.few-links' => 'Link me',
    'step.seo-missing' => 'Een beschrijving voor zoekmachines toevoegen',
    'step.heading-long' => 'Een kop inkorten',
    'step.few-links' => 'Naar uw andere pagina’s linken',
    'fix.keep-link' => 'Houden',
    'fix.use-text' => 'Deze gebruiken',
    'fix.add-links' => 'Een link toevoegen',
    'fix.skip' => 'Overslaan',
];
