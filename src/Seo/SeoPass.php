<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\DraftEditor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The SEO layer's pass over a draft: what the writing pipeline calls.
 * No model in this phase; the parts that call one (links, the search
 * title and description) join afterWriter() later.
 *
 * - **afterWriter()** (①): on the writer's draft, before units and
 *   layouts are made from it, so every layout starts from fixed text.
 *   Headings are fitted to the template and each field's editor
 *   (HeadingFixer); the session's draft is rewritten only when something
 *   changed. SessionLayouts runs it after every writer turn and edit.
 * - **arranged()** (②): on a plan's arranged data, in
 *   SessionLayouts::draftData(), because layouts make headings
 *   (lead-in-to-heading, heading-level). Nothing is stored: a plan is
 *   fixed whenever it is built, for the preview, the cards and "Use this
 *   draft" alike.
 *
 * Both are deterministic and idempotent.
 */
final class SeoPass
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HeadingFixer $fixer = new HeadingFixer,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * ① on the session's draft. Returns what changed, by value (a dotted
     * path: `body`, `page_builder.2.text`); the draft is left alone when
     * nothing did, or when it doesn't parse.
     *
     * @return array<string, list<HeadingChange>>
     */
    public function afterWriter(Session $session, LayoutContext $site): array
    {
        if ($session->draft === null || trim($session->draft) === '') {
            return [];
        }

        try {
            $draft = Draft::parse($session->draft);
        } catch (Throwable) {
            return [];
        }

        [$data, $changes] = $this->headings($draft->data, $site->schema, $site->profile);

        if ($changes !== []) {
            $session->draft = (new DraftEditor)->dump($data);
            $this->log($changes, 'draft');
        }

        return $changes;
    }

    /**
     * ② on a plan's arranged data: the same fix, nothing stored.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function arranged(array $data, LayoutContext $site): array
    {
        return $this->headings($data, $site->schema, $site->profile)[0];
    }

    /**
     * A preview's outline, posted by the addon after a render, recorded on
     * the group's stored profile (RenderProfile::observe(): two renders
     * must agree to change it). Returns the stored profile and whether
     * what bodies are fitted to changed, in which case the addon builds
     * the plan again (②) and renders once more.
     *
     * @return array{0: RenderProfile, 1: bool}
     */
    public function observe(RenderProfiles $profiles, string $key, Outline $outline, string $label = '', ?string $now = null): array
    {
        $stored = $profiles->get($key);
        $seen = RenderProfile::fromOutline($key, $outline, $now ?? gmdate('Y-m-d\TH:i:s\Z'), $label);
        $profile = ($stored ?? RenderProfile::default($key, $label))->observe($seen);
        $profiles->put($profile);
        $changed = $stored === null ? $profile->signature() !== RenderProfile::default()->signature() : $profile->signature() !== $stored->signature();

        if ($changed) {
            $this->logger->info("Ghostwriter: the page template of {$key} prints its main heading from ".$profile->h1->value.'.', ['profile' => $profile->toArray()]);
        }

        return [$profile, $changed];
    }

    /**
     * Every rich-text and markdown value of some draft data fitted to its
     * HeadingPolicy: at the top level by the profile's page `top`; inside
     * a block, one below a heading field the template prints in that
     * block.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, list<HeadingChange>>}
     */
    public function headings(array $data, Schema $schema, ?RenderProfile $profile = null): array
    {
        $changes = [];
        $data = $this->values($data, $schema->fields, null, $profile ?? RenderProfile::default(), '', $changes);

        return [$data, $changes];
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<int, Field>  $fields
     * @param  array<string, list<HeadingChange>>  $changes
     * @return array<mixed>
     */
    private function values(array $values, array $fields, ?string $blockType, RenderProfile $profile, string $at, array &$changes): array
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field->handle, $values)) {
                continue;
            }

            $value = $values[$field->handle];
            $path = ($at === '' ? '' : $at.'.').$field->handle;

            if ($field->isBuilder() && is_array($value)) {
                $i = 0;

                foreach ($value as $key => $block) {
                    if (! is_array($block)) {
                        continue;
                    }

                    $type = is_string($block['type'] ?? null) ? $block['type'] : null;
                    $set = $type === null ? null : $field->set($type);

                    if ($set !== null) {
                        $value[$key] = $this->values($block, $set->fields, $type, $profile, $path.'.'.$i, $changes);
                    }

                    $i++;
                }

                $values[$field->handle] = $value;
            } elseif ($field->kind === Kind::Rows && is_array($value)) {
                foreach ($value as $key => $row) {
                    if (is_array($row)) {
                        $value[$key] = $this->values($row, $field->fields, $blockType, $profile, $path.'.'.$key, $changes);
                    }
                }

                $values[$field->handle] = $value;
            } elseif ($field->kind === Kind::Group && is_array($value)) {
                $values[$field->handle] = $this->values($value, $field->fields, $blockType, $profile, $path, $changes);
            } elseif (HeadingLevels::holdsHeadings($field) && is_string($value)) {
                $fixed = $this->fixer->fix($value, HeadingPolicy::for($field, $profile, $blockType));

                if ($fixed->changed()) {
                    $values[$field->handle] = $fixed->markdown;
                    $changes[$path] = $fixed->changes;
                }
            }
        }

        return $values;
    }

    /**
     * @param  array<string, list<HeadingChange>>  $changes
     */
    private function log(array $changes, string $what): void
    {
        $count = array_sum(array_map('count', $changes));
        $this->logger->info("Ghostwriter: fitted {$count} ".($count === 1 ? 'heading' : 'headings')." in the {$what} to the page template and its editors.", ['changes' => array_map(fn (array $list) => array_map(fn (HeadingChange $change) => $change->toArray(), $list), $changes)]);
    }
}
