<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * What pressing a fix costs, so the button can say so: nothing, one small
 * model call ("uses Ghostwriter"), or a licence.
 */
enum FixCost: string
{
    case Free = 'free';
    case Model = 'model';
    case Licence = 'licence';
}
