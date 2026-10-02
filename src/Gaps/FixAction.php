<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * What a fix does when an editor presses it. The front end carries it out;
 * nothing here writes or saves.
 */
enum FixAction: string
{
    /** Type the fact into the inline answer box. Never a model's value. */
    case Answer = 'answer';

    /** Point the link at the target in Fix::$value (or, with none, an address the editor types). */
    case Link = 'link';

    case ChooseEntry = 'choose-entry';
    case RemoveLink = 'remove-link';
    case FindPhoto = 'find-photo';
    case ChooseAsset = 'choose-asset';
    case LeaveEmpty = 'leave-empty';

    /** Opens the stock feature's License & replace confirm; never licenses by itself. */
    case License = 'license';

    case RequestLicence = 'request-licence';
    case RefreshPreview = 'refresh-preview';
    case ChooseAnother = 'choose-another';

    /** A model writes prose from the page's own content (Studio::fillGap()). */
    case WriteForMe = 'write-for-me';

    /** A model rewrites the sentence without the missing fact, adding nothing. */
    case WriteAround = 'write-around';

    case Shorten = 'shorten';

    /** Move focus to the field and select the gap's text. */
    case Focus = 'focus';

    /** Remove the text (a leftover placeholder). */
    case Remove = 'remove';

    /** "It's fine": hidden for this entry, in this view. */
    case Dismiss = 'dismiss';
}
