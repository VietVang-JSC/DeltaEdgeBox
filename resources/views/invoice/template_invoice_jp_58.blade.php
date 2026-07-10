<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Document</title>
    <style>
        @page { size: auto; margin: 0mm; }

        @font-face {
            font-family: 'NotoSerifJP-SemiBold';
            src: url("{{ str_replace('\\', '/', storage_path('fonts/NotoSerifJP-SemiBold.ttf')) }}") format('truetype');
        }

        @font-face {
            font-family: 'NotoSansJP-Bold';
            src: url("{{ str_replace('\\', '/', storage_path('fonts/NotoSansJP-Bold.ttf')) }}") format('truetype');
        }

        @font-face {
            font-family: 'notosansjp-regular';
            src: url("{{ str_replace('\\', '/', storage_path('fonts/NotoSansJP-Regular.ttf')) }}") format('truetype');
        }

        body {
            font-family: 'notosansjp-regular', sans-serif;
            background-color: #f9f9f9;
            margin: 0;
            padding: 0;
            text-align: center; /* Căn giữa nội dung */
            font-size: 16px
        }

        .receipt {
            margin: 0 auto;
            background: #fff;
            text-align: left;
        }

        .receipt-header {
            text-align: center;
        }

        .receipt-header h1 {
            margin: 0;
        }

        .receipt-header p {
            margin-bottom: 0;
        }

        .receipt-date, .receipt-table, .receipt-customer {
        }

        .receipt-items {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .receipt-items th:nth-child(1), .receipt-items td:nth-child(1) { width: 55%; text-align: left; }
        .receipt-items th:nth-child(2), .receipt-items td:nth-child(2) { width: 15%; text-align: right; }
        .receipt-items th:nth-child(3), .receipt-items td:nth-child(3) { width: 30%; text-align: right; }

        .receipt-items th, .receipt-items td {
            border-bottom: 1px dashed black;
        }
        
        .info-table {
            font-size: 10pt;
            width: 100%;
            border-collapse: collapse;
        }
        .info-table td {
            vertical-align: top;
        }
        .info_label {
            text-align: left;
            font-weight: bold;
        }
        .Info_staff_input {
            text-align: right;
        }
        .invoice-title {
            display: block;
            text-align: center;
            margin-bottom: 10px;
            font-weight: bold;
            font-size: 11pt;
        }
        .invoice-details {
            display: flex;
            flex-direction: column; 
            margin-bottom: 10px;
        }

        .payment .item {
            width: 100%;
            overflow: hidden;
        }

        .payment .item span:first-child {
            float: left;
            text-align: left;
        }

        .payment .item span:last-child {
            text-align: right;
        }

        .total {
            font-weight: normal;
        }

        .dashed-line {
            border-top: 1px dashed black;
            margin: 15px 0;
            clear: both; /* Đảm bảo không bị trôi */
        }

        .d-none {
            display: none;
        }

        .tax-info {
        }

        .tax-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tax-table td {
            /* vertical-align: top; */
        }

        .tax-table .tax-rate {
            width: 40%; /* Tỷ lệ tương tự như float trước đây */
            text-align: left;
        }

        .tax-table .item-price-info {
            width: 60%;
            text-align: right;
        }
        .font-size-head{
            font-size: 11pt;
            /* font-family: 'NotoSerifJP-Bold'; */
            font-weight: bold;
        }
        .font-size-info{
            font-size: 10pt;
            font-weight: bold;
        }
        .font-size-tr{
            font-size: 10pt;
        }

        .receipt-items th {
            border-bottom: 1px dashed black;
            font-size: 10pt;
            font-weight: bold;
            
        }
        .font-size-invoice-tr{
            font-size: 10pt;
        }

        .invoice-footer {
            font-size: 11pt;
            text-align: center;
            font-weight: bold;
        }

        /* .payment {
            text-align: right;
            font-size: 15pt;
        } */

         .payment {
            font-size: 11pt;
             text-align: right;
            border-collapse: collapse;
            margin-top: 10px;
            width: 100%;
        }
        .font-weight-nomarl{
            font-weight: normal;
        }
        .text-break-container {
            max-width: 85px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .txt-center{
            text-align: center !important;
        }
        .txt-right{
            text-align: right !important;
        }
        .txt-left{
            text-align: left !important;
        }
    </style>
</head>
<body>
    @php
        $logo = !empty($bill_setting) ? ($bill_setting['logo'] ?? '' ) : '';
        $store_name = !empty($bill_setting) ? ($bill_setting['store_name'] ?? '' ) : '';
        $address = !empty($bill_setting) ? ($bill_setting['store_address'] ?? '' ) : '';
        $phone = !empty($bill_setting) ? ($bill_setting['store_phone'] ?? '' ) : '';
        $discount = $payment['discount'] ?? 0;
        $surcharge = $payment['surcharge'] ?? 0;
        $seniorDiscount = $payment['senior_discount_amount'] ?? 0;
        $total = $payment['valuetotal'];
        $amount_received = $payment['amount_received'] ?? 0;
        $change = $amount_received > $total ? $amount_received - $total : 0;
        $taxArray = [];
        $currencyRaw = strtoupper($payment['store']['currency'] ?? 'VND');
        $currencySymbols = [
            'JPY' => '¥', 'USD' => '$', 'EUR' => '€',
            'GBP' => '£', 'VND' => '₫', 'PHP' => '₱',
        ];
        $symbol = $currencySymbols[$currencyRaw] ?? '';
        $currencySymbol = '<span class="currency-symbol">' . $symbol . '</span>';
    @endphp
    <div class="receipt" id="print">
        <div class="receipt-header">
            <div class="font-size-head">
                {{ $store_name }}
            </div>

            @if (!empty($address))
                <div class="font-size-head">
                    {{ $address }}
                </div>
            @endif

            @if (!empty($phone) && $payment['status'] == 1)
                <div class="font-size-head">
                    <label style="margin: 0;">
                        <label>ホットライン：</label>
                        <label id="store_phone">{{ $phone }}</label>
                    </label>
                </div>
            @endif

        </div>
        
        @php
            $titleHeader = $payment['status'] == 1 ? 'レシート' : '請求書';
        @endphp
        
        <div class="invoice-details">
            <span class="invoice-title">{{ $titleHeader }}</span>
            <table class="info-table" aria-label="請求書情報">
                <tr>
                    <th class="info_label">{{ __('front/pos_order.invoices.Ngày') }}:</th>
                    <td class="Info_staff_input current_date">{{ $payment['updated_at'] ?? $payment['created_at'] ?? '' }}</td>
                </tr>
                <tr>
                    <th class="info_label">{{ __('front/pos_order.invoices.Nhân viên') }}:</th>
                    <td class="Info_staff_input staff_name">{{ $payment['user']['name'] ?? '' }}</td>
                </tr>
                <tr>
                    <th class="info_label">{{ __('front/pos_order.invoices.Mã hóa đơn') }}:</th>
                    <td class="Info_staff_input payment_id">{{ $payment['payment_code'] ?? '' }}</td>
                </tr>
                @if (!empty($payment['table']['tablename']))
                    <tr>
                        <th class="info_label">{{ __('front/pos_order.invoices.Bàn') }}:</th>
                        <td class="Info_staff_input table_name">{{ $payment['table']['tablename'] }}</td>
                    </tr>
                @endif
                
                @php
                    $isUnpaid = $payment['status'] == 0;
                @endphp
                
                @if ($isUnpaid && !empty($payment['table']['number_of_people']))
                    <tr>
                        <th class="info_label">{{ __('front/pos_order.invoices.Số khách') }}:</th>
                        <td class="Info_staff_input number_of_people">{{ $payment['table']['number_of_people'] }}</td>
                    </tr>
                @elseif (!empty($payment['number_of_people']))
                    <tr>
                        <th class="info_label">{{ __('front/pos_order.invoices.Số khách') }}:</th>
                        <td class="Info_staff_input number_of_people">{{ $payment['number_of_people'] }}</td>
                    </tr>
                @endif
            </table>
        </div>
        
        <div class="dashed-line"></div>

        <table class="receipt-items" aria-label="品目リスト">
            <thead>
                <tr>
                    <th>{{__('front/pos_order.invoices.Sản phẩm')}}</th>
                    <th class="txt-right">{{__('front/pos_order.invoices.Số lượng')}}</th>
                    <th class="txt-right">{{__('front/pos_order.invoices.Tổng Tiền')}}</th>
                </tr>
            </thead>
            <tbody>
                @if (!empty($payment['payment_details']))
                    @php
                        $taxArray = [];
                        $checkTaxNote = false;
                        // $allVatRates = [];

                        // foreach ($payment['payment_details'] as $item) {
                        //     $allVatRates[] = $item['products']['vat'];
                        // }

                        // $maxVat = max($allVatRates);
                    @endphp

                    @foreach ($payment['payment_details'] as $item)
                        @php
                            $tax = $item['products']['vat'];
                            if(array_key_exists($tax, $taxArray)){
                                $taxArray[$tax]['total_price'] += $item['total_price'];
                                $taxArray[$tax]['tax_amount'] += $item['tax_amount'];
                            } else {
                                $taxArray[$tax]['total_price'] = $item['total_price'];
                                $taxArray[$tax]['tax_amount'] = $item['tax_amount'];
                            }
                        @endphp
                        <tr class="font-size-tr">
                            <td class="text-break-container">
                                {{ $item['products']['title'] }} {{ $taxNote }}
                                @if(!empty($item['note']))
                                    <div>{{ $item['note'] }}</div>
                                @endif
                            </td>
                            <td class="txt-right">{{ number_format($item['quantity']) }}</td>
                            <td class="txt-right">¥{{ number_format($item['total_price']) }}</td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>

        <div class="tax-info">
            @if (!empty($taxArray))
                <table class="tax-table" aria-label="税金情報" role="presentation">
                    <tbody>
                        @foreach ($taxArray as $key => $item)
                            {{-- @php
                                $priceWithTax = $item;
                                $taxRate = $key / 100;
                                $tax = $priceWithTax * ($taxRate / (1 + $taxRate));
                                $tax = round($tax);
                            @endphp --}}
                            <tr class="font-size-invoice-tr">
                                <td class="tax-rate">{{ $key }}%対象</td>
                                <td class="item-price-info">
                                    <label class="price">¥{{ number_format($item['total_price']) }}</label>
                                </td>
                            </tr>
                            <tr class="font-size-invoice-tr">
                                <td class="txt-right" colspan="2">
                                    <label class="vat-amount">(内 消費税額 ¥{{ number_format($item['tax_amount']) }})</label>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @if (isset($checkTaxNote) && $checkTaxNote)
                <div class="font-size-invoice-tr">
                    ※軽減税率対象
                </div>
            @endif
        </div>

        <table class="payment" width="100%" aria-label="支払い情報" role="presentation">
            @if (!empty($discount))
                <tr class="item">
                    <td class="font-weight-nomarl txt-left">割引</td>
                    <td class="txt-right">¥{{ number_format($discount) }}</td>
                </tr>
            @endif
            @if (($seniorDiscount ?? 0) > 0)
                <tr class="item">
                    <td class="font-weight-nomarl txt-left">シニア割引 20%</td>
                    <td class="txt-right">¥{{ number_format($seniorDiscount) }}</td>
                </tr>
            @endif
            @if(($payment['service_charge_amount'] ?? 0) > 0 || ($payment['store']['time_zone'] ?? '') == 'Asia/Manila')
            <tr class="item">
                <td class="font-weight-nomarl txt-left">サービス料</td>
                <td class="txt-right">{!! isset($currencySymbol) ? $currencySymbol : '¥' !!}{{ number_format($payment['service_charge_amount'] ?? 0) }}</td>
            </tr>
            @endif
            @if (!empty($surcharge))
                <tr class="item">
                    <td class="font-weight-nomarl txt-left">割増</td>
                    <td class="txt-right">¥{{ number_format($surcharge) }}</td>
                </tr>
            @endif
            <tr class="item">
                <td class="font-weight-nomarl txt-left">{{ isset($isUnpaid) && $isUnpaid == true ? 'ご請求額' : '合計' }}</td>
                <td class="txt-right">¥{{ number_format($total) }}</td>
            </tr>
            @if (!empty($change))
                <tr class="item">
                    <td class="font-weight-nomarl txt-left">お預かり</td>
                    <td class="txt-right">¥{{ number_format($amount_received) }}</td>
                </tr>
            @endif
            @if (!empty($change))
                <tr class="item">
                    <td class="font-weight-nomarl txt-left">お釣り</td>
                    <td class="txt-right">¥{{ number_format($change) }}</td>
                </tr>
            @endif
        </table>

        <div class="dashed-line"></div>
        <div class="invoice-footer">
            @if (!empty($bill_setting) && !empty($bill_setting['footer_content']))
                <span id="footer_content">{{ $bill_setting['footer_content'] }}</span><br>
            @endif
            @if (!empty($bill_setting) && !empty($bill_setting['wifi_information']))
                <span id="wifi_information">{{ $bill_setting['wifi_information'] }}</span> <br>
            @endif
            <span>Powered by Delta Pos</span>
        </div>
    </div>
</body>
</html>
