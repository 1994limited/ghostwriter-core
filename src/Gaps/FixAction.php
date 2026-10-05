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

    /**
     * "Looks right": replace a count to check with Fix::$value, the count
     * as it stands or, when its list has changed, the new count
     * (Markers::resolveCheck()).
     */
    case Confirm = 'confirm';

    /** "Change it": an editable value, prefilled with Fix::$value, that replaces the count to check. */
    case Change = 'change';

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

    /**
     * "Use this": put the text in Fix::$value into the field (an SEO
     * description the draft already has), in the shape the field keeps it.
     */
    case UseText = 'use-text';

    /** Move focus to the field and select the gap's text. */
    case Focus = 'focus';

    /** Remove the text (a leftover placeholder, a count to check). */
    case Remove = 'remove';

    /** "It's fine": hidden for this entry, in this view. */
    case Dismiss = 'dismiss';
}
