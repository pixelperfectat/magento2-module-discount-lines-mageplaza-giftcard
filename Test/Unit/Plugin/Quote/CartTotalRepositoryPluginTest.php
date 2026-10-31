<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Test\Unit\Plugin\Quote;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\TotalSegmentExtension;
use Magento\Quote\Api\Data\TotalSegmentExtensionFactory;
use Magento\Quote\Api\Data\TotalSegmentExtensionInterface;
use Magento\Quote\Api\Data\TotalSegmentInterface;
use Magento\Quote\Api\Data\TotalsInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Service\ProportionalSplitter;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Plugin\Quote\CartTotalRepositoryPlugin;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Service\CodeMasker;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Service\GiftCardLines;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Service\GiftCardTitle;
use Psr\Log\LoggerInterface;

if (!interface_exists(TotalSegmentExtensionInterface::class)) {
    require_once __DIR__ . '/../../../Stub/TotalSegmentExtensionInterface.php';
}
if (!class_exists(TotalSegmentExtension::class)) {
    require_once __DIR__ . '/../../../Stub/TotalSegmentExtension.php';
}
if (!class_exists(TotalSegmentExtensionFactory::class)) {
    require_once __DIR__ . '/../../../Stub/TotalSegmentExtensionFactory.php';
}

class CartTotalRepositoryPluginTest extends TestCase
{
    private const FULL_CODE = 'LABGIFT-0000-1234';

    private ConfigInterface&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private Quote&MockObject $quote;
    private Address&MockObject $address;
    private CartTotalRepositoryPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId', 'isVirtual', 'getShippingAddress', 'getBillingAddress'])
            ->getMock();
        $this->address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $this->quote->method('getStoreId')->willReturn(1);
        $this->quote->method('isVirtual')->willReturn(false);
        $this->quote->method('getShippingAddress')->willReturn($this->address);
        $this->quote->method('getBillingAddress')->willReturn($this->address);
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->method('getActive')->willReturn($this->quote);
        $this->plugin = new CartTotalRepositoryPlugin(
            $repository,
            $this->config,
            new GiftCardLines(new Json(), new CodeMasker(), new ProportionalSplitter()),
            new GiftCardTitle(),
            new TotalSegmentExtensionFactory(),
            $this->logger
        );
    }

    private function segment(float $value, string $title): TotalSegmentInterface&MockObject
    {
        $holder = new \stdClass();
        $holder->extension = null;
        $holder->title = $title;
        $segment = $this->createMock(TotalSegmentInterface::class);
        $segment->method('getValue')->willReturn($value);
        $segment->method('getTitle')->willReturnCallback(static fn() => $holder->title);
        $segment->method('setTitle')->willReturnCallback(
            static function ($newTitle) use ($holder, $segment) {
                $holder->title = $newTitle;

                return $segment;
            }
        );
        $segment->method('getExtensionAttributes')->willReturnCallback(static fn() => $holder->extension);
        $segment->method('setExtensionAttributes')->willReturnCallback(
            static function ($extension) use ($holder, $segment) {
                $holder->extension = $extension;

                return $segment;
            }
        );

        return $segment;
    }

    /**
     * @param array<string, TotalSegmentInterface> $segments
     */
    private function totals(array $segments): TotalsInterface&MockObject
    {
        $totals = $this->createMock(TotalsInterface::class);
        $totals->method('getTotalSegments')->willReturn($segments);

        return $totals;
    }

    private function process(TotalsInterface $totals): TotalsInterface
    {
        return $this->plugin->afterGet($this->createMock(CartTotalRepositoryInterface::class), $totals, 7);
    }

    public function testGiftCardSegmentGetsMaskedLineAndTitle(): void
    {
        $this->quote->setData('mp_gift_cards', '{"' . self::FULL_CODE . '":20}');
        $segment = $this->segment(-20.0, 'Gift Cards');

        $this->process($this->totals(['gift_card' => $segment]));

        $extension = $segment->getExtensionAttributes();
        $this->assertInstanceOf(TotalSegmentExtensionInterface::class, $extension);
        $lines = $extension->getDiscountLines();
        $this->assertCount(1, $lines);
        $this->assertSame(
            [
                'kind' => 'gift_card',
                'rule_id' => null,
                'code' => '••••1234',
                'label' => 'Gift card ••••1234',
                'amount' => 20.0,
                'base_amount' => 20.0,
            ],
            $lines[0]->toArray()
        );
        $this->assertSame('Gift card ••••1234', $segment->getTitle());
        $this->assertStringNotContainsString(self::FULL_CODE, (string)json_encode($lines));
    }

    public function testSeveralCardsGetOneLineEachAndAListedTitle(): void
    {
        $this->quote->setData('mp_gift_cards', '{"AAAA-1111":5,"BBBB-2222":10}');
        $segment = $this->segment(-15.0, 'Gift Cards');

        $this->process($this->totals(['gift_card' => $segment]));

        $this->assertCount(2, $segment->getExtensionAttributes()->getDiscountLines());
        $this->assertSame('Gift cards (••••1111, ••••2222)', $segment->getTitle());
    }

    public function testBaseAmountIsDerivedFromTheQuoteRate(): void
    {
        $this->quote->setData('mp_gift_cards', '{"AAAA-1111":20}');
        $this->quote->setData('base_to_quote_rate', 2.0);
        $segment = $this->segment(-20.0, 'Gift Cards');

        $this->process($this->totals(['gift_card' => $segment]));

        $line = $segment->getExtensionAttributes()->getDiscountLines()[0];
        $this->assertSame(20.0, $line->getAmount());
        $this->assertSame(10.0, $line->getBaseAmount());
    }

    public function testCollectedAddressBaseAmountWinsOverTheRate(): void
    {
        $this->quote->setData('mp_gift_cards', '{"AAAA-1111":20}');
        $this->quote->setData('base_to_quote_rate', 2.0);
        $this->address->setData('base_gift_card_amount', -12.5);
        $segment = $this->segment(-20.0, 'Gift Cards');

        $this->process($this->totals(['gift_card' => $segment]));

        $line = $segment->getExtensionAttributes()->getDiscountLines()[0];
        $this->assertSame(20.0, $line->getAmount());
        $this->assertSame(12.5, $line->getBaseAmount());
    }

    public function testDiscountSegmentIsNeverTouched(): void
    {
        $this->quote->setData('mp_gift_cards', '{"AAAA-1111":5}');
        $discount = $this->segment(-3.0, 'Discount (Summer sale)');

        $this->process($this->totals(['discount' => $discount, 'gift_card' => $this->segment(-5.0, 'Gift Cards')]));

        $this->assertNull($discount->getExtensionAttributes());
        $this->assertSame('Discount (Summer sale)', $discount->getTitle());
    }

    public function testCartWithoutGiftCardSegmentIsUnchanged(): void
    {
        $totals = $this->totals(['subtotal' => $this->segment(10.0, 'Subtotal')]);

        $this->assertSame($totals, $this->process($totals));
    }

    public function testDisabledModuleKeepsMageplazaSegment(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(false);
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->method('getActive')->willReturn($this->quote);
        $plugin = new CartTotalRepositoryPlugin(
            $repository,
            $config,
            new GiftCardLines(new Json(), new CodeMasker(), new ProportionalSplitter()),
            new GiftCardTitle(),
            new TotalSegmentExtensionFactory(),
            $this->logger
        );
        $this->quote->setData('mp_gift_cards', '{"AAAA-1111":5}');
        $segment = $this->segment(-5.0, 'Gift Cards');

        $plugin->afterGet(
            $this->createMock(CartTotalRepositoryInterface::class),
            $this->totals(['gift_card' => $segment]),
            7
        );

        $this->assertNull($segment->getExtensionAttributes());
        $this->assertSame('Gift Cards', $segment->getTitle());
    }

    public function testUnusableStoredDataKeepsMageplazaSegment(): void
    {
        $this->quote->setData('mp_gift_cards', '{broken');
        $segment = $this->segment(-5.0, 'Gift Cards');

        $this->process($this->totals(['gift_card' => $segment]));

        $this->assertNull($segment->getExtensionAttributes());
        $this->assertSame('Gift Cards', $segment->getTitle());
    }

    public function testFailureIsLoggedAndSegmentKept(): void
    {
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->method('getActive')->willThrowException(new \RuntimeException('boom'));
        $plugin = new CartTotalRepositoryPlugin(
            $repository,
            $this->config,
            new GiftCardLines(new Json(), new CodeMasker(), new ProportionalSplitter()),
            new GiftCardTitle(),
            new TotalSegmentExtensionFactory(),
            $this->logger
        );
        $this->logger->expects($this->once())->method('error');
        $segment = $this->segment(-5.0, 'Gift Cards');

        $plugin->afterGet(
            $this->createMock(CartTotalRepositoryInterface::class),
            $this->totals(['gift_card' => $segment]),
            7
        );

        $this->assertSame('Gift Cards', $segment->getTitle());
    }
}
