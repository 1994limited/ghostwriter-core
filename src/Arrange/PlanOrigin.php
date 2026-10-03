<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

/** Where a plan came from. */
enum PlanOrigin: string
{
    /** The writer's own draft: plan "w". */
    case Writer = 'writer';

    /** Built from one of the site's own patterns, with no model. Reserved. */
    case Pattern = 'pattern';

    /** Proposed by the layout planner. */
    case Model = 'model';
}
