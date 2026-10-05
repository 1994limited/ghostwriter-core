<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

use SensitiveParameter;

/**
 * Checks pasted fields with the service before they are kept ("Check &
 * save"): KeyCheck makes one cheap live call; Testing\FakeKeyCheck stands in
 * for the end-to-end tests.
 */
interface ChecksKeys
{
    /**
     * @param  array<string, string|null>  $fields  By field name.
     */
    public function check(Service $service, #[SensitiveParameter] array $fields): CheckResult;
}
