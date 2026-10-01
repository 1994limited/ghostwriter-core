<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions;

/**
 * A reply ran out of room and can't be used part-written. Thrown by the
 * caller (Studio), never by a provider.
 */
class Truncated extends ProviderException {}
