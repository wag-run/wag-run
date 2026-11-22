<?php

namespace App\Engine;

/**
 * Scope keys place steps inside loop iterations. '' is the top level;
 * 'L12.0' is iteration 0 of the loop whose step is 12; 'L12.0/L15.3' is
 * iteration 3 of an inner loop (step 15) inside it.
 */
final class Scope
{
    public static function forIteration(string $parent, int $loopStepId, int $index): string
    {
        $frame = "L{$loopStepId}.{$index}";

        return $parent === '' ? $frame : "{$parent}/{$frame}";
    }

    /**
     * The innermost loop frame of a scope.
     *
     * @return array{loop_step_id: int, index: int}|null
     */
    public static function innermost(string $scope): ?array
    {
        if ($scope === '') {
            return null;
        }

        $last = substr($scope, (int) strrpos('/'.$scope, '/'));

        if (! preg_match('/^L(\d+)\.(\d+)$/', $last, $matches)) {
            return null;
        }

        return ['loop_step_id' => (int) $matches[1], 'index' => (int) $matches[2]];
    }

    public static function parent(string $scope): string
    {
        $position = strrpos($scope, '/');

        return $position === false ? '' : substr($scope, 0, $position);
    }

    /**
     * Whether a step in scope $scope can see the outputs of a step in $other:
     * its own scope and every enclosing one.
     */
    public static function canSee(string $scope, string $other): bool
    {
        return $other === '' || $other === $scope || str_starts_with($scope, $other.'/');
    }

    /**
     * Whether $other is $scope itself or nested inside it.
     */
    public static function contains(string $scope, string $other): bool
    {
        return $scope === '' || $other === $scope || str_starts_with($other, $scope.'/');
    }
}
