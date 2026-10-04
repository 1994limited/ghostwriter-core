<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/** What a finding needs before it can be fixed. */
enum Needs: string
{
    /** A free fix exists: a link candidate, or nothing to write. */
    case Nothing = 'nothing';

    /** Words: the review call writes a replacement. */
    case Words = 'words';

    /** A fact only the editor knows: the call writes the template around it, never the answer. */
    case Editor = 'editor';
}
