<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Test\Unit;

use PHPUnit\Framework\TestCase;

class TranslationTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../src';

    /**
     * @return string[]
     */
    private function sourceStrings(): array
    {
        $strings = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::MODULE_DIR));
        foreach ($files as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'phtml'], true)) {
                continue;
            }
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string)file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $string) {
                $strings[] = stripslashes($string);
            }
        }
        $strings = array_values(array_unique($strings));
        sort($strings);

        return $strings;
    }

    /**
     * @return array<string, string>
     */
    private function csv(string $locale): array
    {
        $path = self::MODULE_DIR . '/i18n/' . $locale . '.csv';
        self::assertFileExists($path);
        $rows = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        );

        return array_column($rows, 1, 0);
    }

    public function testSourceHasTranslatableStrings(): void
    {
        self::assertContains('Gift card %1', $this->sourceStrings());
    }

    public function testEnglishAndGermanCoverEverySourceString(): void
    {
        foreach (['en_US', 'de_DE'] as $locale) {
            $csv = $this->csv($locale);
            foreach ($this->sourceStrings() as $string) {
                self::assertArrayHasKey($string, $csv, $locale . ' misses "' . $string . '"');
                self::assertNotSame('', $csv[$string], $locale . ' has an empty translation for "' . $string . '"');
            }
        }
    }

    public function testGermanHasTheSameKeysAsEnglish(): void
    {
        self::assertSame(array_keys($this->csv('en_US')), array_keys($this->csv('de_DE')));
    }
}
