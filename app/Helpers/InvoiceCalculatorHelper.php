<?php

namespace App\Helpers;

class InvoiceCalculatorHelper
{
    /**
     * @param array $items Array of items (with price, quantity, vat, etc.)
     * @param bool $isTaxIncluded
     * @param string $timeZone
     * @param float $discountValue
     * @param string $typeDiscount 'amount' or 'percent'
     * @param float $serviceChargeRate
     * @param float|null $surchargePercent
     * @param float $surchargeAmount
     * @param bool $isSenior
     * @param float $seniorRate Default 20
     * @return array
     */
    public static function calculateInvoiceTotals(
        array $items,
        bool $isTaxIncluded,
        string $timeZone,
        float $discountValue,
        string $typeDiscount,
        float $serviceChargeRate,
        ?float $surchargePercent,
        float $surchargeAmount,
        bool $isSenior,
        float $seniorRate = 20
    ) {
        $billItem = $isTaxIncluded 
            ? self::allocateDiscountTaxIncluded($items, $discountValue, $typeDiscount) 
            : self::allocateDiscountTaxExcluded($items, $discountValue, $typeDiscount);
            
        $totalTax  = $billItem['summary']['total_vat'] ?? 0;
        $subTotalBefore = round($billItem['summary']['subtotal_before'] ?? 0);
        $discountAmount = $billItem['summary']['discount_total'] ?? 0;
        $totalInclVatBeforeDiscount = $billItem['summary']['total_incl_vat_before_discount'] ?? 0;
        
        // Base logic without Manila exceptions
        $valuetotal = round(($billItem['summary']['total_with_vat'] ?? 0) + $surchargeAmount);
        $serviceChargeAmount = 0;
        $seniorDiscountAmount = 0;
        
        if ($timeZone === 'Asia/Manila') {
            $baseForSurcharge = $isTaxIncluded
                ? ($billItem['summary']['total_with_vat'] ?? 0)
                : ($billItem['summary']['subtotal_after'] ?? ($billItem['summary']['total_with_vat'] ?? 0));

            if ($surchargePercent !== null) {
                $surchargeAmount = round($baseForSurcharge * $surchargePercent / 100);
            }

            $serviceChargeAmount = round($baseForSurcharge * $serviceChargeRate / 100);
            $valuetotal = round(($billItem['summary']['total_with_vat'] ?? 0) + $serviceChargeAmount + $surchargeAmount);

            // Senior Discount (RA 9994) — tính TRƯỚC discount
            if ($isSenior) {
                $seniorDiscountAmount = round($subTotalBefore * $seniorRate / 100);
                $afterSenior = $subTotalBefore - $seniorDiscountAmount;

                // Recalculate discount on afterSenior (RA 9994: discount applies after senior)
                if ($typeDiscount === 'percent') {
                    $billItem['summary']['discount_total'] = round($afterSenior * $discountValue / 100);
                }
                $discountAmount = $billItem['summary']['discount_total'];
                $totalAfterDiscount = $afterSenior - $discountAmount;
                
                $totalTax = 0; // VAT exempt

                $serviceChargeAmount = round(max(0, $totalAfterDiscount) * $serviceChargeRate / 100);

                if ($surchargePercent !== null) {
                    $surchargeAmount = round(max(0, $totalAfterDiscount) * $surchargePercent / 100);
                }

                $valuetotal = round(max(0, $totalAfterDiscount) + $serviceChargeAmount + $surchargeAmount);
            }
        }
        
        return [
            'items' => $billItem['items'] ?? [],
            'total_tax' => $totalTax,
            'sub_total_before_discount' => $subTotalBefore,
            'discount' => $discountAmount,
            'total_incl_vat_before_discount' => $totalInclVatBeforeDiscount,
            'surcharge' => $surchargeAmount,
            'service_charge_amount' => $serviceChargeAmount,
            'senior_discount_amount' => $seniorDiscountAmount,
            'valuetotal' => $valuetotal,
        ];
    }

    public static function allocateDiscountTaxExcluded(array $items, float $discountValue, string $type_discount = 'amount'): array
    {
        //Tính tổng thành tiền (chưa VAT)
        $totalBase = 0;
        foreach ($items as $item) {
            $totalBase += $item['price'] * $item['quantity'];
        }

        if ($totalBase <= 0) {
            return ['items' => [], 'summary' => []];
        }

        $allocatedSum = 0;
        $lastKey = array_key_last($items);

        //Duyệt qua từng món để tính giảm
        foreach ($items as $key => &$item) {
            $subTotal = $item['price'] * $item['quantity'];
            $item['sub_total_excl_vat'] = round($subTotal);

            if ($type_discount === 'percent') {
                //Giảm theo phần trăm
                $item['discount_percent'] = $discountValue;
                $item['discount_allocated_excl_vat'] = round($subTotal * $discountValue / 100);
            } else {
                //Giảm theo số tiền (phân bổ tỷ lệ)
                $ratio = $subTotal / $totalBase;

                if ($key !== $lastKey) {
                    $item['discount_allocated_excl_vat'] = round($discountValue * $ratio);
                    $allocatedSum += $item['discount_allocated_excl_vat'];
                } else {
                    $item['discount_allocated_excl_vat'] = $discountValue - $allocatedSum;
                }
            }


            // Tính giá sau giảm
            $item['net_excl_vat'] = $item['sub_total_excl_vat'] - $item['discount_allocated_excl_vat'];

            // VAT
            $item['tax_amount'] = round($item['net_excl_vat'] * ($item['vat'] / 100));

            // Tổng sau giảm (đã VAT)
            $item['total_with_vat_after_discount'] = $item['net_excl_vat'] + $item['tax_amount'];

            $item['detail_discount'] = $item['discount_allocated_excl_vat'];
            $item['TotalPrice'] = round($item['sub_total_excl_vat'] * (1 + $item['vat'] / 100));
            $item['detail_discount_excluding_tax'] = $item['discount_allocated_excl_vat'];
            $item['unit_price_excluding_tax'] = $item['price'];
            $item['discounted_price_excluding_tax'] = $item['net_excl_vat'];

        }
        unset($item);

        // Tổng hợp kết quả
        $summary = [
            'subtotal_before' => array_sum(array_column($items, 'sub_total_excl_vat')),
            'discount_total'  => ($type_discount === 'percent')
                ? round($totalBase * $discountValue / 100)
                : $discountValue,
            'subtotal_after'  => array_sum(array_column($items, 'net_excl_vat')),
            'total_vat'       => array_sum(array_column($items, 'tax_amount')),
            'total_with_vat'  => array_sum(array_column($items, 'total_with_vat_after_discount')),
            'total_incl_vat_before_discount'  => array_sum(array_column($items, 'TotalPrice')),
        ];

        return ['items' => $items, 'summary' => $summary];
    }

    public static function allocateDiscountTaxIncluded(array $items, float $discountValue, string $type_discount = 'amount'): array
    {
        //Tính tổng thành tiền (đã bao gồm VAT)
        $totalWithVat = 0;
        foreach ($items as $item) {
            $totalWithVat += $item['price'] * $item['quantity'];
        }

        if ($totalWithVat <= 0) {
            return ['items' => [], 'summary' => []];
        }

        $allocatedSum = 0;
        $lastKey = array_key_last($items);

        //Duyệt từng sản phẩm
        foreach ($items as $key => &$item) {
            $subTotalInclVat = $item['price'] * $item['quantity'];
            $item['sub_total_incl_vat'] = round($subTotalInclVat);

            if ($type_discount === 'percent') {
                // Giảm theo phần trăm
                $item['discount_percent'] = $discountValue;
                $item['discount_allocated_incl_vat'] = round($subTotalInclVat * $discountValue / 100);
            } else {
                //Giảm theo số tiền cố định (phân bổ theo tỷ lệ)
                $ratio = $subTotalInclVat / $totalWithVat;

                if ($key !== $lastKey) {
                    $item['discount_allocated_incl_vat'] = round($discountValue * $ratio);
                    $allocatedSum += $item['discount_allocated_incl_vat'];
                } else {
                    // Món cuối nhận phần còn lại để tránh sai số làm tròn
                    $item['discount_allocated_incl_vat'] = $discountValue - $allocatedSum;
                }
            }
            
            $item['unit_price_excluding_tax'] = round($item['price'] / (1 + $item['vat'] / 100));


            $item['discount_allocated_excl_vat'] = round(
                $item['discount_allocated_incl_vat'] / (1 + $item['vat'] / 100)
            );

            // Sau giảm (vẫn là giá đã có VAT)
            $item['total_with_vat_after_discount'] = $item['sub_total_incl_vat'] - $item['discount_allocated_incl_vat'];

            //Tách phần chưa VAT & VAT
            $item['net_excl_vat'] = round($item['total_with_vat_after_discount'] / (1 + $item['vat'] / 100));
            $item['tax_amount'] = $item['total_with_vat_after_discount'] - $item['net_excl_vat'];

            $item['detail_discount'] = $item['discount_allocated_incl_vat'];
            $item['TotalPrice'] = $item['sub_total_incl_vat'];
            $item['detail_discount_excluding_tax'] = $item['discount_allocated_excl_vat'];
            $item['discounted_price_excluding_tax'] = $item['net_excl_vat'];
        }
        unset($item);

        //Tổng hợp kết quả
        $summary = [
            'subtotal_before' => array_sum(array_map(fn($i) => $i['sub_total_incl_vat'] / (1 + $i['vat'] / 100), $items)),
            'discount_total'  => ($type_discount === 'percent')
                ? round($totalWithVat * $discountValue / 100)
                : $discountValue,
            'subtotal_after'  => array_sum(array_column($items, 'net_excl_vat')),
            'total_vat'       => array_sum(array_column($items, 'tax_amount')),
            'total_with_vat'  => array_sum(array_column($items, 'total_with_vat_after_discount')),
            'total_incl_vat_before_discount'  => array_sum(array_column($items, 'TotalPrice')),
        ];

        return ['items' => $items, 'summary' => $summary];
    }
}
