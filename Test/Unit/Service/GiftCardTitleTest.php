<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Service\GiftCardTitle;

class GiftCardTitleTest extends TestCase
{
    private function line(string $masked): DiscountLine
    {
        return new DiscountLine('gift_card', null, $masked, 'Gift card ' . $masked, 5.0, 5.0);
    }

    public function testSingleCardUsesItsLabel(): void
    {
        $this->assertSame('Gift card ••••1234', (new GiftCardTitle())->title([$this->line('••••1234')]));
    }

    public function testSeveralCardsAreListedWithTheirMaskedCodes(): void
    {
        $title = (new GiftCardTitle())->title([$this->line('••••1111'), $this->line('••••2222')]);

        $this->assertSame('Gift cards (••••1111, ••••2222)', $title);
    }

    public function testLineWithoutCodeContributesAnEmptyEntry(): void
    {
        $withoutCode = new DiscountLine('gift_card', null, null, 'Gift card', 5.0, 5.0);

        $title = (new GiftCardTitle())->title([$withoutCode, $this->line('••••2222')]);

        $this->assertSame('Gift cards (, ••••2222)', $title);
    }

    public function testNoLinesFallBackToTheGenericTitle(): void
    {
        $this->assertSame('Gift Cards', (new GiftCardTitle())->title([]));
    }
}
