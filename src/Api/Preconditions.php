<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Api;

use Thenativeweb\Eventsourcingdb\IsSubjectOnEventId;
use Thenativeweb\Eventsourcingdb\IsSubjectPopulated;
use Thenativeweb\Eventsourcingdb\IsSubjectPristine;

/**
 * Reads the preconditions of a write for the options of a precondition on a
 * subject, which only the v2 API knows.
 *
 * @internal
 */
final class Preconditions
{
    /**
     * Tells whether any of the given preconditions applies to the subjects
     * below its subject as well, which only the v2 API checks.
     */
    public static function includeRecursiveOne(array $preconditions): bool
    {
        foreach ($preconditions as $precondition) {
            if (self::isOnSubject($precondition) && $precondition->options?->recursive === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the given preconditions as the v1 API reads them, which knows no
     * options, and checks a precondition on a subject for the given subject
     * alone, which is what one that is not recursive means.
     */
    public static function removeOptions(array $preconditions): array
    {
        return array_map(
            static function (mixed $precondition): mixed {
                if (!self::isOnSubject($precondition)) {
                    return $precondition;
                }

                $preconditionWithoutOptions = $precondition->jsonSerialize();
                unset($preconditionWithoutOptions['payload']['options']);

                return $preconditionWithoutOptions;
            },
            $preconditions,
        );
    }

    /**
     * @phpstan-assert-if-true IsSubjectPristine|IsSubjectPopulated|IsSubjectOnEventId $precondition
     */
    private static function isOnSubject(mixed $precondition): bool
    {
        return $precondition instanceof IsSubjectPristine
            || $precondition instanceof IsSubjectPopulated
            || $precondition instanceof IsSubjectOnEventId;
    }
}
