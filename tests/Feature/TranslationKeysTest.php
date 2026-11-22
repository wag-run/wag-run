<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Lang;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class TranslationKeysTest extends TestCase
{
    /**
     * Every __('…') call in the app and its views, as [file, key, isPrefix].
     * A key followed by a "." is the prefix of a key built at runtime.
     *
     * @return list<array{string, string, bool}>
     */
    private function translationCalls(): array
    {
        $calls = [];
        $finder = Finder::create()->files()->name('*.php')->in([app_path(), resource_path('views'), base_path('routes')]);

        foreach ($finder as $file) {
            preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'(\\s*\\.)?/", $file->getContents(), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $calls[] = [$file->getRelativePathname(), $match[1], ($match[2] ?? '') !== ''];
            }
        }

        return $calls;
    }

    public function test_every_string_is_a_translation_key_that_exists(): void
    {
        $problems = [];

        foreach ($this->translationCalls() as [$file, $key, $isPrefix]) {
            $lookup = $isPrefix ? rtrim($key, '.') : $key;

            if (! preg_match('/^[a-z_]+(\.[a-z0-9_]+)+\.?$/', $key)) {
                $problems[] = "{$file}: \"{$key}\" is text, not a translation key";
            } elseif (! Lang::has($lookup, 'en', false)) {
                $problems[] = "{$file}: \"{$key}\" is missing from lang/en";
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_page_titles_are_translated(): void
    {
        $untranslated = Finder::create()->files()->in(resource_path('views'))->contains('#[Title(');

        $this->assertSame([], array_keys(iterator_to_array($untranslated)));
    }
}
