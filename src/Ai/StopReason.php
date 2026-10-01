<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

/**
 * Why a model stopped writing, the same for every provider.
 */
enum StopReason: string
{
    /** It finished normally. */
    case End = 'end';

    /** It ran out of room: the reply was cut off. */
    case MaxTokens = 'max_tokens';

    /** The model declined. */
    case Refusal = 'refusal';

    /** A provider safety filter stopped it. */
    case Safety = 'safety';

    case Other = 'other';
}
