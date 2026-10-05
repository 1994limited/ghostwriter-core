<?php

/*
 * French for the SEO layer's "Finish this page" strings (keys without the `gaps.` prefix).
 * Only the SEO strings are translated (decision 18); a key that isn't
 * here falls back to English. Parameters are kept as they are (`:label`).
 */

return [
    'seo-length' => ':label est trop long.',
    'seo-missing' => 'La description SEO est vide : les moteurs de recherche en choisiront une eux-mêmes.',
    'seo-missing.draft' => 'La description SEO est vide : les moteurs de recherche en choisiront une eux-mêmes. Voici celle du brouillon : « :text »',
    'seo-missing.short' => 'La description SEO ne fait que :length caractères. Les résultats de recherche en affichent jusqu’à environ :limit.',
    'seo-missing.short-draft' => 'La description SEO ne fait que :length caractères. Voici celle du brouillon : « :text »',
    'seo-missing.inherited' => 'La description SEO vient de :field, qui est vide : les moteurs de recherche en choisiront une eux-mêmes.',
    'seo-missing.inherited-draft' => 'La description SEO vient de :field, qui est vide. En voici une pour cette page : « :text »',
    'heading-long' => 'Ce titre dans :label fait :length caractères. Au-delà de 70, il se lit mal en diagonale et il est coupé dans les résultats de recherche.',
    'few-links' => 'Cette page ne renvoie vers aucune de vos autres pages. Quelques liens aident les lecteurs, et les moteurs de recherche, à trouver les pages proches.',
    'links-added' => 'Vérifiez les :count liens ajoutés par Ghostwriter. « :words » mène à :title (:url). Gardez-le, ou retirez le lien en gardant les mots.',
    'links-added-one' => 'Vérifiez le lien ajouté par Ghostwriter. « :words » mène à :title (:url). Gardez-le, ou retirez le lien en gardant les mots.',
    'speech.seo-length' => 'Trop long',
    'speech.seo-missing' => 'Vide !',
    'speech.links-added' => 'Lié',
    'speech.heading-long' => 'Trop long',
    'speech.few-links' => 'Liez-moi',
    'step.seo-missing' => 'Ajouter une description pour la recherche',
    'step.heading-long' => 'Raccourcir un titre',
    'step.few-links' => 'Créer des liens vers vos autres pages',
    'fix.keep-link' => 'Le garder',
    'fix.use-text' => 'Utiliser ce texte',
    'fix.add-links' => 'Ajouter un lien',
    'fix.skip' => 'Passer',
];
