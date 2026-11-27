<?php

namespace Tests\Unit\Models;

use App\Models\Workspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkspaceInitialsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'one word' => ['marketing', 'M'],
            'two words' => ['Acme ops', 'AO'],
            'first and last of several words' => ['North East Sales', 'NS'],
        ];
    }

    #[DataProvider('names')]
    public function test_initials_are_the_first_and_last_words_first_letters(string $name, string $initials): void
    {
        $this->assertSame($initials, (new Workspace(['name' => $name]))->initials());
    }
}
