<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions;

/**
 * Connecting a provider account ("Connect with OpenRouter") did not work:
 * the sign-in was refused or has expired, the callback address isn't one
 * the provider accepts, or the site uses a key from its .env file instead.
 * The message is written to be shown on the settings screen, and never
 * holds a code, a verifier or a key.
 */
class ConnectFailed extends ProviderException {}
