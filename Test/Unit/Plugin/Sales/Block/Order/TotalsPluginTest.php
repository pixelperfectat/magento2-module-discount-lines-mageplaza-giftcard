<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Test\Unit\Plugin\Sales\Block\Order;

use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Block\Order\Totals;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Service\ProportionalSplitter;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Api\Service\GiftCardLinesInterface;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Plugin\Sales\Block\Order\TotalsPlugin;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Service\CodeMasker;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Service\GiftCardLines;
use Psr\Log\LoggerInterface;

class TotalsPluginTest extends TestCase
{
    private const FULL_CODE = 'LABGIFT-0000-1234';

    private ConfigInterface&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private TotalsPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->plugin = $this->plugin($this->config);
    }

    private function plugin(ConfigInterface $config): TotalsPlugin
    {
        return new TotalsPlugin(
            $config,
            new GiftCardLines(new Json(), new CodeMasker(), new ProportionalSplitter()),
            $this->logger
        );
    }

    private function order(string $cards): Order&MockObject
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId'])
            ->getMock();
        $order->method('getStoreId')->willReturn(1);
        $order->setData('mp_gift_cards', $cards);

        return $order;
    }

    private function block(object $source): Totals&MockObject
    {
        $block = $this->createMock(Totals::class);
        $block->method('getSource')->willReturn($source);

        return $block;
    }

    /**
     * @return array<string, DataObject>
     */
    private function totals(float $gift, float $baseGift): array
    {
        return [
            'subtotal' => new DataObject(['code' => 'subtotal', 'value' => 100.0, 'label' => 'Subtotal']),
            'gift_card' => new DataObject([
                'code' => 'gift_card',
                'value' => $gift,
                'base_value' => $baseGift,
                'label' => 'Gift Cards',
            ]),
            'gift_credit' => new DataObject(['code' => 'gift_credit', 'value' => -2.0, 'label' => 'Gift Credit']),
            'grand_total' => new DataObject(['code' => 'grand_total', 'value' => 78.0, 'label' => 'Grand Total']),
        ];
    }

    public function testRowGetsMaskedLabelAndContractData(): void
    {
        $block = $this->block($this->order('{"' . self::FULL_CODE . '":20}'));

        $result = $this->plugin->afterGetTotals($block, $this->totals(-20.0, -20.0));

        $row = $result['gift_card'];
        $this->assertSame('Gift card ••••1234', $row->getLabel());
        $this->assertSame(-20.0, $row->getValue());
        $this->assertSame(
            [
                'kind' => 'gift_card',
                'rule_id' => null,
                'code' => '••••1234',
                'label' => 'Gift card ••••1234',
                'amount' => 20.0,
                'base_amount' => 20.0,
            ],
            $row->getDiscountLine()
        );
        $this->assertStringNotContainsString(self::FULL_CODE, (string)json_encode($row->getData()));
    }

    public function testSeveralCardsGiveOneRowEachInPlaceOfTheOriginal(): void
    {
        $block = $this->block($this->order('{"AAAA-1111":5,"BBBB-2222":15}'));

        $result = $this->plugin->afterGetTotals($block, $this->totals(-20.0, -18.0));

        $this->assertSame(
            ['subtotal', 'gift_card', 'gift_card_1', 'gift_credit', 'grand_total'],
            array_keys($result)
        );
        $this->assertSame('Gift card ••••1111', $result['gift_card']->getLabel());
        $this->assertSame('Gift card ••••2222', $result['gift_card_1']->getLabel());
        $this->assertSame('gift_card_1', $result['gift_card_1']->getCode());
        $this->assertEqualsWithDelta(
            -20.0,
            $result['gift_card']->getValue() + $result['gift_card_1']->getValue(),
            0.0001
        );
        $this->assertEqualsWithDelta(
            -18.0,
            $result['gift_card']->getBaseValue() + $result['gift_card_1']->getBaseValue(),
            0.0001
        );
    }

    public function testInvoiceUsesTheOrderCardSharesScaledToTheDocumentTotal(): void
    {
        $invoice = $this->getMockBuilder(Invoice::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId', 'getOrder'])
            ->getMock();
        $invoice->method('getStoreId')->willReturn(1);
        $invoice->method('getOrder')->willReturn($this->order('{"AAAA-1111":5,"BBBB-2222":15}'));

        $result = $this->plugin->afterGetTotals($this->block($invoice), $this->totals(-10.0, -10.0));

        $this->assertSame(-2.5, $result['gift_card']->getValue());
        $this->assertSame(-7.5, $result['gift_card_1']->getValue());
    }

    public function testPositiveRowKeepsItsSign(): void
    {
        $block = $this->block($this->order('{"AAAA-1111":5,"BBBB-2222":15}'));

        $result = $this->plugin->afterGetTotals($block, $this->totals(20.0, 18.0));

        $this->assertSame(5.0, $result['gift_card']->getValue());
        $this->assertSame(15.0, $result['gift_card_1']->getValue());
    }

    public function testSmallPositiveRowKeepsItsSign(): void
    {
        $block = $this->block($this->order('{"AAAA-1111":0.5}'));

        $result = $this->plugin->afterGetTotals($block, $this->totals(0.5, 0.5));

        $this->assertSame(0.5, $result['gift_card']->getValue());
    }

    public function testRowsKeepTheOtherDataOfTheMageplazaRow(): void
    {
        $block = $this->block($this->order('{"AAAA-1111":20}'));
        $totals = $this->totals(-20.0, -20.0);
        $totals['gift_card']->setData('block_name', 'custom_block');

        $row = $this->plugin->afterGetTotals($block, $totals)['gift_card'];

        $this->assertSame('custom_block', $row->getData('block_name'));
        $this->assertSame('gift_card', $row->getData('code'));
    }

    public function testRowsAreNumberedByPositionNotByTheKeysOfTheLines(): void
    {
        $lines = [
            4 => new DiscountLine('gift_card', null, '••••1111', 'Gift card ••••1111', 5.0, 5.0),
            9 => new DiscountLine('gift_card', null, '••••2222', 'Gift card ••••2222', 15.0, 15.0),
        ];
        $giftCardLines = $this->createMock(GiftCardLinesInterface::class);
        $giftCardLines->method('build')->willReturn($lines);
        $plugin = new TotalsPlugin($this->config, $giftCardLines, $this->logger);

        $result = $plugin->afterGetTotals(
            $this->block($this->order('{"AAAA-1111":20}')),
            $this->totals(-20.0, -20.0)
        );

        $this->assertSame(
            ['subtotal', 'gift_card', 'gift_card_1', 'gift_credit', 'grand_total'],
            array_keys($result)
        );
    }

    public function testRowOrderAndOtherRowsAreKept(): void
    {
        $block = $this->block($this->order('{"AAAA-1111":20}'));
        $totals = $this->totals(-20.0, -20.0);

        $result = $this->plugin->afterGetTotals($block, $totals);

        $this->assertSame(['subtotal', 'gift_card', 'gift_credit', 'grand_total'], array_keys($result));
        $this->assertSame($totals['gift_credit'], $result['gift_credit']);
        $this->assertSame('Gift Credit', $result['gift_credit']->getLabel());
    }

    public function testFooterAreaWithoutGiftCardRowIsUntouched(): void
    {
        $footer = ['paid' => new DataObject(['code' => 'paid', 'value' => 1.0, 'label' => 'Paid'])];

        $this->assertSame(
            $footer,
            $this->plugin->afterGetTotals($this->block($this->order('{"AAAA-1111":20}')), $footer, 'footer')
        );
    }

    public function testNothingIsInsertedWhenThereIsNoGiftCardRow(): void
    {
        $totals = ['subtotal' => new DataObject(['code' => 'subtotal', 'value' => 1.0, 'label' => 'Subtotal'])];
        $this->logger->expects($this->never())->method('error');

        $this->assertSame(
            $totals,
            $this->plugin->afterGetTotals($this->block($this->order('{"AAAA-1111":20}')), $totals, '')
        );
    }

    public function testNullTotalsPassThrough(): void
    {
        $this->assertNull($this->plugin->afterGetTotals($this->createMock(Totals::class), null));
    }

    public function testOrderWithoutStoredCardsKeepsMageplazaRow(): void
    {
        $totals = $this->totals(-20.0, -20.0);

        $this->assertSame($totals, $this->plugin->afterGetTotals($this->block($this->order('')), $totals));
    }

    public function testDisabledModuleKeepsMageplazaRow(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(false);
        $totals = $this->totals(-20.0, -20.0);

        $result = $this->plugin($config)->afterGetTotals($this->block($this->order('{"AAAA-1111":20}')), $totals);

        $this->assertSame($totals, $result);
    }

    public function testFailureIsLoggedAndRowsKept(): void
    {
        $block = $this->createMock(Totals::class);
        $block->method('getSource')->willThrowException(new \RuntimeException('boom'));
        $totals = $this->totals(-20.0, -20.0);
        $this->logger->expects($this->once())->method('error')->with(
            'Discount lines: gift card totals rendering failed, Mageplaza row kept.',
            $this->callback(static fn(array $context): bool => $context['exception'] instanceof \RuntimeException)
        );

        $this->assertSame($totals, $this->plugin->afterGetTotals($block, $totals));
    }
}
