<?php

/*
 * French phrase lists for the free checks. See en.php for what each list is.
 */

return [
    'current' => [
        'nouveau(?:té)?s? (?:pour |en |de )?{year}',
        'nouvelle (?:pour |en )?{year}',
        '(?:nos |les )?(?:tarifs|prix|programme|saison|catalogue|brochure|horaires|menu|offres?|dates) (?:pour |de |en )?{year}',
        'saison {year}',
        'édition {year}',
        '(?:en|pour) {year},? nous (?:proposons|sommes|avons|offrons|ouvrons)',
        'à partir de {year},? (?:nous|les|le|la)',
        'réservations? (?:pour|en) {year}',
        '(?:valable|mis à jour) (?:pour |en )?{year}',
    ],
    'history' => [
        'depuis {year}',
        '(?:fondée?s?|créée?s?|établie?s?|ouverte?s?|construite?s?|lancée?s?|née?s?) en {year}',
        'en {year},? (?:nous avons|nous étions|il a|elle a|ils ont|elles ont|on a|nous avons été|le|la|les) ',
        'de {year} à',
        'jusqu\'en {year}',
        'dès {year}',
        '{year} ?- ?(?:19|20)\d\d',
        '(?:début|fin|mi-|printemps|été|automne|hiver) (?:de |d\')?{year}',
    ],
    'relative' => [
        'cette année', 'l\'année prochaine', 'l\'an prochain', 'cette saison', 'actuellement', 'en ce moment', 'à présent',
        'bientôt', 'prochainement', 'récemment', 'tout nouveau', 'toute nouvelle', 'nouvellement', 'cet été', 'cet hiver',
        'cet automne', 'ce printemps', 'le printemps prochain', 'l\'été prochain', 'à venir',
    ],
    'closing' => [
        'jusqu\'au', 'date limite', 'clôture(?: le)?', 'se termine le', 'avant le', 'fin le', 'valable jusqu\'au',
        'inscriptions? jusqu\'au', 'au plus tard le', 'expire le',
    ],
    'months' => [
        'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7,
        'août' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12,
        'janv' => 1, 'févr' => 2, 'avr' => 4, 'juil' => 7, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'déc' => 12,
    ],
    'counts' => [
        'équipe de {n}',
        '{n} (?:collaborateurs|collaboratrices|employés|salariés|designers|experts|spécialistes|jardiniers|architectes|avocats|développeurs|bénévoles)',
        '(?:plus de|près de|environ|presque) {n} (?:ans|clients|projets|collaborateurs|salariés|agences|magasins|pays|prix)',
        '{n}\+? (?:ans d\'expérience|clients satisfaits|clients|projets|agences|magasins|boutiques)',
        '(?:à partir de|dès|seulement) {n} ?€',
    ],
    'numbers' => [
        'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9,
        'dix' => 10, 'onze' => 11, 'douze' => 12, 'quinze' => 15, 'vingt' => 20, 'trente' => 30, 'cinquante' => 50,
    ],
    'link_text' => ['ici', 'cliquez ici', 'cliquer ici', 'en savoir plus', 'lire la suite', 'plus', 'ce lien', 'lien', 'voir plus', 'plus d\'infos'],
    'long_sentence' => 35,
    'stop_words' => [
        'le', 'la', 'les', 'l', 'un', 'une', 'des', 'du', 'de', 'd', 'et', 'ou', 'mais', 'donc', 'or', 'ni', 'car',
        'à', 'au', 'aux', 'en', 'dans', 'sur', 'sous', 'par', 'pour', 'avec', 'sans', 'chez', 'entre', 'vers',
        'contre', 'depuis', 'pendant', 'avant', 'après', 'comme', 'que', 'quoi', 'dont', 'où', 'quand', 'si',
        'ne', 'pas', 'plus', 'moins', 'très', 'tout', 'tous', 'toute', 'toutes', 'ce', 'cet', 'cette', 'ces', 'son',
        'sa', 'ses', 'leur', 'leurs', 'notre', 'nos', 'votre', 'vos', 'mon', 'ma', 'mes', 'ton', 'ta', 'tes', 'il',
        'elle', 'ils', 'elles', 'on', 'nous', 'vous', 'je', 'me', 'te', 'se', 'y', 'est', 'sont', 'été', 'être', 'a',
        'ont', 'avait', 'avoir', 'fait', 'faire', 'peut', 'peuvent', 'doit', 'aussi', 'encore', 'déjà', 'bien', 'ici',
        'là',
    ],
    'utility_slugs' => [
        'recherche', 'connexion', 'deconnexion', 'déconnexion', 'compte', 'mon-compte',
        'inscription', 'panier', 'paiement', 'merci', 'confirmation', 'desinscription', 'désinscription',
        'erreur', 'page-introuvable', 'plan-du-site', 'mot-de-passe',
    ],
    // Words photo libraries put in titles that say nothing about the photo: left out of file names (Seo\FilenameRules).
    'filename_noise' => ['photo de stock', 'photo stock', 'image de stock', 'banque d images', 'libre de droits', 'photo gratuite', 'image de', 'photo de', 'gros plan', 'unsplash', 'pexels', 'pixabay', 'shutterstock', 'istock', 'adobe stock'],
];
