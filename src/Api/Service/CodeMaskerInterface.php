<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Api\Service;

interface CodeMaskerInterface
{
    /**
     * Mask a gift card code: only its last characters stay readable.
     *
     * @param string $code
     * @return string
     */
    public function mask(string $code): string;
}
