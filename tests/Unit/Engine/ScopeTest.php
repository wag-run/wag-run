<?php

namespace Tests\Unit\Engine;

use App\Engine\Scope;
use PHPUnit\Framework\TestCase;

class ScopeTest extends TestCase
{
    public function test_builds_and_reads_nested_iteration_scopes(): void
    {
        $outer = Scope::forIteration('', 12, 0);
        $inner = Scope::forIteration($outer, 15, 3);

        $this->assertSame('L12.0/L15.3', $inner);
        $this->assertSame(['loop_step_id' => 15, 'index' => 3], Scope::innermost($inner));
        $this->assertSame(['loop_step_id' => 12, 'index' => 0], Scope::innermost($outer));
        $this->assertNull(Scope::innermost(''));
        $this->assertSame($outer, Scope::parent($inner));
    }

    public function test_steps_see_their_own_and_enclosing_scopes_only(): void
    {
        $this->assertTrue(Scope::canSee('L1.0/L2.1', ''));
        $this->assertTrue(Scope::canSee('L1.0/L2.1', 'L1.0'));
        $this->assertTrue(Scope::canSee('L1.0', 'L1.0'));
        $this->assertFalse(Scope::canSee('L1.0', 'L1.1'), 'other iterations are invisible');
        $this->assertFalse(Scope::canSee('', 'L1.0'), 'loop bodies are invisible from outside');
        $this->assertFalse(Scope::canSee('L1.10', 'L1.1'), 'prefix of a different index');
    }

    public function test_lists_exactly_the_scopes_a_step_can_see(): void
    {
        $this->assertSame([''], Scope::visibleFrom(''));
        $this->assertSame(['', 'L1.10'], Scope::visibleFrom('L1.10'));
        $this->assertSame(['', 'L1.0/L2.1/L3.4', 'L1.0/L2.1', 'L1.0'], Scope::visibleFrom('L1.0/L2.1/L3.4'));

        foreach (['', 'L1.0', 'L1.1', 'L1.10', 'L1.0/L2.1', 'L1.0/L2.2', 'L1.0/L2.1/L3.4', 'L5.0'] as $other) {
            $this->assertSame(Scope::canSee('L1.0/L2.1/L3.4', $other), in_array($other, Scope::visibleFrom('L1.0/L2.1/L3.4'), true), $other);
        }
    }
}
