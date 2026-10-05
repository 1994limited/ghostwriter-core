<?php

/*
 * German for Settings → Connections (Connections\Strings). A key that isn't
 * here falls back to English. Parameters are kept as they are (`:service`).
 * Button names on the services' own pages stay as those pages show them.
 */

return [
    'title' => 'Verbindungen',
    'intro' => 'Die Dienste, mit denen Ghostwriter schreibt, Bilder erstellt und Fotos findet. Richten Sie jeden hier ein: Seite öffnen, Schlüssel erstellen, hier einfügen.',
    'privacy' => 'Schlüssel werden verschlüsselt auf dieser Website gespeichert und nur an den Dienst gesendet, zu dem sie gehören. Sie erreichen nie die Server von Ghostwriter.',
    'environment' => 'Sie sind auf :environment.',
    'environment.note' => 'Was Sie hier einrichten, gilt nur für diese Website. Ihre anderen Kopien (lokal, Staging, live) haben jeweils ihre eigenen Schlüssel und können sich daher unterscheiden.',
    'environment.local' => 'lokal',
    'environment.staging' => 'Staging',
    'environment.production' => 'Produktion',
    'nav' => 'Verbindungen',

    'group.writing' => 'Schreiben',
    'group.writing.intro' => 'Das Modell, das schreibt. Eines genügt; welches, wählen Sie in den Einstellungen.',
    'group.images' => 'Bilder',
    'group.images.intro' => 'Kostenlose Fotobibliotheken für die Suche. Bilder werden mit Ihrem OpenAI-, Gemini- oder OpenRouter-Schlüssel aus „Schreiben“ erstellt.',
    'group.stock' => 'Stockfotos',
    'group.stock.intro' => 'Kostenpflichtige Bibliotheken, lizenziert über Ihr eigenes Konto dort.',
    'group.test' => 'Testdienste',
    'group.test.intro' => 'Nur die End-to-End-Tests sehen diese, auf einer lokalen Website.',

    'status.connected' => 'Verbunden · Schlüssel endet auf :ending',
    'status.not-set' => 'Nicht eingerichtet',
    'status.env' => 'In .env gesetzt',
    'status.config' => 'In der Konfiguration gesetzt',
    'status.broken' => 'Schlüssel funktioniert nicht mehr',
    'status.no-key' => 'Kein Schlüssel nötig',
    'status.env.help' => 'Diese Website verwendet :variable aus ihrer .env-Datei, die Vorrang vor allem hat, was hier eingerichtet ist. Ändern Sie es dort.',
    'status.config.help' => 'Diese Website setzt den Schlüssel in ihrer Konfiguration, die Vorrang vor allem hat, was hier eingerichtet ist. Ändern Sie ihn dort.',
    'status.broken.help' => ':service akzeptiert diesen Schlüssel nicht mehr. Erstellen Sie einen neuen und ersetzen Sie ihn.',
    'status.env.broken' => ':service akzeptiert den Schlüssel in .env nicht mehr. Ändern Sie :variable dort.',
    'status.no-key.help' => 'Schalten Sie ihn in den Einstellungen ein oder aus.',
    'status.via-connect' => 'Verbunden durch Anmelden bei :service.',
    'makes-images' => 'Erstellt auch Bilder',

    'action.setup' => 'Einrichten',
    'action.replace' => 'Schlüssel ersetzen',
    'action.disconnect' => 'Trennen',
    'action.open' => ':service öffnen',
    'action.check' => 'Prüfen und speichern',
    'action.checking' => 'Wird geprüft…',
    'action.cancel' => 'Abbrechen',

    'panel.title' => ':service einrichten',
    'panel.replace' => 'Schlüssel für :service ersetzen',
    'panel.opens' => 'Öffnet sich in einem neuen Tab.',
    'panel.paste' => 'Hier einfügen',
    'panel.private' => 'Verschlüsselt auf dieser Website gespeichert. Ghostwriter zeigt ihn nie wieder, nur seine letzten vier Zeichen.',
    'field.key' => 'API-Schlüssel',
    'field.secret' => 'Geheimnis',
    'field.token' => 'Zugriffstoken',

    'disconnect.title' => ':service trennen?',
    'disconnect.body' => 'Ghostwriter vergisst diesen Schlüssel und verwendet :service nicht mehr, bis er wieder eingerichtet ist. Bei :service funktioniert der Schlüssel weiter; löschen Sie ihn dort, wenn Sie ihn nicht mehr brauchen.',

    'saved' => ':service ist verbunden.',
    'disconnected' => ':service ist getrennt.',

    'check.missing' => 'Fügen Sie zuerst den Wert für :field ein.',
    'check.refused' => ':service hat diesen Schlüssel nicht akzeptiert. Prüfen Sie, ob Sie ihn vollständig kopiert haben, ohne etwas davor oder danach.',
    'check.unreachable' => ':service ist gerade nicht erreichbar. Prüfen Sie, ob diese Website das Internet erreicht, und versuchen Sie es erneut.',
    'check.busy' => ':service ist ausgelastet oder begrenzt Anfragen. Versuchen Sie es in einer Minute erneut.',
    'check.failed' => ':service konnte den Schlüssel nicht prüfen: :reason',
    'env-wins' => ':service ist in .env gesetzt (:variable), was Vorrang hat. Ändern Sie es dort, oder entfernen Sie es aus .env, um es hier einzurichten.',
    'unknown' => 'Ghostwriter kennt keinen Dienst namens „:service“.',
    'forbidden' => 'Nur wer die Einstellungen von Ghostwriter ändern darf, kann Verbindungen einrichten.',

    'oauth.key' => 'Oder melden Sie sich bei :service an und lassen Sie dort einen Schlüssel für Sie erstellen.',
    'oauth.key.button' => 'Mit :service verbinden',
    'oauth.account' => 'Zum Lizenzieren muss außerdem Ihr :service-Konto verbunden sein.',
    'oauth.account.connect' => 'Konto verbinden',
    'oauth.account.disconnect' => 'Konto trennen',
    'oauth.account.connected' => 'Konto verbunden',
    'oauth.account.not-connected' => 'Konto nicht verbunden',
    'oauth.account.needs-key' => 'Richten Sie zuerst Schlüssel und Geheimnis ein, dann verbinden Sie Ihr Konto.',
    'oauth.account.callback' => 'Tragen Sie in den Einstellungen Ihrer App bei :service diesen Callback ein:',

    'elsewhere.link' => 'In Verbindungen einrichten',
    'elsewhere.hint' => 'In Verbindungen einrichten (oder :variable in .env setzen).',
    'elsewhere.keys' => 'Schlüssel werden in Verbindungen eingerichtet oder in Ihrer .env-Datei, die Vorrang hat.',

    'anthropic.about' => 'Claude von Anthropic. Schreibt Ihre Entwürfe.',
    'anthropic.step.1' => 'Melden Sie sich in der Claude Console an und öffnen Sie „API keys“.',
    'anthropic.step.2' => 'Klicken Sie auf „Create key“, benennen Sie ihn nach dieser Website und kopieren Sie ihn.',
    'anthropic.step.3' => 'Fügen Sie ihn unten ein. Er beginnt mit sk-ant-.',

    'openai.about' => 'Die Modelle hinter ChatGPT, von OpenAI. Schreibt und erstellt Bilder.',
    'openai.step.1' => 'Melden Sie sich bei der OpenAI Platform an und öffnen Sie „API keys“.',
    'openai.step.2' => 'Klicken Sie auf „Create new secret key“, benennen Sie ihn nach dieser Website und kopieren Sie ihn.',
    'openai.step.3' => 'Fügen Sie ihn unten ein. Er beginnt mit sk-.',

    'gemini.about' => 'Gemini von Google. Schreibt und erstellt Bilder.',
    'gemini.step.1' => 'Melden Sie sich mit Ihrem Google-Konto bei Google AI Studio an.',
    'gemini.step.2' => 'Klicken Sie auf „Create API key“, wählen Sie ein Projekt und kopieren Sie den Schlüssel.',
    'gemini.step.3' => 'Fügen Sie ihn unten ein. Er beginnt mit AIza.',

    'openrouter.about' => 'Claude, GPT, Gemini und andere über ein Konto, bezahlt mit OpenRouter-Guthaben. Schreibt und erstellt Bilder.',
    'openrouter.step.1' => 'Melden Sie sich bei OpenRouter an und öffnen Sie „Keys“.',
    'openrouter.step.2' => 'Klicken Sie auf „Create key“, setzen Sie bei Bedarf ein Guthabenlimit und kopieren Sie ihn.',
    'openrouter.step.3' => 'Fügen Sie ihn unten ein. Er beginnt mit sk-or-.',

    'unsplash.about' => 'Kostenlose Fotos von Unsplash.',
    'unsplash.step.1' => 'Melden Sie sich bei Unsplash Developers an und öffnen Sie „Your apps“.',
    'unsplash.step.2' => 'Klicken Sie auf „New Application“, akzeptieren Sie die Bedingungen und geben Sie ihr einen Namen.',
    'unsplash.step.3' => 'Kopieren Sie den „Access Key“ (nicht den „Secret key“) und fügen Sie ihn unten ein.',
    'unsplash.field.key' => 'Access Key',

    'pexels.about' => 'Kostenlose Fotos von Pexels.',
    'pexels.step.1' => 'Melden Sie sich bei Pexels an und öffnen Sie die API-Seite.',
    'pexels.step.2' => 'Klicken Sie auf „Your API Key“, füllen Sie das kurze Formular aus und kopieren Sie den Schlüssel.',
    'pexels.step.3' => 'Fügen Sie ihn unten ein.',

    'pixabay.about' => 'Kostenlose Fotos von Pixabay.',
    'pixabay.step.1' => 'Melden Sie sich bei Pixabay an und öffnen Sie die API-Dokumentation.',
    'pixabay.step.2' => 'Ihr Schlüssel steht unter „Parameters“ neben „key“. Kopieren Sie ihn.',
    'pixabay.step.3' => 'Fügen Sie ihn unten ein.',

    'openverse.about' => 'Gemeinfreie und CC0-Fotos. Braucht keinen Schlüssel.',

    'shutterstock.about' => 'Kostenpflichtige Fotos, lizenziert über Ihr eigenes Shutterstock-API-Abonnement.',
    'shutterstock.step.1' => 'Melden Sie sich bei Shutterstock an und öffnen Sie „My apps“ unter „Developers“.',
    'shutterstock.step.2' => 'Klicken Sie auf „Create new app“ (der Callback-Host ist die Adresse dieser Website) und kopieren Sie „Consumer key“ und „Consumer secret“.',
    'shutterstock.step.3' => 'Fügen Sie beide unten ein. Um Fotos zu lizenzieren, verbinden Sie danach Ihr Konto.',
    'shutterstock.field.key' => 'Consumer key',
    'shutterstock.field.secret' => 'Consumer secret',
];
