<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions;

/**
 * The model declined, or a safety filter stopped it with nothing usable
 * written.
 */
class Refused extends ProviderException {}
