<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Api\Service;

use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;

interface GiftCardLinesInterface
{
    /**
     * Build one masked `gift_card` line per applied card.
     *
     * The per-card amounts Mageplaza stores are used as weights; the lines are scaled and rounded so they add up to
     * the given totals exactly. Returns no lines when nothing is stored, nothing is redeemed or the data is unusable.
     *
     * @param string|null $storedCards Mageplaza JSON map of code => redeemed amount (quote or order `mp_gift_cards`)
     * @param float $total Redeemed total in order currency, positive
     * @param float $baseTotal Redeemed total in base currency, positive
     * @return DiscountLineInterface[]
     */
    public function build(?string $storedCards, float $total, float $baseTotal): array;
}
