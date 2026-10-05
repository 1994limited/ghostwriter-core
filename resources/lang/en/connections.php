<?php

/*
 * The English source strings for Settings → Connections, by key without a
 * prefix (Connections\Strings). The addons read them at run time, with a
 * language's own over these. Parameters are Laravel-style: `:service`.
 *
 * Each service has `<id>.about` and its steps, `<id>.step.1`…; a field
 * label of its own is `<id>.field.<name>`, else `field.<name>`.
 */

return [
    // The page.
    'title' => 'Connections',
    'intro' => 'The services Ghostwriter writes, makes images and finds photos with. Set each one up here: open its page, make a key, paste it in.',
    'privacy' => 'Keys are kept encrypted on this site and only ever sent to the service they belong to. They never reach Ghostwriter’s servers.',
    'environment' => 'You’re on :environment.',
    'environment.note' => 'What you set up here is for this site only. Your other copies of it (local, staging, live) each keep their own, so they may differ.',
    'environment.local' => 'local',
    'environment.staging' => 'staging',
    'environment.production' => 'production',
    'nav' => 'Connections',

    // Groups.
    'group.writing' => 'Writing',
    'group.writing.intro' => 'The model that writes. One is enough; choose which in Settings.',
    'group.images' => 'Images',
    'group.images.intro' => 'Free photo libraries to search. Pictures are made with your OpenAI, Gemini or OpenRouter key from Writing.',
    'group.stock' => 'Stock photos',
    'group.stock.intro' => 'Paid libraries, licensed from your own account with them.',
    'group.test' => 'Test services',
    'group.test.intro' => 'Only the end-to-end tests see these, on a local site.',

    // What a card says.
    'status.connected' => 'Connected · key ending :ending',
    'status.not-set' => 'Not set up',
    'status.env' => 'Set in .env',
    'status.config' => 'Set in config',
    'status.broken' => 'Key stopped working',
    'status.no-key' => 'No key needed',
    'status.env.help' => 'This site uses :variable from its .env file, which wins over anything set up here. To change it, change it there.',
    'status.config.help' => 'This site sets the key in its config, which wins over anything set up here. To change it, change it there.',
    'status.broken.help' => ':service stopped accepting this key. Make a new one and replace it.',
    'status.env.broken' => ':service stopped accepting the key in .env. Change :variable there.',
    'status.no-key.help' => 'Switch it on or off in Settings.',
    'status.via-connect' => 'Connected by signing in to :service.',
    'makes-images' => 'Also makes images',

    // Buttons.
    'action.setup' => 'Set up',
    'action.replace' => 'Replace key',
    'action.disconnect' => 'Disconnect',
    'action.open' => 'Open :service',
    'action.check' => 'Check & save',
    'action.checking' => 'Checking…',
    'action.cancel' => 'Cancel',

    // The set-up panel.
    'panel.title' => 'Set up :service',
    'panel.replace' => 'Replace the key for :service',
    'panel.opens' => 'Opens in a new tab.',
    'panel.paste' => 'Paste it here',
    'panel.private' => 'Kept encrypted on this site. Ghostwriter never shows it again, only its last four characters.',
    'field.key' => 'API key',
    'field.secret' => 'Secret',
    'field.token' => 'Access token',

    // Disconnect.
    'disconnect.title' => 'Disconnect :service?',
    'disconnect.body' => 'Ghostwriter forgets this key and stops using :service until it’s set up again. The key still works at :service, so delete it there if you no longer need it.',

    // After saving or disconnecting.
    'saved' => ':service is connected.',
    'disconnected' => ':service is disconnected.',

    // Why something couldn't be done.
    'check.missing' => 'Paste the :field first.',
    'check.refused' => ':service didn’t accept that key. Check you copied all of it, with nothing before or after.',
    'check.unreachable' => 'Couldn’t reach :service just now. Check this site can reach the internet, then try again.',
    'check.busy' => ':service is busy or limiting requests. Try again in a minute.',
    'check.failed' => ':service couldn’t check the key: :reason',
    'env-wins' => ':service is set in .env (:variable), which wins. Change it there, or take it out of .env to set it up here.',
    'unknown' => 'Ghostwriter doesn’t know a service called “:service”.',
    'forbidden' => 'Only someone who can change Ghostwriter’s settings can set up connections.',

    // Signing in, inside a card.
    'oauth.key' => 'Or sign in to :service and let it make a key for you.',
    'oauth.key.button' => 'Connect with :service',
    'oauth.account' => 'Licensing also needs your :service account connected.',
    'oauth.account.connect' => 'Connect account',
    'oauth.account.disconnect' => 'Disconnect account',
    'oauth.account.connected' => 'Account connected',
    'oauth.account.not-connected' => 'Account not connected',
    'oauth.account.needs-key' => 'Set up the key and secret first, then connect your account.',
    'oauth.account.callback' => 'In your app’s settings at :service, add this callback:',

    // Elsewhere: Settings and Get started.
    'elsewhere.link' => 'Set up in Connections',
    'elsewhere.hint' => 'Set up in Connections (or set :variable in .env).',
    'elsewhere.keys' => 'Keys are set up in Connections, or in your .env file, which wins.',

    // The services.
    'anthropic.about' => 'Claude, from Anthropic. Writes your drafts.',
    'anthropic.step.1' => 'Sign in to the Claude Console and open API keys.',
    'anthropic.step.2' => 'Click Create key, name it after this site, and copy it.',
    'anthropic.step.3' => 'Paste it below. It starts with sk-ant-.',

    'openai.about' => 'ChatGPT’s models, from OpenAI. Writes, and makes images.',
    'openai.step.1' => 'Sign in to the OpenAI Platform and open API keys.',
    'openai.step.2' => 'Click Create new secret key, name it after this site, and copy it.',
    'openai.step.3' => 'Paste it below. It starts with sk-.',

    'gemini.about' => 'Google’s Gemini. Writes, and makes images.',
    'gemini.step.1' => 'Sign in to Google AI Studio with your Google account.',
    'gemini.step.2' => 'Click Create API key, choose a project, and copy the key.',
    'gemini.step.3' => 'Paste it below. It starts with AIza.',

    'openrouter.about' => 'Claude, GPT, Gemini and others through one account, paid for with OpenRouter credit. Writes, and makes images.',
    'openrouter.step.1' => 'Sign in to OpenRouter and open Keys.',
    'openrouter.step.2' => 'Click Create key, set a credit limit if you like, and copy it.',
    'openrouter.step.3' => 'Paste it below. It starts with sk-or-.',

    'unsplash.about' => 'Free photographs from Unsplash.',
    'unsplash.step.1' => 'Sign in to Unsplash Developers and open Your apps.',
    'unsplash.step.2' => 'Click New Application, accept the terms and give it a name.',
    'unsplash.step.3' => 'Copy the Access Key (not the Secret key) and paste it below.',
    'unsplash.field.key' => 'Access key',

    'pexels.about' => 'Free photographs from Pexels.',
    'pexels.step.1' => 'Sign in to Pexels and open its API page.',
    'pexels.step.2' => 'Click Your API Key, fill in the short form, and copy the key.',
    'pexels.step.3' => 'Paste it below.',

    'pixabay.about' => 'Free photographs from Pixabay.',
    'pixabay.step.1' => 'Sign in to Pixabay and open its API documentation.',
    'pixabay.step.2' => 'Your key is shown under Parameters, beside “key”. Copy it.',
    'pixabay.step.3' => 'Paste it below.',

    'openverse.about' => 'Public-domain and CC0 photographs. Needs no key.',

    'shutterstock.about' => 'Paid photographs, licensed from your own Shutterstock API subscription.',
    'shutterstock.step.1' => 'Sign in to Shutterstock and open My apps, under Developers.',
    'shutterstock.step.2' => 'Click Create new app (its callback host is this site’s address), then copy its Consumer key and Consumer secret.',
    'shutterstock.step.3' => 'Paste both below. To license photos, connect your account afterwards.',
    'shutterstock.field.key' => 'Consumer key',
    'shutterstock.field.secret' => 'Consumer secret',

    'e2e-paste.about' => 'Stands in for a service in the end-to-end tests.',
    'e2e-paste.step.1' => 'Paste any key below. One with “wrong” in it is refused.',
    'e2e-env.about' => 'Stands in for a service set in .env, in the end-to-end tests.',
    'e2e-env.step.1' => 'Set GHOSTWRITER_E2E_ENV_KEY in .env.',
];
