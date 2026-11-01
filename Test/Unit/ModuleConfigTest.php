<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Test\Unit;

use PHPUnit\Framework\TestCase;

class ModuleConfigTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../src';

    public function testGiftCardTotalSortsBetweenTaxAndGrandTotal(): void
    {
        $xml = simplexml_load_file(self::MODULE_DIR . '/etc/config.xml');
        $sort = (int)$xml->default->sales->totals_sort->gift_card;

        $this->assertGreaterThan(40, $sort);
        $this->assertLessThan(100, $sort);
    }

    public function testModuleLoadsAfterBaseModuleAndMageplaza(): void
    {
        $xml = simplexml_load_file(self::MODULE_DIR . '/etc/module.xml');
        $modules = array_map('strval', $xml->xpath('//sequence/module/@name'));

        $this->assertContains('PixelPerfect_DiscountLines', $modules);
        $this->assertContains('Mageplaza_GiftCard', $modules);
    }
}
