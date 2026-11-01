<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Service\CodeMasker;

class CodeMaskerTest extends TestCase
{
    public function testKeepsOnlyTheLastFourCharacters(): void
    {
        $this->assertSame('••••1234', (new CodeMasker())->mask('LABGIFT-0000-1234'));
    }

    public function testCodeOfFiveCharactersShowsFourOfThem(): void
    {
        $this->assertSame('••••BCDE', (new CodeMasker())->mask('ABCDE'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function shortCodes(): array
    {
        return [
            'four characters' => ['ABCD', '••••'],
            'three characters' => ['ABC', '••••'],
            'one character' => ['A', '••••'],
            'empty' => ['', '••••'],
        ];
    }

    /**
     * @dataProvider shortCodes
     */
    public function testShortCodeIsMaskedCompletelyWithAtLeastFourDots(string $code, string $expected): void
    {
        $this->assertSame($expected, (new CodeMasker())->mask($code));
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        $this->assertSame('••••1234', (new CodeMasker())->mask("  XY-1234 \n"));
    }

    public function testMultibyteCodeIsCountedInCharacters(): void
    {
        $this->assertSame('••••äöüß', (new CodeMasker())->mask('GUTSCHEIN-äöüß'));
    }

    public function testMultibyteCharactersBeforeTheVisiblePartDoNotShiftIt(): void
    {
        $this->assertSame('••••1234', (new CodeMasker())->mask('äöü-1234'));
    }

    public function testMaskAndVisibleLengthAreConfigurable(): void
    {
        $this->assertSame('******12', (new CodeMasker('*', 2, 6))->mask('ABCDEF12'));
    }
}
