<?php

/*
 * Dutch for Settings → Connections (Connections\Strings). A key that isn't
 * here falls back to English. Parameters are kept as they are (`:service`).
 * Button names on the services' own pages stay as those pages show them.
 */

return [
    'title' => 'Koppelingen',
    'intro' => 'De diensten waarmee Ghostwriter schrijft, afbeeldingen maakt en foto’s vindt. Stel ze hier een voor een in: open de pagina, maak een sleutel en plak die hier.',
    'privacy' => 'Sleutels worden versleuteld op deze site bewaard en alleen naar de dienst gestuurd waar ze bij horen. Ze komen nooit op de servers van Ghostwriter.',
    'environment' => 'U zit op :environment.',
    'environment.note' => 'Wat u hier instelt, geldt alleen voor deze site. Uw andere kopieën (lokaal, staging, live) hebben elk hun eigen sleutels, dus die kunnen verschillen.',
    'environment.local' => 'lokaal',
    'environment.staging' => 'staging',
    'environment.production' => 'productie',
    'nav' => 'Koppelingen',

    'group.writing' => 'Schrijven',
    'group.writing.intro' => 'Het model dat schrijft. Eén is genoeg; kies welk in de instellingen.',
    'group.images' => 'Afbeeldingen',
    'group.images.intro' => 'Gratis fotobibliotheken om in te zoeken. Afbeeldingen worden gemaakt met uw OpenAI-, Gemini- of OpenRouter-sleutel van Schrijven.',
    'group.stock' => 'Stockfoto’s',
    'group.stock.intro' => 'Betaalde bibliotheken, gelicentieerd via uw eigen account bij hen.',
    'group.test' => 'Testdiensten',
    'group.test.intro' => 'Alleen de end-to-endtests zien deze, op een lokale site.',

    'status.connected' => 'Gekoppeld · sleutel eindigt op :ending',
    'status.not-set' => 'Niet ingesteld',
    'status.env' => 'Ingesteld in .env',
    'status.config' => 'Ingesteld in de configuratie',
    'status.broken' => 'Sleutel werkt niet meer',
    'status.no-key' => 'Geen sleutel nodig',
    'status.env.help' => 'Deze site gebruikt :variable uit het .env-bestand, dat voorgaat op alles wat hier is ingesteld. Wijzig het daar.',
    'status.config.help' => 'Deze site zet de sleutel in de configuratie, die voorgaat op alles wat hier is ingesteld. Wijzig hem daar.',
    'status.broken.help' => ':service accepteert deze sleutel niet meer. Maak een nieuwe en vervang hem.',
    'status.env.broken' => ':service accepteert de sleutel in .env niet meer. Wijzig :variable daar.',
    'status.no-key.help' => 'Zet hem aan of uit in de instellingen.',
    'status.via-connect' => 'Gekoppeld door in te loggen bij :service.',
    'makes-images' => 'Maakt ook afbeeldingen',

    'action.setup' => 'Instellen',
    'action.replace' => 'Sleutel vervangen',
    'action.disconnect' => 'Ontkoppelen',
    'action.open' => ':service openen',
    'action.check' => 'Controleren en opslaan',
    'action.checking' => 'Controleren…',
    'action.cancel' => 'Annuleren',

    'panel.title' => ':service instellen',
    'panel.replace' => 'Sleutel voor :service vervangen',
    'panel.opens' => 'Opent in een nieuw tabblad.',
    'panel.paste' => 'Plak hem hier',
    'panel.private' => 'Versleuteld op deze site bewaard. Ghostwriter toont hem nooit meer, alleen de laatste vier tekens.',
    'field.key' => 'API-sleutel',
    'field.secret' => 'Geheim',
    'field.token' => 'Toegangstoken',

    'disconnect.title' => ':service ontkoppelen?',
    'disconnect.body' => 'Ghostwriter vergeet deze sleutel en gebruikt :service niet meer tot hij opnieuw is ingesteld. De sleutel werkt nog wel bij :service; verwijder hem daar als u hem niet meer nodig hebt.',

    'saved' => ':service is gekoppeld.',
    'disconnected' => ':service is ontkoppeld.',

    'check.missing' => 'Plak eerst de waarde voor :field.',
    'check.refused' => ':service heeft deze sleutel niet geaccepteerd. Controleer of u hem helemaal hebt gekopieerd, zonder iets ervoor of erna.',
    'check.unreachable' => ':service is nu niet bereikbaar. Controleer of deze site internet heeft en probeer het opnieuw.',
    'check.busy' => ':service is druk of beperkt het aantal verzoeken. Probeer het over een minuut opnieuw.',
    'check.failed' => ':service kon de sleutel niet controleren: :reason',
    'env-wins' => ':service is ingesteld in .env (:variable), en dat gaat voor. Wijzig het daar, of haal het uit .env om het hier in te stellen.',
    'unknown' => 'Ghostwriter kent geen dienst met de naam ‘:service’.',
    'forbidden' => 'Alleen wie de instellingen van Ghostwriter mag wijzigen, kan koppelingen instellen.',

    'oauth.key' => 'Of log in bij :service en laat daar een sleutel voor u maken.',
    'oauth.key.button' => 'Koppelen met :service',
    'oauth.account' => 'Om licenties te kopen moet uw :service-account ook gekoppeld zijn.',
    'oauth.account.connect' => 'Account koppelen',
    'oauth.account.disconnect' => 'Account ontkoppelen',
    'oauth.account.connected' => 'Account gekoppeld',
    'oauth.account.not-connected' => 'Account niet gekoppeld',
    'oauth.account.needs-key' => 'Stel eerst de sleutel en het geheim in en koppel daarna uw account.',
    'oauth.account.callback' => 'Voeg in de instellingen van uw app bij :service deze callback toe:',

    'elsewhere.link' => 'Instellen in Koppelingen',
    'elsewhere.hint' => 'Instellen in Koppelingen (of :variable zetten in .env).',
    'elsewhere.keys' => 'Sleutels stelt u in bij Koppelingen, of in uw .env-bestand, dat voorgaat.',

    'anthropic.about' => 'Claude, van Anthropic. Schrijft uw concepten.',
    'anthropic.step.1' => 'Log in bij de Claude Console en open ‘API keys’.',
    'anthropic.step.2' => 'Klik op ‘Create key’, geef hem de naam van deze site en kopieer hem.',
    'anthropic.step.3' => 'Plak hem hieronder. Hij begint met sk-ant-.',

    'openai.about' => 'De modellen achter ChatGPT, van OpenAI. Schrijft en maakt afbeeldingen.',
    'openai.step.1' => 'Log in bij het OpenAI Platform en open ‘API keys’.',
    'openai.step.2' => 'Klik op ‘Create new secret key’, geef hem de naam van deze site en kopieer hem.',
    'openai.step.3' => 'Plak hem hieronder. Hij begint met sk-.',

    'gemini.about' => 'Gemini, van Google. Schrijft en maakt afbeeldingen.',
    'gemini.step.1' => 'Log met uw Google-account in bij Google AI Studio.',
    'gemini.step.2' => 'Klik op ‘Create API key’, kies een project en kopieer de sleutel.',
    'gemini.step.3' => 'Plak hem hieronder. Hij begint met AIza.',

    'openrouter.about' => 'Claude, GPT, Gemini en andere via één account, betaald met OpenRouter-tegoed. Schrijft en maakt afbeeldingen.',
    'openrouter.step.1' => 'Log in bij OpenRouter en open ‘Keys’.',
    'openrouter.step.2' => 'Klik op ‘Create key’, stel eventueel een tegoedlimiet in en kopieer hem.',
    'openrouter.step.3' => 'Plak hem hieronder. Hij begint met sk-or-.',

    'unsplash.about' => 'Gratis foto’s van Unsplash.',
    'unsplash.step.1' => 'Log in bij Unsplash Developers en open ‘Your apps’.',
    'unsplash.step.2' => 'Klik op ‘New Application’, accepteer de voorwaarden en geef hem een naam.',
    'unsplash.step.3' => 'Kopieer de ‘Access Key’ (niet de ‘Secret key’) en plak hem hieronder.',
    'unsplash.field.key' => 'Access Key',

    'pexels.about' => 'Gratis foto’s van Pexels.',
    'pexels.step.1' => 'Log in bij Pexels en open de API-pagina.',
    'pexels.step.2' => 'Klik op ‘Your API Key’, vul het korte formulier in en kopieer de sleutel.',
    'pexels.step.3' => 'Plak hem hieronder.',

    'pixabay.about' => 'Gratis foto’s van Pixabay.',
    'pixabay.step.1' => 'Log in bij Pixabay en open de API-documentatie.',
    'pixabay.step.2' => 'Uw sleutel staat onder ‘Parameters’, naast ‘key’. Kopieer hem.',
    'pixabay.step.3' => 'Plak hem hieronder.',

    'openverse.about' => 'Foto’s uit het publieke domein en CC0. Geen sleutel nodig.',

    'shutterstock.about' => 'Betaalde foto’s, gelicentieerd via uw eigen Shutterstock-API-abonnement.',
    'shutterstock.step.1' => 'Log in bij Shutterstock en open ‘My apps’ onder ‘Developers’.',
    'shutterstock.step.2' => 'Klik op ‘Create new app’ (de callbackhost is het adres van deze site) en kopieer de ‘Consumer key’ en het ‘Consumer secret’.',
    'shutterstock.step.3' => 'Plak ze allebei hieronder. Koppel daarna uw account om foto’s te licentiëren.',
    'shutterstock.field.key' => 'Consumer key',
    'shutterstock.field.secret' => 'Consumer secret',
];
