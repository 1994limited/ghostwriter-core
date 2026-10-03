<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions;

/**
 * The account behind the key has no credit left for this call, or the key
 * has reached the spending limit set on it (OpenRouter's 402). Trying again
 * won't help until someone adds credit or raises the limit.
 */
class OutOfCredit extends ProviderException {}
