<?php

/*
 * French for the SEO layer's strings (`seo.*`): the after-draft notice, the links Ghostwriter added and the Search section.
 * Only the SEO strings are translated (decision 18); a key that isn't
 * here falls back to English. Parameters are kept as they are (`:label`).
 */

return [
    'notice.links' => 'J’ai ajouté des liens vers :count de vos pages : :titles.',
    'notice.links-one' => 'J’ai ajouté un lien vers une de vos pages : :titles.',
    'notice.no-links' => 'Je n’ai pas trouvé de pages assez proches pour y renvoyer.',
    'status.checking' => 'Brouillon prêt. Je vérifie les titres et les liens…',
    'link.added' => 'Ajouté par Ghostwriter',
    'link.added-long' => 'Ajouté par Ghostwriter. Si vous le retirez, les mots restent.',
    'link.remove' => 'Retirer le lien',
    'link.open' => 'Ouvrir la page',
    'link.removed' => 'Lien retiré. Les mots restent.',
    'search.heading' => 'Recherche',
    'search.intro' => 'Comment cette page peut apparaître dans les résultats de recherche. Rien n’est enregistré ici tant que vous n’utilisez pas le brouillon.',
    'search.title' => 'Titre SEO',
    'search.description' => 'Méta-description',
    'search.address' => 'Adresse',
    'search.uses-title' => 'Reprend le titre de la page : « :title »',
    'search.title-fits' => 'Il tient dans la limite, donc vos réglages SEO continuent de l’utiliser.',
    'search.title-long' => 'Le titre de la page est trop long pour les résultats de recherche : la page a besoin d’un titre à elle.',
    'search.title-own' => 'Un titre à elle, plus court que le titre de la page.',
    'search.title-stays' => 'Votre titre SEO reste. Suggestion à la place :',
    'search.give-own' => 'Lui donner le sien',
    'search.use-page-title' => 'Reprendre le titre de la page',
    'search.description-new' => 'Uniquement ce que dit la page.',
    'search.description-source-empty' => 'Elle venait de :field, qui est vide : cette page a donc la sienne. Uniquement ce que dit la page.',
    'search.description-stays' => 'Votre description SEO reste. Suggestion à la place :',
    'search.description-inherits' => 'Reprend :field. Elle tient dans la limite, donc vos réglages SEO continuent de l’utiliser.',
    'search.description-dropped' => 'Je n’ai pas pu en écrire une à partir de ce que dit la page. Réessayez, ou écrivez la vôtre.',
    'search.description-empty' => 'Vide : « Finir cette page » vous en proposera une.',
    'search.edited' => 'C’est le vôtre désormais : un prochain échange ne le réécrira pas.',
    'search.template' => 'Vos réglages SEO le génèrent.',
    'search.off' => 'Désactivé dans vos réglages SEO.',
    'search.use-this' => 'Utiliser ce texte',
    'search.keep-mine' => 'Garder le mien',
    'search.range' => 'Visez :min à :max caractères',
    'search.address-new' => 'Définie seulement pour cette nouvelle page. Les pages publiées gardent leur adresse.',
    'search.address-kept' => 'Les pages publiées gardent leur adresse.',
    'search.try-again' => 'Réessayer',
    'search.try-again-note' => 'Demande un autre titre et une autre description. Utilise Ghostwriter.',
    'search.writing' => 'J’en écris un autre…',
    'search.failed' => 'Ça n’a pas marché. Réessayez dans un instant.',
];
