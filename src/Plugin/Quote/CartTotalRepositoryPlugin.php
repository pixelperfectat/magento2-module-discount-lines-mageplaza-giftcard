<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Plugin\Quote;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\TotalSegmentExtensionFactory;
use Magento\Quote\Api\Data\TotalsInterface;
use Magento\Quote\Model\Quote;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Api\Service\GiftCardLinesInterface;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Api\Service\GiftCardTitleInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gives Mageplaza's `gift_card` total segment the masked lines and a masked title.
 *
 * The lines live on this segment only: the `discount` segment and its sum invariant are not touched. Never breaks
 * the cart: on any failure the segment is returned as Mageplaza built it.
 */
class CartTotalRepositoryPlugin
{
    public const SEGMENT_CODE = 'gift_card';

    /**
     * @param CartRepositoryInterface $cartRepository
     * @param ConfigInterface $config
     * @param GiftCardLinesInterface $giftCardLines
     * @param GiftCardTitleInterface $title
     * @param TotalSegmentExtensionFactory $extensionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ConfigInterface $config,
        private readonly GiftCardLinesInterface $giftCardLines,
        private readonly GiftCardTitleInterface $title,
        private readonly TotalSegmentExtensionFactory $extensionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Add the masked lines to the gift card segment and replace its title.
     *
     * @param CartTotalRepositoryInterface $subject
     * @param TotalsInterface $result
     * @param int|string $cartId
     * @return TotalsInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGet(CartTotalRepositoryInterface $subject, TotalsInterface $result, $cartId): TotalsInterface
    {
        $segment = $result->getTotalSegments()[self::SEGMENT_CODE] ?? null;
        if ($segment === null) {
            return $result;
        }

        try {
            $quote = $this->cartRepository->getActive((int)$cartId);
            if (!$quote instanceof Quote || !$this->config->isEnabled((int)$quote->getStoreId())) {
                return $result;
            }
            $amount = abs((float)$segment->getValue());
            $lines = $this->giftCardLines->build(
                (string)$quote->getData('mp_gift_cards'),
                $amount,
                $this->baseAmount($quote, $amount)
            );
            if ($lines === []) {
                return $result;
            }

            $extension = $segment->getExtensionAttributes() ?? $this->extensionFactory->create();
            // The extension class is generated at runtime from the base module's extension_attributes.xml.
            // @phpstan-ignore-next-line
            $extension->setDiscountLines($lines);
            $segment->setExtensionAttributes($extension);
            $segment->setTitle($this->title->title($lines));
        } catch (Throwable $exception) {
            $this->logger->error(
                'Discount lines: gift card lines could not be added to the cart totals: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }

        return $result;
    }

    /**
     * Convert the redeemed amount back to base currency; the quote does not persist the base amount.
     *
     * @param Quote $quote
     * @param float $amount
     * @return float
     */
    private function baseAmount(Quote $quote, float $amount): float
    {
        $rate = (float)$quote->getData('base_to_quote_rate');

        return $rate > 0.0 ? round($amount / $rate, 2) : $amount;
    }
}
