<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Service;

use PixelPerfect\DiscountLinesMageplazaGiftCard\Api\Service\CodeMaskerInterface;

/**
 * Masks a code to "••••1234": the last 4 characters stay, a code of 4 characters or fewer is masked completely
 * (never fewer than 4 mask characters), so a short code cannot be read off the label.
 */
class CodeMasker implements CodeMaskerInterface
{
    /**
     * @param string $maskCharacter
     * @param int $visibleLength Number of trailing characters that stay readable
     * @param int $minimumMaskLength Minimum number of mask characters
     */
    public function __construct(
        private readonly string $maskCharacter = '•',
        private readonly int $visibleLength = 4,
        private readonly int $minimumMaskLength = 4
    ) {
    }

    /**
     * @inheritDoc
     */
    public function mask(string $code): string
    {
        $code = trim($code);
        $length = mb_strlen($code);
        if ($length <= $this->visibleLength) {
            return str_repeat($this->maskCharacter, max($length, $this->minimumMaskLength));
        }

        return str_repeat($this->maskCharacter, $this->minimumMaskLength)
            . mb_substr($code, $length - $this->visibleLength);
    }
}
