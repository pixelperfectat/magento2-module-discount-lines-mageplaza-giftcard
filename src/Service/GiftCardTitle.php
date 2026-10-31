<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Service;

use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Api\Service\GiftCardTitleInterface;

class GiftCardTitle implements GiftCardTitleInterface
{
    /**
     * @inheritDoc
     */
    public function title(array $lines): string
    {
        if ($lines === []) {
            return (string)__('Gift Cards');
        }
        if (count($lines) === 1) {
            return reset($lines)->getLabel();
        }

        return (string)__('Gift cards (%1)', implode(', ', array_map(
            static fn(DiscountLineInterface $line): string => (string)$line->getCode(),
            $lines
        )));
    }
}
