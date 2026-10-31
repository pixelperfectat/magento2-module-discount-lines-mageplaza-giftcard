<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Service;

use InvalidArgumentException;
use Magento\Framework\Serialize\SerializerInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\ProportionalSplitterInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Api\Service\CodeMaskerInterface;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Api\Service\GiftCardLinesInterface;

/**
 * Turns Mageplaza's stored gift card map into masked discount lines.
 *
 * Mageplaza keeps the redeemed amount per card on the quote and the order (`mp_gift_cards`) but only a total on the
 * documents, so the lines of an invoice or credit memo are the order's card shares scaled to the document total.
 */
class GiftCardLines implements GiftCardLinesInterface
{
    private const EPSILON = 0.00001;

    /**
     * @param SerializerInterface $serializer
     * @param CodeMaskerInterface $masker
     * @param ProportionalSplitterInterface $splitter
     */
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly CodeMaskerInterface $masker,
        private readonly ProportionalSplitterInterface $splitter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function build(?string $storedCards, float $total, float $baseTotal): array
    {
        if ($total <= self::EPSILON) {
            return [];
        }
        $lines = [];
        foreach ($this->cards($storedCards) as $code => $amount) {
            $masked = $this->masker->mask($code);
            $lines[] = new DiscountLine(
                DiscountLineInterface::KIND_GIFT_CARD,
                null,
                $masked,
                (string)__('Gift card %1', $masked),
                $amount,
                $amount,
                count($lines)
            );
        }

        return $this->splitter->split($lines, $total, $baseTotal > self::EPSILON ? $baseTotal : $total);
    }

    /**
     * Read the redeemed cards: code => amount, cards without a redeemed amount are dropped.
     *
     * @param string|null $storedCards
     * @return array<string, float>
     */
    private function cards(?string $storedCards): array
    {
        if ($storedCards === null || trim($storedCards) === '') {
            return [];
        }
        try {
            $data = $this->serializer->unserialize($storedCards);
        } catch (InvalidArgumentException) {
            return [];
        }
        if (!is_array($data)) {
            return [];
        }

        $cards = [];
        foreach ($data as $code => $amount) {
            if (is_numeric($amount) && (float)$amount > self::EPSILON) {
                $cards[(string)$code] = (float)$amount;
            }
        }

        return $cards;
    }
}
