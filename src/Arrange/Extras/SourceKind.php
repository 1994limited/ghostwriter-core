<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras;

/**
 * Where a fact in an extra came from.
 */
enum SourceKind: string
{
    /** The brief the piece started from. */
    case Brief = 'brief';

    /** An answer the colleague gave: to the brief's questions or the writer's. */
    case Answer = 'answer';

    /** A sentence already in the draft. */
    case Draft = 'draft';

    /** One of the existing entries the writer was shown. */
    case Entry = 'entry';

    /** A comment or chat message. */
    case Conversation = 'conversation';

    /** The editor wrote or changed it by hand. */
    case Editor = 'editor';
}
