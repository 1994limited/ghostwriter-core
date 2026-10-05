<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Connections\CheckResult;
use NineteenNinetyFour\Ghostwriter\Core\Connections\ChecksKeys;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Service;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Strings;
use SensitiveParameter;

/**
 * Checks keys without calling anybody, for tests and for the end-to-end
 * tests' fake scenarios (the addons use it only while one is playing on a
 * local site). Every key works, except one with "wrong" in it, which is
 * refused as the service would, and "offline", which can't be reached.
 */
final class FakeKeyCheck implements ChecksKeys
{
    /** @var array<int, string> Each service checked, in order. */
    public array $checked = [];

    public function check(Service $service, #[SensitiveParameter] array $fields): CheckResult
    {
        $this->checked[] = $service->id;

        foreach ($service->required() as $field) {
            if (trim((string) ($fields[$field->name] ?? '')) === '') {
                return CheckResult::fails('check.missing', ['field' => Strings::english()->get('field.'.$field->name)]);
            }
        }

        $all = strtolower(implode(' ', array_map('strval', $fields)));

        return match (true) {
            str_contains($all, 'wrong') => CheckResult::fails('check.refused', ['service' => $service->name]),
            str_contains($all, 'offline') => CheckResult::fails('check.unreachable', ['service' => $service->name]),
            default => CheckResult::works(),
        };
    }
}
