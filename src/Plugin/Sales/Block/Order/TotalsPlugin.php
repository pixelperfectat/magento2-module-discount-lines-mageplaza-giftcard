<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesMageplazaGiftCard\Plugin\Sales\Block\Order;

use Magento\Framework\DataObject;
use Magento\Sales\Block\Order\Totals;
use Magento\Sales\Model\AbstractModel;
use Magento\Sales\Model\Order;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLinesMageplazaGiftCard\Api\Service\GiftCardLinesInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Replaces Mageplaza's `gift_card` totals row (order, invoice, credit memo; storefront, email, admin) with one masked
 * row per card that carries the `discount_line` contract data.
 *
 * Only an existing row is rewritten, nothing is inserted, so the 'footer' area and the second admin call stay as
 * they are. The gift credit row is not a card and is left alone.
 */
class TotalsPlugin
{
    public const ROW_CODE = 'gift_card';

    /**
     * @param ConfigInterface $config
     * @param GiftCardLinesInterface $giftCardLines
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly GiftCardLinesInterface $giftCardLines,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Swap the gift card row for one masked row per card.
     *
     * @param Totals $subject
     * @param array<int|string, DataObject>|null $result Null while the block is not initialised
     * @param array<int|string, mixed>|string|null $area
     * @return array<int|string, DataObject>|null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetTotals(Totals $subject, $result, $area = null)
    {
        if (!is_array($result)) {
            return $result;
        }
        $row = $this->findRow($result);
        if ($row === null) {
            return $result;
        }

        try {
            /** @var AbstractModel|null $source Order, invoice or credit memo depending on the block subclass */
            $source = $subject->getSource();
            if ($source === null || !$this->config->isEnabled((int)$source->getStoreId())) {
                return $result;
            }
            $order = $source instanceof Order ? $source : $source->getOrder();
            $lines = $this->giftCardLines->build(
                (string)$order->getData('mp_gift_cards'),
                abs((float)$row->getData('value')),
                abs((float)$row->getData('base_value'))
            );
            if ($lines === []) {
                return $result;
            }

            return $this->rebuild($result, $this->buildRows($row, $lines));
        } catch (Throwable $exception) {
            $this->logger->error('Discount lines: gift card totals rendering failed, Mageplaza row kept.', [
                'exception' => $exception,
            ]);

            return $result;
        }
    }

    /**
     * Find the gift card row.
     *
     * @param array<int|string, DataObject> $totals
     * @return DataObject|null
     */
    private function findRow(array $totals): ?DataObject
    {
        foreach ($totals as $total) {
            if ($total->getData('code') === self::ROW_CODE) {
                return $total;
            }
        }

        return null;
    }

    /**
     * Build the masked rows from the Mageplaza row; the first keeps the Mageplaza row code.
     *
     * @param DataObject $original
     * @param DiscountLineInterface[] $lines
     * @return array<string, DataObject>
     */
    private function buildRows(DataObject $original, array $lines): array
    {
        // Mageplaza books the amounts negative on every document; keep whatever sign the row carries.
        // The lines exist only for a non-zero row value, so the sign is never 0.
        $sign = $original->getData('value') <=> 0.0;
        $rows = [];
        foreach (array_values($lines) as $position => $line) {
            $code = $position === 0 ? self::ROW_CODE : self::ROW_CODE . '_' . $position;
            $rows[$code] = new DataObject([
                ...$original->getData(),
                'code' => $code,
                'value' => $sign * $line->getAmount(),
                'base_value' => $sign * $line->getBaseAmount(),
                'label' => $line->getLabel(),
                'discount_line' => $line->toArray(),
            ]);
        }

        return $rows;
    }

    /**
     * Put the rows in place of the Mageplaza row.
     *
     * @param array<int|string, DataObject> $totals
     * @param array<string, DataObject> $rows
     * @return array<int|string, DataObject>
     */
    private function rebuild(array $totals, array $rows): array
    {
        $rebuilt = [];
        foreach ($totals as $key => $total) {
            if ($total->getData('code') === self::ROW_CODE) {
                $rebuilt += $rows;
                continue;
            }
            $rebuilt[$key] = $total;
        }

        return $rebuilt;
    }
}
