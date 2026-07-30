<?php

namespace App\Services;

class ReceiptItemPricing
{
    public static function resolve(
        array $item,
        bool $isTaxIncluded,
        float $seniorDiscount = 0,
        ?float $legacyLineTotal = null
    ): array {
        $quantity = (float) ($item['quantity'] ?? 0);
        $totalPrice = (float) ($item['total_price'] ?? 0);
        $vatRate = (float) ($item['products']['vat'] ?? 0) / 100;
        $unitPriceExcludingTax = $item['unit_price_excluding_tax'] ?? null;
        $taxAmount = $item['tax_amount'] ?? null;
        $hasPersistedTaxFields = $unitPriceExcludingTax !== null
            && $taxAmount !== null
            && ((float) $unitPriceExcludingTax > 0 || $totalPrice === 0.0);

        if ($hasPersistedTaxFields) {
            $unitPrice = (float) $unitPriceExcludingTax;
            $lineTotal = $unitPrice * $quantity;
        } elseif ($legacyLineTotal !== null) {
            $lineTotal = $legacyLineTotal;
            $unitPrice = $quantity > 0 ? $lineTotal / $quantity : 0;
        } else {
            $lineTotal = $isTaxIncluded && $seniorDiscount <= 0
                ? $totalPrice
                : round($totalPrice / (1 + $vatRate));
            $unitPrice = $quantity > 0 ? $lineTotal / $quantity : 0;
        }

        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'tax_amount' => (float) ($taxAmount ?? 0),
            'used_persisted_tax_fields' => $hasPersistedTaxFields,
        ];
    }
}
