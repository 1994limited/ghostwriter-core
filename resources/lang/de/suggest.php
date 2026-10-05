<?php

/*
 * German for the SEO layer's Suggest edits strings (keys without the `suggest.` prefix).
 * Only the SEO strings are translated (decision 18); a key that isn't
 * here falls back to English. Parameters are kept as they are (`:label`).
 */

return [
    'category.seo' => 'SEO',
    'speech.seo' => 'Für die Suche',
    'finding.seo-length' => ':label hat :length Zeichen; erlaubt sind :limit.',
    'finding.seo-empty' => ':label ist leer. Seiten wie diese füllen es aus.',
    'finding.seo-missing' => 'Die SEO-Beschreibung ist leer, also wählen Suchmaschinen selbst einen Text.',
    'finding.seo-missing-inherited' => 'Die SEO-Beschreibung kommt aus :field, und das ist leer, also wählen Suchmaschinen selbst einen Text.',
    'finding.seo-missing-short' => 'Die SEO-Beschreibung hat nur :length Zeichen. Ideal sind :min bis :max.',
    'finding.heading-long' => 'Diese Überschrift hat :length Zeichen. Über 70 ist sie schwer zu überfliegen und wird in Suchergebnissen abgeschnitten.',
    'finding.few-links' => 'Diese Seite verlinkt auf keine Ihrer anderen Seiten.',
];
