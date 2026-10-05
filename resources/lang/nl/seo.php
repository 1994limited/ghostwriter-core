<?php

/*
 * Dutch for the SEO layer's strings (`seo.*`): the after-draft notice, the links Ghostwriter added and the Search section.
 * Only the SEO strings are translated (decision 18); a key that isn't
 * here falls back to English. Parameters are kept as they are (`:label`).
 */

return [
    'notice.links' => 'Ik heb naar :count van uw pagina’s gelinkt: :titles.',
    'notice.links-one' => 'Ik heb naar een van uw pagina’s gelinkt: :titles.',
    'notice.no-links' => 'Ik heb geen pagina’s gevonden die nauw genoeg aansluiten om naar te linken.',
    'status.checking' => 'Concept klaar. Ik controleer de koppen en links…',
    'link.added' => 'Toegevoegd door Ghostwriter',
    'link.added-long' => 'Toegevoegd door Ghostwriter. Als u hem verwijdert, blijven de woorden staan.',
    'link.remove' => 'Link verwijderen',
    'link.open' => 'Pagina openen',
    'link.removed' => 'Link verwijderd. De woorden blijven.',
    'search.heading' => 'Zoeken',
    'search.intro' => 'Zo kan deze pagina in zoekresultaten verschijnen. Hier wordt pas iets opgeslagen als u het concept gebruikt.',
    'search.title' => 'SEO-titel',
    'search.description' => 'Metabeschrijving',
    'search.address' => 'Adres',
    'search.uses-title' => 'Gebruikt de paginatitel: ‘:title’',
    'search.title-fits' => 'Die past, dus uw SEO-instellingen blijven hem gebruiken.',
    'search.title-long' => 'De paginatitel is te lang voor zoekresultaten, dus de pagina heeft een eigen titel nodig.',
    'search.title-own' => 'Een eigen titel, korter dan de paginatitel.',
    'search.title-stays' => 'Uw SEO-titel blijft. In plaats daarvan voorgesteld:',
    'search.give-own' => 'Een eigen geven',
    'search.use-page-title' => 'Paginatitel gebruiken',
    'search.description-new' => 'Alleen wat de pagina zegt.',
    'search.description-source-empty' => 'Die kwam uit :field, en dat is leeg, dus deze pagina krijgt een eigen beschrijving. Alleen wat de pagina zegt.',
    'search.description-stays' => 'Uw SEO-beschrijving blijft. In plaats daarvan voorgesteld:',
    'search.description-inherits' => 'Gebruikt :field. Die past, dus uw SEO-instellingen blijven hem gebruiken.',
    'search.description-dropped' => 'Ik kon er geen schrijven op basis van wat de pagina zegt. Probeer het opnieuw, of schrijf uw eigen.',
    'search.description-empty' => 'Leeg: ‘Pagina afmaken’ biedt er een aan.',
    'search.edited' => 'Nu van u: een latere ronde schrijft hem niet opnieuw.',
    'search.template' => 'Uw SEO-instellingen maken deze.',
    'search.off' => 'Uitgeschakeld in uw SEO-instellingen.',
    'search.use-this' => 'Deze gebruiken',
    'search.keep-mine' => 'Mijn eigen houden',
    'search.range' => 'Mik op :min tot :max tekens',
    'search.address-new' => 'Alleen ingesteld op deze nieuwe pagina. Gepubliceerde pagina’s houden hun adres.',
    'search.address-kept' => 'Gepubliceerde pagina’s houden hun adres.',
    'search.try-again' => 'Opnieuw proberen',
    'search.try-again-note' => 'Vraagt om een andere titel en beschrijving. Gebruikt Ghostwriter.',
    'search.writing' => 'Ik schrijf een andere…',
    'search.failed' => 'Dat lukte niet. Probeer het zo meteen opnieuw.',
];
