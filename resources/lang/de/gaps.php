<?php

/*
 * German for the SEO layer's "Finish this page" strings (keys without the `gaps.` prefix).
 * Only the SEO strings are translated (decision 18); a key that isn't
 * here falls back to English. Parameters are kept as they are (`:label`).
 */

return [
    'seo-length' => ':label ist zu lang.',
    'seo-missing' => 'Die SEO-Beschreibung ist leer, also wählen Suchmaschinen selbst einen Text.',
    'seo-missing.draft' => 'Die SEO-Beschreibung ist leer, also wählen Suchmaschinen selbst einen Text. Hier ist die aus dem Entwurf: „:text“',
    'seo-missing.short' => 'Die SEO-Beschreibung hat nur :length Zeichen. Suchergebnisse zeigen bis zu etwa :limit.',
    'seo-missing.short-draft' => 'Die SEO-Beschreibung hat nur :length Zeichen. Hier ist die aus dem Entwurf: „:text“',
    'seo-missing.inherited' => 'Die SEO-Beschreibung kommt aus :field, und das ist leer, also wählen Suchmaschinen selbst einen Text.',
    'seo-missing.inherited-draft' => 'Die SEO-Beschreibung kommt aus :field, und das ist leer. Hier ist eine für diese Seite: „:text“',
    'heading-long' => 'Diese Überschrift in :label hat :length Zeichen. Über 70 ist sie schwer zu überfliegen und wird in Suchergebnissen abgeschnitten.',
    'few-links' => 'Diese Seite verlinkt auf keine Ihrer anderen Seiten. Ein paar Links helfen Lesern und Suchmaschinen, verwandte Seiten zu finden.',
    'links-added' => 'Prüfen Sie die :count Links, die Ghostwriter gesetzt hat. „:words“ führt zu :title (:url). Behalten Sie ihn, oder entfernen Sie den Link und behalten Sie die Wörter.',
    'links-added-one' => 'Prüfen Sie den Link, den Ghostwriter gesetzt hat. „:words“ führt zu :title (:url). Behalten Sie ihn, oder entfernen Sie den Link und behalten Sie die Wörter.',
    'speech.seo-length' => 'Zu lang',
    'speech.seo-missing' => 'Leer!',
    'speech.links-added' => 'Verlinkt',
    'speech.heading-long' => 'Zu lang',
    'speech.few-links' => 'Verlinke mich',
    'step.seo-missing' => 'Eine Beschreibung für die Suche hinzufügen',
    'step.heading-long' => 'Eine Überschrift kürzen',
    'step.few-links' => 'Auf Ihre anderen Seiten verlinken',
    'fix.keep-link' => 'Behalten',
    'fix.use-text' => 'Übernehmen',
    'fix.add-links' => 'Einen Link setzen',
    'fix.skip' => 'Überspringen',
];
