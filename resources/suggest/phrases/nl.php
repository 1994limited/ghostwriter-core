<?php

/*
 * Dutch phrase lists for the free checks. See en.php for what each list is.
 */

return [
    'current' => [
        'nieuw (?:in |voor |vanaf )?{year}',
        '(?:onze |de )?(?:prijzen|tarieven|prijslijst|programma|seizoen|brochure|agenda|cursussen) (?:voor |van |in )?{year}',
        '{year} (?:prijzen|tarieven|programma|seizoen|editie)',
        'seizoen {year}',
        'editie {year}',
        'in {year} (?:bieden|hebben|zijn|openen) (?:we|wij)',
        'boekingen (?:voor|in) {year}',
        '(?:geldig|bijgewerkt) (?:voor |in |vanaf )?{year}',
    ],
    'history' => [
        'sinds {year}',
        '(?:opgericht|gesticht|geopend|gebouwd|gestart|begonnen) in {year}',
        'in {year} (?:werd|werden|hebben we|hebben wij|is|was|waren|won|wonnen|begonnen)\b',
        'van {year} tot',
        'tot {year}',
        'vanaf {year}',
        '{year} ?- ?(?:19|20)\d\d',
        '(?:begin|eind|midden|voorjaar|zomer|najaar|winter) (?:van )?{year}',
    ],
    'relative' => [
        'dit jaar', 'volgend jaar', 'komend jaar', 'dit seizoen', 'momenteel', 'op dit moment', 'binnenkort', 'onlangs',
        'recent', 'gloednieuw', 'nieuw geopend', 'deze zomer', 'deze winter', 'deze herfst', 'dit voorjaar', 'deze lente',
        'volgende zomer', 'later dit jaar',
    ],
    'closing' => [
        'tot en met', 't\\/m', 'tot', 'sluit(?: op)?', 'sluitingsdatum', 'deadline', 'uiterlijk', 'geldig tot', 'eindigt(?: op)?',
        'inschrijven kan tot', 'vóór', 'voor',
    ],
    'months' => [
        'januari' => 1, 'februari' => 2, 'maart' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6, 'juli' => 7,
        'augustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mrt' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9,
        'okt' => 10, 'nov' => 11, 'dec' => 12,
    ],
    'counts' => [
        'team van {n}',
        '{n} (?:medewerkers|ontwerpers|experts|specialisten|hoveniers|architecten|advocaten|ontwikkelaars|vrijwilligers|collega\'s)',
        '(?:meer dan|ruim|bijna|ongeveer|zo\'n) {n} (?:jaar|klanten|projecten|medewerkers|vestigingen|winkels|landen|prijzen)',
        '{n}\+? (?:jaar ervaring|tevreden klanten|klanten|projecten|vestigingen|winkels)',
        '(?:vanaf|slechts|al vanaf) € ?{n}',
    ],
    'numbers' => [
        'twee' => 2, 'drie' => 3, 'vier' => 4, 'vijf' => 5, 'zes' => 6, 'zeven' => 7, 'acht' => 8, 'negen' => 9,
        'tien' => 10, 'elf' => 11, 'twaalf' => 12, 'vijftien' => 15, 'twintig' => 20, 'dertig' => 30, 'vijftig' => 50,
    ],
    'link_text' => ['hier', 'klik hier', 'lees meer', 'meer', 'meer info', 'meer informatie', 'deze link', 'link', 'lees verder'],
    'long_sentence' => 28,
    'stop_words' => [
        'de', 'het', 'een', 'en', 'of', 'maar', 'want', 'dus', 'van', 'te', 'in', 'op', 'aan', 'bij', 'met', 'naar',
        'uit', 'voor', 'door', 'over', 'onder', 'tussen', 'tegen', 'zonder', 'sinds', 'tot', 'om', 'als', 'dan',
        'dat', 'die', 'dit', 'deze', 'daar', 'hier', 'er', 'is', 'zijn', 'was', 'waren', 'ben', 'bent', 'wordt',
        'worden', 'werd', 'werden', 'heeft', 'hebben', 'had', 'hadden', 'kan', 'kunnen', 'moet', 'moeten', 'zal',
        'zullen', 'wil', 'willen', 'mag', 'niet', 'geen', 'ook', 'nog', 'al', 'wel', 'zo', 'meer', 'alle', 'elk',
        'elke', 'ieder', 'wie', 'wat', 'waar', 'wanneer', 'waarom', 'hoe', 'welk', 'welke', 'ik', 'mij', 'mijn', 'je',
        'jij', 'jou', 'jouw', 'u', 'uw', 'hij', 'zij', 'ze', 'haar', 'hem', 'wij', 'we', 'ons', 'onze', 'jullie',
        'hun', 'zich', 'men',
    ],
    'utility_slugs' => [
        'zoeken', 'zoekresultaten', 'inloggen', 'uitloggen', 'login', 'account', 'mijn-account', 'registreren',
        'winkelwagen', 'winkelmand', 'afrekenen', 'bestelling', 'bedankt', 'dankjewel', 'dank-u', 'bevestiging',
        'uitschrijven', 'fout', 'pagina-niet-gevonden', 'sitemap', 'wachtwoord',
    ],
];
