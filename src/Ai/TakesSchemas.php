<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

/**
 * A text provider that can hold a model to a request's OutputSchema. Studio
 * asks before writing the instructions, so a prompt only tells the model to
 * wrap its JSON in tags when nothing else holds it to the shape.
 */
interface TakesSchemas
{
    /** Whether this request's schema would be sent to the model (as a JSON output mode or a tool), not only described in its prompt. */
    public function takesSchema(TextRequest $request): bool;
}
