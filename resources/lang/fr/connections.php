<?php

/*
 * French for Settings → Connections (Connections\Strings). A key that isn't
 * here falls back to English. Parameters are kept as they are (`:service`).
 * Button names on the services' own pages stay as those pages show them.
 */

return [
    'title' => 'Connexions',
    'intro' => 'Les services avec lesquels Ghostwriter écrit, crée des images et trouve des photos. Configurez chacun ici : ouvrez sa page, créez une clé, collez-la.',
    'privacy' => 'Les clés sont conservées chiffrées sur ce site et ne sont envoyées qu’au service auquel elles appartiennent. Elles n’atteignent jamais les serveurs de Ghostwriter.',
    'environment' => 'Vous êtes en :environment.',
    'environment.note' => 'Ce que vous configurez ici ne vaut que pour ce site. Vos autres copies (locale, préproduction, en ligne) gardent chacune les leurs, elles peuvent donc différer.',
    'environment.local' => 'local',
    'environment.staging' => 'préproduction',
    'environment.production' => 'production',
    'nav' => 'Connexions',

    'group.writing' => 'Rédaction',
    'group.writing.intro' => 'Le modèle qui écrit. Un seul suffit ; choisissez lequel dans les réglages.',
    'group.images' => 'Images',
    'group.images.intro' => 'Des photothèques gratuites où chercher. Les images sont créées avec votre clé OpenAI, Gemini ou OpenRouter de la partie Rédaction.',
    'group.stock' => 'Photos payantes',
    'group.stock.intro' => 'Des photothèques payantes, sous licence depuis votre propre compte chez elles.',
    'group.test' => 'Services de test',
    'group.test.intro' => 'Seuls les tests de bout en bout les voient, sur un site local.',

    'status.connected' => 'Connecté · clé se terminant par :ending',
    'status.not-set' => 'Non configuré',
    'status.env' => 'Défini dans .env',
    'status.config' => 'Défini dans la configuration',
    'status.broken' => 'La clé ne fonctionne plus',
    'status.no-key' => 'Aucune clé nécessaire',
    'status.env.help' => 'Ce site utilise :variable de son fichier .env, qui l’emporte sur tout ce qui est configuré ici. Pour le changer, changez-le là.',
    'status.config.help' => 'Ce site définit la clé dans sa configuration, qui l’emporte sur tout ce qui est configuré ici. Pour la changer, changez-la là.',
    'status.broken.help' => ':service n’accepte plus cette clé. Créez-en une nouvelle et remplacez-la.',
    'status.env.broken' => ':service n’accepte plus la clé du fichier .env. Changez :variable là.',
    'status.no-key.help' => 'Activez-le ou désactivez-le dans les réglages.',
    'status.via-connect' => 'Connecté en se connectant à :service.',
    'makes-images' => 'Crée aussi des images',

    'action.setup' => 'Configurer',
    'action.replace' => 'Remplacer la clé',
    'action.disconnect' => 'Déconnecter',
    'action.open' => 'Ouvrir :service',
    'action.check' => 'Vérifier et enregistrer',
    'action.checking' => 'Vérification…',
    'action.cancel' => 'Annuler',

    'panel.title' => 'Configurer :service',
    'panel.replace' => 'Remplacer la clé de :service',
    'panel.opens' => 'S’ouvre dans un nouvel onglet.',
    'panel.paste' => 'Collez-la ici',
    'panel.private' => 'Conservée chiffrée sur ce site. Ghostwriter ne l’affiche plus jamais, seulement ses quatre derniers caractères.',
    'field.key' => 'Clé d’API',
    'field.secret' => 'Secret',
    'field.token' => 'Jeton d’accès',

    'disconnect.title' => 'Déconnecter :service ?',
    'disconnect.body' => 'Ghostwriter oublie cette clé et n’utilise plus :service jusqu’à ce qu’il soit de nouveau configuré. La clé fonctionne toujours chez :service : supprimez-la là-bas si vous n’en avez plus besoin.',

    'saved' => ':service est connecté.',
    'disconnected' => ':service est déconnecté.',

    'check.missing' => 'Collez d’abord : :field.',
    'check.refused' => ':service n’a pas accepté cette clé. Vérifiez que vous l’avez copiée en entier, sans rien avant ni après.',
    'check.unreachable' => 'Impossible de joindre :service pour l’instant. Vérifiez que ce site accède à Internet, puis réessayez.',
    'check.busy' => ':service est occupé ou limite les requêtes. Réessayez dans une minute.',
    'check.failed' => ':service n’a pas pu vérifier la clé : :reason',
    'env-wins' => ':service est défini dans .env (:variable), qui l’emporte. Changez-le là, ou retirez-le de .env pour le configurer ici.',
    'unknown' => 'Ghostwriter ne connaît aucun service appelé « :service ».',
    'forbidden' => 'Seule une personne autorisée à modifier les réglages de Ghostwriter peut configurer les connexions.',

    'oauth.key' => 'Ou connectez-vous à :service et laissez-le créer une clé pour vous.',
    'oauth.key.button' => 'Se connecter avec :service',
    'oauth.account' => 'Pour acheter des licences, votre compte :service doit aussi être connecté.',
    'oauth.account.connect' => 'Connecter le compte',
    'oauth.account.disconnect' => 'Déconnecter le compte',
    'oauth.account.connected' => 'Compte connecté',
    'oauth.account.not-connected' => 'Compte non connecté',
    'oauth.account.needs-key' => 'Configurez d’abord la clé et le secret, puis connectez votre compte.',
    'oauth.account.callback' => 'Dans les réglages de votre application chez :service, ajoutez ce rappel :',

    'elsewhere.link' => 'Configurer dans Connexions',
    'elsewhere.hint' => 'Configurer dans Connexions (ou définir :variable dans .env).',
    'elsewhere.keys' => 'Les clés se configurent dans Connexions, ou dans votre fichier .env, qui l’emporte.',

    'anthropic.about' => 'Claude, d’Anthropic. Rédige vos brouillons.',
    'anthropic.step.1' => 'Connectez-vous à la Claude Console et ouvrez « API keys ».',
    'anthropic.step.2' => 'Cliquez sur « Create key », nommez-la d’après ce site et copiez-la.',
    'anthropic.step.3' => 'Collez-la ci-dessous. Elle commence par sk-ant-.',

    'openai.about' => 'Les modèles de ChatGPT, d’OpenAI. Rédige et crée des images.',
    'openai.step.1' => 'Connectez-vous à OpenAI Platform et ouvrez « API keys ».',
    'openai.step.2' => 'Cliquez sur « Create new secret key », nommez-la d’après ce site et copiez-la.',
    'openai.step.3' => 'Collez-la ci-dessous. Elle commence par sk-.',

    'gemini.about' => 'Gemini, de Google. Rédige et crée des images.',
    'gemini.step.1' => 'Connectez-vous à Google AI Studio avec votre compte Google.',
    'gemini.step.2' => 'Cliquez sur « Create API key », choisissez un projet et copiez la clé.',
    'gemini.step.3' => 'Collez-la ci-dessous. Elle commence par AIza.',

    'openrouter.about' => 'Claude, GPT, Gemini et d’autres avec un seul compte, payés en crédit OpenRouter. Rédige et crée des images.',
    'openrouter.step.1' => 'Connectez-vous à OpenRouter et ouvrez « Keys ».',
    'openrouter.step.2' => 'Cliquez sur « Create key », fixez une limite de crédit si vous le souhaitez, et copiez-la.',
    'openrouter.step.3' => 'Collez-la ci-dessous. Elle commence par sk-or-.',

    'unsplash.about' => 'Des photos gratuites d’Unsplash.',
    'unsplash.step.1' => 'Connectez-vous à Unsplash Developers et ouvrez « Your apps ».',
    'unsplash.step.2' => 'Cliquez sur « New Application », acceptez les conditions et donnez-lui un nom.',
    'unsplash.step.3' => 'Copiez l’« Access Key » (pas la « Secret key ») et collez-la ci-dessous.',
    'unsplash.field.key' => 'Access Key',

    'pexels.about' => 'Des photos gratuites de Pexels.',
    'pexels.step.1' => 'Connectez-vous à Pexels et ouvrez sa page API.',
    'pexels.step.2' => 'Cliquez sur « Your API Key », remplissez le court formulaire et copiez la clé.',
    'pexels.step.3' => 'Collez-la ci-dessous.',

    'pixabay.about' => 'Des photos gratuites de Pixabay.',
    'pixabay.step.1' => 'Connectez-vous à Pixabay et ouvrez sa documentation API.',
    'pixabay.step.2' => 'Votre clé figure sous « Parameters », à côté de « key ». Copiez-la.',
    'pixabay.step.3' => 'Collez-la ci-dessous.',

    'openverse.about' => 'Des photos du domaine public et CC0. Aucune clé nécessaire.',

    'shutterstock.about' => 'Des photos payantes, sous licence depuis votre propre abonnement API Shutterstock.',
    'shutterstock.step.1' => 'Connectez-vous à Shutterstock et ouvrez « My apps », sous « Developers ».',
    'shutterstock.step.2' => 'Cliquez sur « Create new app » (son hôte de rappel est l’adresse de ce site), puis copiez son « Consumer key » et son « Consumer secret ».',
    'shutterstock.step.3' => 'Collez les deux ci-dessous. Pour acheter des licences, connectez ensuite votre compte.',
    'shutterstock.field.key' => 'Consumer key',
    'shutterstock.field.secret' => 'Consumer secret',
];
