<?php

/*
 * Dutch for the SEO layer's Suggest edits strings (keys without the `suggest.` prefix).
 * Only the SEO strings are translated (decision 18); a key that isn't
 * here falls back to English. Parameters are kept as they are (`:label`).
 */

return [
    'category.seo' => 'SEO',
    'speech.seo' => 'Voor zoekmachines',
    'finding.seo-length' => ':label is :length tekens; de limiet is :limit.',
    'finding.seo-empty' => ':label is leeg. Pagina’s zoals deze vullen het in.',
    'finding.seo-missing' => 'De SEO-beschrijving is leeg, dus zoekmachines kiezen zelf een tekst.',
    'finding.seo-missing-inherited' => 'De SEO-beschrijving komt uit :field, en dat is leeg, dus zoekmachines kiezen zelf een tekst.',
    'finding.seo-missing-short' => 'De SEO-beschrijving is maar :length tekens. Mik op :min tot :max.',
    'finding.heading-long' => 'Deze kop is :length tekens. Boven de 70 is hij lastig te scannen en wordt hij in zoekresultaten afgekapt.',
    'finding.few-links' => 'Deze pagina linkt naar geen enkele van uw andere pagina’s.',
];
