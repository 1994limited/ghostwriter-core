<?php

/*
 * Spanish for Settings → Connections (Connections\Strings). A key that isn't
 * here falls back to English. Parameters are kept as they are (`:service`).
 * Button names on the services' own pages stay as those pages show them.
 */

return [
    'title' => 'Conexiones',
    'intro' => 'Los servicios con los que Ghostwriter escribe, crea imágenes y encuentra fotos. Configure cada uno aquí: abra su página, cree una clave y péguela.',
    'privacy' => 'Las claves se guardan cifradas en este sitio y solo se envían al servicio al que pertenecen. Nunca llegan a los servidores de Ghostwriter.',
    'environment' => 'Está en :environment.',
    'environment.note' => 'Lo que configure aquí es solo para este sitio. Sus otras copias (local, staging, en producción) guardan cada una las suyas, así que pueden ser distintas.',
    'environment.local' => 'local',
    'environment.staging' => 'staging',
    'environment.production' => 'producción',
    'nav' => 'Conexiones',

    'group.writing' => 'Escritura',
    'group.writing.intro' => 'El modelo que escribe. Basta con uno; elija cuál en los ajustes.',
    'group.images' => 'Imágenes',
    'group.images.intro' => 'Bancos de fotos gratuitos donde buscar. Las imágenes se crean con su clave de OpenAI, Gemini u OpenRouter de Escritura.',
    'group.stock' => 'Fotos de stock',
    'group.stock.intro' => 'Bancos de pago, con licencia desde su propia cuenta en ellos.',
    'group.test' => 'Servicios de prueba',
    'group.test.intro' => 'Solo los ven las pruebas de extremo a extremo, en un sitio local.',

    'status.connected' => 'Conectado · la clave termina en :ending',
    'status.not-set' => 'Sin configurar',
    'status.env' => 'Definido en .env',
    'status.config' => 'Definido en la configuración',
    'status.broken' => 'La clave ha dejado de funcionar',
    'status.no-key' => 'No necesita clave',
    'status.env.help' => 'Este sitio usa :variable de su archivo .env, que tiene prioridad sobre lo que se configure aquí. Para cambiarlo, cámbielo allí.',
    'status.config.help' => 'Este sitio define la clave en su configuración, que tiene prioridad sobre lo que se configure aquí. Para cambiarla, cámbiela allí.',
    'status.broken.help' => ':service ha dejado de aceptar esta clave. Cree una nueva y reemplácela.',
    'status.env.broken' => ':service ha dejado de aceptar la clave de .env. Cambie :variable allí.',
    'status.no-key.help' => 'Actívelo o desactívelo en los ajustes.',
    'status.via-connect' => 'Conectado iniciando sesión en :service.',
    'makes-images' => 'También crea imágenes',

    'action.setup' => 'Configurar',
    'action.replace' => 'Reemplazar clave',
    'action.disconnect' => 'Desconectar',
    'action.open' => 'Abrir :service',
    'action.check' => 'Comprobar y guardar',
    'action.checking' => 'Comprobando…',
    'action.cancel' => 'Cancelar',

    'panel.title' => 'Configurar :service',
    'panel.replace' => 'Reemplazar la clave de :service',
    'panel.opens' => 'Se abre en una pestaña nueva.',
    'panel.paste' => 'Péguela aquí',
    'panel.private' => 'Se guarda cifrada en este sitio. Ghostwriter no vuelve a mostrarla, solo sus cuatro últimos caracteres.',
    'field.key' => 'Clave de API',
    'field.secret' => 'Secreto',
    'field.token' => 'Token de acceso',

    'disconnect.title' => '¿Desconectar :service?',
    'disconnect.body' => 'Ghostwriter olvida esta clave y deja de usar :service hasta que se vuelva a configurar. La clave sigue funcionando en :service; bórrela allí si ya no la necesita.',

    'saved' => ':service está conectado.',
    'disconnected' => ':service está desconectado.',

    'check.missing' => 'Pegue primero el valor de :field.',
    'check.refused' => ':service no ha aceptado esa clave. Compruebe que la ha copiado entera, sin nada antes ni después.',
    'check.unreachable' => 'No se ha podido contactar con :service ahora. Compruebe que este sitio tiene acceso a internet y vuelva a intentarlo.',
    'check.busy' => ':service está ocupado o limitando las peticiones. Vuelva a intentarlo dentro de un minuto.',
    'check.failed' => ':service no ha podido comprobar la clave: :reason',
    'env-wins' => ':service está definido en .env (:variable), que tiene prioridad. Cámbielo allí, o quítelo de .env para configurarlo aquí.',
    'unknown' => 'Ghostwriter no conoce ningún servicio llamado «:service».',
    'forbidden' => 'Solo quien puede cambiar los ajustes de Ghostwriter puede configurar conexiones.',

    'oauth.key' => 'O inicie sesión en :service y deje que cree una clave por usted.',
    'oauth.key.button' => 'Conectar con :service',
    'oauth.account' => 'Para comprar licencias también hay que conectar su cuenta de :service.',
    'oauth.account.connect' => 'Conectar cuenta',
    'oauth.account.disconnect' => 'Desconectar cuenta',
    'oauth.account.connected' => 'Cuenta conectada',
    'oauth.account.not-connected' => 'Cuenta sin conectar',
    'oauth.account.needs-key' => 'Configure primero la clave y el secreto, y después conecte su cuenta.',
    'oauth.account.callback' => 'En los ajustes de su aplicación en :service, añada esta dirección de retorno:',

    'elsewhere.link' => 'Configurar en Conexiones',
    'elsewhere.hint' => 'Configurar en Conexiones (o definir :variable en .env).',
    'elsewhere.keys' => 'Las claves se configuran en Conexiones, o en su archivo .env, que tiene prioridad.',

    'anthropic.about' => 'Claude, de Anthropic. Escribe sus borradores.',
    'anthropic.step.1' => 'Inicie sesión en Claude Console y abra «API keys».',
    'anthropic.step.2' => 'Haga clic en «Create key», póngale el nombre de este sitio y cópiela.',
    'anthropic.step.3' => 'Péguela abajo. Empieza por sk-ant-.',

    'openai.about' => 'Los modelos de ChatGPT, de OpenAI. Escribe y crea imágenes.',
    'openai.step.1' => 'Inicie sesión en OpenAI Platform y abra «API keys».',
    'openai.step.2' => 'Haga clic en «Create new secret key», póngale el nombre de este sitio y cópiela.',
    'openai.step.3' => 'Péguela abajo. Empieza por sk-.',

    'gemini.about' => 'Gemini, de Google. Escribe y crea imágenes.',
    'gemini.step.1' => 'Inicie sesión en Google AI Studio con su cuenta de Google.',
    'gemini.step.2' => 'Haga clic en «Create API key», elija un proyecto y copie la clave.',
    'gemini.step.3' => 'Péguela abajo. Empieza por AIza.',

    'openrouter.about' => 'Claude, GPT, Gemini y otros con una sola cuenta, pagados con crédito de OpenRouter. Escribe y crea imágenes.',
    'openrouter.step.1' => 'Inicie sesión en OpenRouter y abra «Keys».',
    'openrouter.step.2' => 'Haga clic en «Create key», ponga un límite de crédito si quiere y cópiela.',
    'openrouter.step.3' => 'Péguela abajo. Empieza por sk-or-.',

    'unsplash.about' => 'Fotos gratuitas de Unsplash.',
    'unsplash.step.1' => 'Inicie sesión en Unsplash Developers y abra «Your apps».',
    'unsplash.step.2' => 'Haga clic en «New Application», acepte las condiciones y póngale un nombre.',
    'unsplash.step.3' => 'Copie la «Access Key» (no la «Secret key») y péguela abajo.',
    'unsplash.field.key' => 'Access Key',

    'pexels.about' => 'Fotos gratuitas de Pexels.',
    'pexels.step.1' => 'Inicie sesión en Pexels y abra su página de API.',
    'pexels.step.2' => 'Haga clic en «Your API Key», rellene el breve formulario y copie la clave.',
    'pexels.step.3' => 'Péguela abajo.',

    'pixabay.about' => 'Fotos gratuitas de Pixabay.',
    'pixabay.step.1' => 'Inicie sesión en Pixabay y abra su documentación de la API.',
    'pixabay.step.2' => 'Su clave aparece en «Parameters», junto a «key». Cópiela.',
    'pixabay.step.3' => 'Péguela abajo.',

    'openverse.about' => 'Fotos de dominio público y CC0. No necesita clave.',

    'shutterstock.about' => 'Fotos de pago, con licencia desde su propia suscripción a la API de Shutterstock.',
    'shutterstock.step.1' => 'Inicie sesión en Shutterstock y abra «My apps», en «Developers».',
    'shutterstock.step.2' => 'Haga clic en «Create new app» (su host de retorno es la dirección de este sitio) y copie su «Consumer key» y su «Consumer secret».',
    'shutterstock.step.3' => 'Pegue ambos abajo. Para comprar licencias, conecte después su cuenta.',
    'shutterstock.field.key' => 'Consumer key',
    'shutterstock.field.secret' => 'Consumer secret',
];
