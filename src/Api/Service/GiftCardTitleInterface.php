<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Api\Service;

use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;

interface GiftCardTitleInterface
{
    /**
     * Get the customer-safe title of the gift card total: only masked codes.
     *
     * @param DiscountLineInterface[] $lines
     * @return string
     */
    public function title(array $lines): string;
}
