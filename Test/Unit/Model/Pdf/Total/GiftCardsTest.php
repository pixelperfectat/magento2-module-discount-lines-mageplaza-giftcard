<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Test\Unit\Model\Pdf\Total;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Tax\Helper\Data;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\ResourceModel\Sales\Order\Tax\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Service\ProportionalSplitter;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Model\Pdf\Total\GiftCards;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Service\CodeMasker;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Service\GiftCardLines;
use Psr\Log\LoggerInterface;

if (!class_exists(CollectionFactory::class)) {
    require_once __DIR__ . '/../../../../Stub/TaxOrderCollectionFactory.php';
}

class GiftCardsTest extends TestCase
{
    private const FULL_CODE = 'LABGIFT-0000-1234';

    private ConfigInterface&MockObject $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
    }

    private function total(string $cards, float $amount = -20.0, ?ConfigInterface $config = null): GiftCards
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['formatPriceTxt'])
            ->getMock();
        $order->method('formatPriceTxt')->willReturnCallback(
            static fn($value): string => sprintf('EUR %.2f', $value)
        );
        $order->setData('mp_gift_cards', $cards);
        $source = $this->getMockBuilder(Invoice::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId'])
            ->getMock();
        $source->method('getStoreId')->willReturn(1);
        $source->setData(['gift_card_amount' => $amount, 'base_gift_card_amount' => $amount]);

        return new GiftCards(
            $this->createMock(Data::class),
            $this->createMock(Calculation::class),
            $this->createMock(CollectionFactory::class),
            $config ?? $this->config,
            new GiftCardLines(new Json(), new CodeMasker(), new ProportionalSplitter()),
            $this->createMock(LoggerInterface::class),
            [
                'order' => $order,
                'source' => $source,
                'title' => 'Gift Cards',
                'source_field' => 'gift_card_amount',
                'font_size' => 7,
            ]
        );
    }

    public function testEntryCarriesTheMaskedLabel(): void
    {
        $entries = $this->total('{"' . self::FULL_CODE . '":20}')->getTotalsForDisplay();

        $this->assertSame([['amount' => 'EUR -20.00', 'label' => 'Gift card ••••1234:', 'font_size' => 7]], $entries);
        $this->assertStringNotContainsString(self::FULL_CODE, (string)json_encode($entries));
    }

    public function testSeveralCardsGiveOneEntryEach(): void
    {
        $entries = $this->total('{"AAAA-1111":5,"BBBB-2222":15}')->getTotalsForDisplay();

        $this->assertSame(
            [
                ['amount' => 'EUR -5.00', 'label' => 'Gift card ••••1111:', 'font_size' => 7],
                ['amount' => 'EUR -15.00', 'label' => 'Gift card ••••2222:', 'font_size' => 7],
            ],
            $entries
        );
    }

    public function testFallsBackToTheMageplazaEntryWithoutStoredCards(): void
    {
        $entries = $this->total('')->getTotalsForDisplay();

        $this->assertSame([['amount' => 'EUR -20.00', 'label' => 'Gift Cards:', 'font_size' => 7]], $entries);
    }

    public function testDisabledModuleKeepsTheMageplazaEntry(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(false);

        $entries = $this->total('{"AAAA-1111":20}', -20.0, $config)->getTotalsForDisplay();

        $this->assertSame('Gift Cards:', $entries[0]['label']);
    }

    public function testZeroAmountIsNotDisplayed(): void
    {
        $this->assertFalse($this->total('{"AAAA-1111":20}', 0.0)->canDisplay());
    }
}
