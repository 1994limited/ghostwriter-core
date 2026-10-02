<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

/**
 * What happened when the addon tried to save an entry, for
 * PublishGuardContract: whether it saved, the field errors it showed (by
 * field key, as the CMS keys them) and any warning it showed.
 */
final class GuardOutcome
{
    /**
     * @param  array<string, string|list<string>>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly bool $saved,
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {}

    /** Every error message, flattened. */
    public function errorText(): string
    {
        $all = [];

        foreach ($this->errors as $messages) {
            array_push($all, ...(array) $messages);
        }

        return implode("\n", $all);
    }

    /** Whether some error is keyed to this field (or something inside it). */
    public function hasErrorOn(string $field): bool
    {
        foreach (array_keys($this->errors) as $key) {
            if ($key === $field || str_starts_with($key, $field.'.') || str_starts_with($key, $field.'/')) {
                return true;
            }
        }

        return false;
    }
}
