<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class NoNativeDialogsTest extends TestCase
{
    /**
     * Confirmations use the app's modal ($confirm) rather than the browser's
     * native dialogs, which wire:confirm and confirm()/alert()/prompt() open.
     * Comments may mention them.
     */
    public function test_views_and_scripts_do_not_open_native_dialogs(): void
    {
        $offenders = [];
        $finder = Finder::create()->files()->in([resource_path('views'), resource_path('js')])->name(['*.php', '*.js']);

        foreach ($finder as $file) {
            $code = preg_replace(['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $file->getContents());

            if (preg_match('/wire:confirm|(?<![\w.$])(window\.)?(confirm|alert|prompt)\(/', $code, $match)) {
                $offenders[] = $file->getRelativePathname().': '.$match[0];
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * After $confirm(...), the action has to be called, not just named:
     * "ok && $wire.discardDraft" would confirm and then do nothing.
     */
    public function test_confirmed_actions_are_called(): void
    {
        $offenders = [];

        foreach (Finder::create()->files()->in(resource_path('views'))->name('*.php') as $file) {
            if (preg_match_all('/ok && \$wire\.\w+(?!\w|\()/', $file->getContents(), $matches)) {
                array_push($offenders, ...array_map(fn (string $match) => $file->getRelativePathname().': '.$match, $matches[0]));
            }
        }

        $this->assertSame([], $offenders);
    }
}
