<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

/**
 * How hard a model should think before answering, for models that take it.
 */
enum Effort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
