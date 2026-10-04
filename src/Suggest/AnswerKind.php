<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/** What kind of answer a Fact to check takes. */
enum AnswerKind: string
{
    case Number = 'number';
    case Date = 'date';
    case Money = 'money';
    case Text = 'text';
}
