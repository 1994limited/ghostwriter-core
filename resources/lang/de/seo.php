<?php

/*
 * German for the SEO layer's strings (`seo.*`): the after-draft notice, the links Ghostwriter added and the Search section.
 * Only the SEO strings are translated (decision 18); a key that isn't
 * here falls back to English. Parameters are kept as they are (`:label`).
 */

return [
    'notice.links' => 'Ich habe auf :count Ihrer Seiten verlinkt: :titles.',
    'notice.links-one' => 'Ich habe auf eine Ihrer Seiten verlinkt: :titles.',
    'notice.no-links' => 'Ich habe keine Seiten gefunden, die gut genug passen, um darauf zu verlinken.',
    'status.checking' => 'Entwurf fertig. Überschriften und Links werden geprüft…',
    'link.added' => 'Von Ghostwriter gesetzt',
    'link.added-long' => 'Von Ghostwriter gesetzt. Wenn Sie ihn entfernen, bleiben die Wörter stehen.',
    'link.remove' => 'Link entfernen',
    'link.open' => 'Seite öffnen',
    'link.removed' => 'Link entfernt. Die Wörter bleiben.',
    'search.heading' => 'Suche',
    'search.intro' => 'So kann diese Seite in Suchergebnissen erscheinen. Gespeichert wird hier erst etwas, wenn Sie den Entwurf übernehmen.',
    'search.title' => 'SEO-Titel',
    'search.description' => 'Meta-Beschreibung',
    'search.address' => 'Adresse',
    'search.uses-title' => 'Nutzt den Seitentitel: „:title“',
    'search.title-fits' => 'Er passt, also verwenden Ihre SEO-Einstellungen ihn weiter.',
    'search.title-long' => 'Der Seitentitel ist zu lang für Suchergebnisse, deshalb braucht die Seite einen eigenen.',
    'search.title-own' => 'Ein eigener Titel, kürzer als der Seitentitel.',
    'search.title-stays' => 'Ihr SEO-Titel bleibt. Stattdessen vorgeschlagen:',
    'search.give-own' => 'Eigenen vergeben',
    'search.use-page-title' => 'Seitentitel verwenden',
    'search.description-new' => 'Nur was auf der Seite steht.',
    'search.description-source-empty' => 'Sie kam aus :field, und das ist leer, deshalb bekommt diese Seite eine eigene. Nur was auf der Seite steht.',
    'search.description-stays' => 'Ihre SEO-Beschreibung bleibt. Stattdessen vorgeschlagen:',
    'search.description-inherits' => 'Nutzt :field. Sie passt, also verwenden Ihre SEO-Einstellungen sie weiter.',
    'search.description-dropped' => 'Aus dem, was auf der Seite steht, konnte ich keine schreiben. Versuchen Sie es erneut, oder schreiben Sie Ihre eigene.',
    'search.description-empty' => 'Leer: „Seite fertigstellen“ bietet Ihnen eine an.',
    'search.edited' => 'Jetzt Ihr Text: Ein späterer Durchgang schreibt ihn nicht neu.',
    'search.template' => 'Ihre SEO-Einstellungen erzeugen diesen Wert.',
    'search.off' => 'In Ihren SEO-Einstellungen ausgeschaltet.',
    'search.use-this' => 'Übernehmen',
    'search.keep-mine' => 'Meine behalten',
    'search.range' => 'Ideal sind :min bis :max Zeichen',
    'search.address-new' => 'Wird nur bei dieser neuen Seite gesetzt. Veröffentlichte Seiten behalten ihre Adresse.',
    'search.address-kept' => 'Veröffentlichte Seiten behalten ihre Adresse.',
    'search.try-again' => 'Erneut versuchen',
    'search.try-again-note' => 'Fragt nach einem anderen Titel und einer anderen Beschreibung. Nutzt Ghostwriter.',
    'search.writing' => 'Ich schreibe einen anderen…',
    'search.failed' => 'Das hat nicht geklappt. Versuchen Sie es gleich noch einmal.',
];
