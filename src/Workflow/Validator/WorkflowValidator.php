<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Validator;

use ElevateDxp\Workflow\Model\WorkflowDefinition;

/**
 * Validates a workflow definition: well-formed name and subject, non-empty unique places, valid
 * initial place, transitions referencing known places, sane colours. Returns user-facing messages.
 */
final class WorkflowValidator
{
    public const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    /** @return list<string> list of error messages (empty = valid) */
    public function validate(WorkflowDefinition $def): array
    {
        $errors = [];
        if ($def->name === '') {
            $errors[] = 'name is required';
        } elseif (preg_match(self::NAME_PATTERN, $def->name) !== 1) {
            $errors[] = "name '{$def->name}' must start with a letter and contain only letters, digits and underscores (max 64)";
        }
        if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $def->subject) !== 1) {
            $errors[] = "subject '{$def->subject}' is not a valid class name";
        }
        if ($def->places === []) {
            $errors[] = 'at least one place is required';
        }
        foreach (array_count_values($def->places) as $place => $count) {
            if ((string) $place === '') {
                $errors[] = 'place names must not be empty';
            } elseif ($count > 1) {
                $errors[] = "place '$place' is declared more than once";
            }
        }
        if ($def->initialPlace !== '' && !\in_array($def->initialPlace, $def->places, true)) {
            $errors[] = "initial_place '{$def->initialPlace}' is not in places";
        }
        if ($def->transitions === []) {
            $errors[] = 'at least one transition is required';
        }
        foreach ($def->transitions as $name => $t) {
            if ((string) $name === '') {
                $errors[] = 'transition names must not be empty';
            }
            if ($t['from'] === []) {
                $errors[] = "transition '$name': at least one 'from' place is required";
            }
            foreach ($t['from'] as $from) {
                if (!\in_array($from, $def->places, true)) {
                    $errors[] = "transition '$name': from '$from' is not a known place";
                }
            }
            if (!\in_array($t['to'], $def->places, true)) {
                $errors[] = "transition '$name': to '{$t['to']}' is not a known place";
            }
        }
        foreach ($def->placeMeta as $place => $meta) {
            if (!\in_array($place, $def->places, true)) {
                $errors[] = "placeMeta '$place' is not a known place";
            }
            if (isset($meta['color']) && preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', (string) $meta['color']) !== 1) {
                $errors[] = "place '$place': color must be a hex colour like #52c41a";
            }
        }

        return $errors;
    }
}
