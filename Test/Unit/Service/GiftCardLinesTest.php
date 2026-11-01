<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Test\Unit\Service;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Service\ProportionalSplitter;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Service\CodeMasker;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Service\GiftCardLines;

class GiftCardLinesTest extends TestCase
{
    private GiftCardLines $lines;

    protected function setUp(): void
    {
        $this->lines = new GiftCardLines(new Json(), new CodeMasker(), new ProportionalSplitter());
    }

    public function testSingleCardBecomesMaskedContractLine(): void
    {
        $lines = $this->lines->build('{"LABGIFT-0000-1234":20}', 20.0, 20.0);

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
    }

    public function testFullCodeNeverAppearsInTheSerialisedLine(): void
    {
        $lines = $this->lines->build('{"LABGIFT-0000-1234":20}', 20.0, 20.0);

        $this->assertStringNotContainsString('LABGIFT', (string)json_encode($lines));
    }

    public function testSeveralCardsGiveOneLinePerCardInStoredOrder(): void
    {
        $lines = $this->lines->build('{"AAAA-1111":5,"BBBB-2222":15}', 20.0, 20.0);

        $this->assertCount(2, $lines);
        $this->assertSame('Gift card ••••1111', $lines[0]->getLabel());
        $this->assertSame(0, $lines[0]->getSortOrder());
        $this->assertSame(5.0, $lines[0]->getAmount());
        $this->assertSame('Gift card ••••2222', $lines[1]->getLabel());
        $this->assertSame(1, $lines[1]->getSortOrder());
        $this->assertSame(15.0, $lines[1]->getAmount());
    }

    public function testLinesAddUpToTheTotalsEvenWhenTheDocumentTotalIsScaled(): void
    {
        // An invoice that carries 10 of the 20 redeemed on the order, base currency 8.
        $lines = $this->lines->build('{"AAAA-1111":5,"BBBB-2222":15}', 10.0, 8.0);

        $this->assertEqualsWithDelta(10.0, $lines[0]->getAmount() + $lines[1]->getAmount(), 0.0001);
        $this->assertEqualsWithDelta(8.0, $lines[0]->getBaseAmount() + $lines[1]->getBaseAmount(), 0.0001);
        $this->assertSame(2.5, $lines[0]->getAmount());
        $this->assertSame(7.5, $lines[1]->getAmount());
    }

    public function testRoundingDifferenceIsAbsorbedSoTheSumStaysExact(): void
    {
        $lines = $this->lines->build('{"AAAA-1111":1,"BBBB-2222":1,"CCCC-3333":1}', 10.0, 10.0);

        $sum = array_sum(array_map(static fn($line): float => $line->getAmount(), $lines));
        $this->assertEqualsWithDelta(10.0, $sum, 0.0001);
    }

    public function testCardWithoutRedeemedAmountIsDropped(): void
    {
        $lines = $this->lines->build('{"AAAA-1111":0,"BBBB-2222":12.5}', 12.5, 12.5);

        $this->assertCount(1, $lines);
        $this->assertSame('••••2222', $lines[0]->getCode());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unusableData(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'invalid json' => ['{not json'],
            'scalar' => ['"text"'],
            'empty map' => ['{}'],
            'empty list' => ['[]'],
        ];
    }

    /**
     * @dataProvider unusableData
     */
    public function testUnusableStoredDataGivesNoLines(?string $stored): void
    {
        $this->assertSame([], $this->lines->build($stored, 20.0, 20.0));
    }

    public function testNoLinesWhenNothingWasRedeemed(): void
    {
        $this->assertSame([], $this->lines->build('{"AAAA-1111":5}', 0.0, 0.0));
    }

    public function testTotalAtTheEpsilonIsTreatedAsNothingRedeemed(): void
    {
        $this->assertSame([], $this->lines->build('{"AAAA-1111":5}', 0.00001, 0.00001));
    }

    public function testBaseTotalAtTheEpsilonFallsBackToTheOrderTotal(): void
    {
        $lines = $this->lines->build('{"AAAA-1111":5}', 5.0, 0.00001);

        $this->assertSame(5.0, $lines[0]->getBaseAmount());
    }

    public function testCardAtTheEpsilonIsDropped(): void
    {
        $lines = $this->lines->build('{"AAAA-1111":0.00001,"BBBB-2222":5}', 5.0, 5.0);

        $this->assertCount(1, $lines);
        $this->assertSame('••••2222', $lines[0]->getCode());
    }

    public function testNumericStringAmountIsUsedAsNumberAndNonNumericIsDropped(): void
    {
        $lines = $this->lines->build('{"AAAA-1111":"abc","BBBB-2222":"5.5"}', 5.5, 5.5);

        $this->assertCount(1, $lines);
        $this->assertSame('••••2222', $lines[0]->getCode());
        $this->assertSame(5.5, $lines[0]->getAmount());
    }

    public function testAllDigitCodeIsMaskedLikeAnyOther(): void
    {
        $lines = $this->lines->build('{"12345678":5}', 5.0, 5.0);

        $this->assertSame('••••5678', $lines[0]->getCode());
        $this->assertSame('Gift card ••••5678', $lines[0]->getLabel());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankData(): array
    {
        return ['empty' => [''], 'spaces' => ['   '], 'newline' => ["\n"]];
    }

    /**
     * @dataProvider blankData
     */
    public function testBlankStoredDataIsNotEvenParsed(string $stored): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects($this->never())->method('unserialize');
        $lines = new GiftCardLines($serializer, new CodeMasker(), new ProportionalSplitter());

        $this->assertSame([], $lines->build($stored, 20.0, 20.0));
    }

    public function testMissingBaseTotalFallsBackToTheOrderTotal(): void
    {
        $lines = $this->lines->build('{"AAAA-1111":5}', 5.0, 0.0);

        $this->assertSame(5.0, $lines[0]->getBaseAmount());
    }
}
