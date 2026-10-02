<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions;

use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;

/**
 * Where a piece has got to, and whether it is finished.
 *
 * E6: a piece counts as finished only once its record is saved. A draft put
 * into a form and never saved is still in progress, and can be resumed.
 * For a piece editing an existing record, Statamic waits for the record to
 * be saved after the changes went into its form; Craft and Filament count
 * it finished once the changes are put in (DomainOptions::$editFinishedOnApply).
 *
 * Stages: `failed`, `working`, `editing`, `changed` (an edit put into the
 * form), `published`, `saved`, `in_form` (a new piece put into the form,
 * not saved), `draft` and `interview` (no draft yet). An addon may name
 * them its own way (DomainOptions::$stageNames).
 */
final class Progress
{
    public const STAGES = ['failed', 'working', 'editing', 'changed', 'published', 'saved', 'in_form', 'draft', 'interview'];

    public function __construct(
        public readonly string $stage,
        public readonly bool $finished,
    ) {}

    public static function of(Session $session, Record $record, DomainOptions $options): self
    {
        $stage = self::stage($session, $record, $options);

        return new self($options->stageNames[$stage] ?? $stage, self::finished($session, $record, $options));
    }

    public static function finished(Session $session, Record $record, DomainOptions $options): bool
    {
        if ($session->isWorking() && ! $options->recordFirst) {
            return false;
        }

        if ($session->isEditing()) {
            if ($options->editFinishedOnApply) {
                return $session->appliedAt !== null;
            }

            $applied = Format::parse($session->appliedAt);

            return $record->saved && $applied !== null && $record->savedAt !== null && $record->savedAt->getTimestamp() >= $applied->getTimestamp();
        }

        return $record->exists && $record->saved;
    }

    private static function stage(Session $session, Record $record, DomainOptions $options): string
    {
        $saved = ! $session->isEditing() && $record->exists && $record->saved;

        if ($options->recordFirst && $saved) {
            return $record->published ? 'published' : 'saved';
        }

        return match (true) {
            $session->hasFailed() => 'failed',
            $session->isWorking() => 'working',
            $session->isEditing() => match (true) {
                ! $options->editFinishedOnApply && self::finished($session, $record, $options) => $record->published ? 'published' : 'saved',
                $session->appliedAt !== null => 'changed',
                default => 'editing',
            },
            $saved => $record->published ? 'published' : 'saved',
            $session->appliedAt !== null => 'in_form',
            $session->draft === null => 'interview',
            $options->questionsMeanAsking && (bool) ($session->lastMessage()['asks'] ?? false) && ($session->lastMessage()['role'] ?? null) === 'assistant' => 'interview',
            default => 'draft',
        };
    }
}
