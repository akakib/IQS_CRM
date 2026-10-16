<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Blade does not compile @js() inside a component tag (<x-button @click="... @js($x) ...">): the browser gets
 * "@js(...)" as text, the script breaks and the button does nothing. Inside <x-...> tags use
 * {{ \Illuminate\Support\Js::from($x) }} instead (plain HTML tags may keep @js).
 */
class BladeComponentAttributesTest extends TestCase
{
    public function test_no_js_directive_inside_a_component_tag(): void
    {
        $found = [];
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views'));
        foreach ($dir as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $s = file_get_contents($file->getPathname());
            $at = 0;
            while (($i = strpos($s, '<x-', $at)) !== false) {
                $end = $this->tagEnd($s, $i);
                if ($end < 0) {
                    break;
                }
                if (str_contains(substr($s, $i, $end - $i), '@js(')) {
                    $found[] = str_replace(dirname(__DIR__, 2), '', $file->getPathname()).':'.(substr_count($s, "\n", 0, $i) + 1);
                }
                $at = $end + 1;
            }
        }
        $this->assertSame([], $found, 'Use {{ \Illuminate\Support\Js::from(...) }} inside <x-...> tags, not @js(...).');
    }

    /** The '>' that closes the tag starting at $i, outside quotes. */
    private function tagEnd(string $s, int $i): int
    {
        $quote = null;
        for ($j = $i, $n = strlen($s); $j < $n; $j++) {
            $c = $s[$j];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '>') {
                return $j;
            }
        }

        return -1;
    }
}
