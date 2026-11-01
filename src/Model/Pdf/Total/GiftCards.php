<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Model\Pdf\Total;

use Magento\Sales\Model\Order\Pdf\Total\DefaultTotal;
use Magento\Tax\Helper\Data;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\ResourceModel\Sales\Order\Tax\CollectionFactory;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Api\Service\GiftCardLinesInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PDF gift card total: one entry per redeemed card with its masked label, the Mageplaza entry otherwise.
 */
class GiftCards extends DefaultTotal
{
    /**
     * @param Data $taxHelper
     * @param Calculation $taxCalculation
     * @param CollectionFactory $ordersFactory
     * @param ConfigInterface $config
     * @param GiftCardLinesInterface $giftCardLines
     * @param LoggerInterface $logger
     * @param array<string, mixed> $data
     */
    public function __construct(
        Data $taxHelper,
        Calculation $taxCalculation,
        CollectionFactory $ordersFactory,
        private readonly ConfigInterface $config,
        private readonly GiftCardLinesInterface $giftCardLines,
        private readonly LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($taxHelper, $taxCalculation, $ordersFactory, $data);
    }

    /**
     * @inheritDoc
     *
     * @return array<int, array{amount: string, label: string, font_size: int}>
     */
    public function getTotalsForDisplay()
    {
        try {
            $source = $this->getSource();
            if ($this->config->isEnabled((int)$source->getStoreId())) {
                $amount = (float)$this->getAmount();
                $lines = $this->giftCardLines->build(
                    (string)$this->getOrder()->getData('mp_gift_cards'),
                    abs($amount),
                    abs((float)$source->getData('base_gift_card_amount'))
                );
                if ($lines !== []) {
                    // The lines exist only for a non-zero amount, so the sign is never 0.
                    $sign = $amount <=> 0.0;
                    $fontSize = $this->getFontSize() ? $this->getFontSize() : 7;
                    $entries = [];
                    foreach ($lines as $line) {
                        $entries[] = [
                            'amount' => $this->getOrder()->formatPriceTxt($sign * $line->getAmount()),
                            'label' => $line->getLabel() . ':',
                            'font_size' => $fontSize,
                        ];
                    }

                    return $entries;
                }
            }
        } catch (Throwable $exception) {
            $this->logger->error('Discount lines: gift card PDF total failed, Mageplaza entry kept.', [
                'exception' => $exception,
            ]);
        }

        return parent::getTotalsForDisplay();
    }
}
