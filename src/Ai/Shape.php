<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

/**
 * The shape of an image to be made.
 */
enum Shape: string
{
    case Landscape = 'landscape';
    case Portrait = 'portrait';
    case Square = 'square';
}
