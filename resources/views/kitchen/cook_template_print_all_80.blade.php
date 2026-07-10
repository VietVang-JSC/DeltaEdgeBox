<!DOCTYPE html>
<html lang="en">

<head>
    <title>In Bếp</title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        body {
            font-family: 'notosansjp-regular', sans-serif !important;
            width: 80mm;
            margin: 0 auto;
        }

        @page {
            size: 80mm auto;
            margin: 0mm;
        }


        @media print {
            #print {
                line-height: 1.15;
                margin: 0;
            }

        }

        #print {
            font-size: 14pt;
            line-height: 1.15;
            margin: 0;
            background-color: #f9f9f9;
        }

        p {
            margin-bottom: 3mm;
        }

        .center {
            text-align: center;
        }

        .p-0 {
            padding: 0;
        }

        .m-0 {
            margin: 0;
        }

        .p-2 {
            padding: 0.5rem;
        }

        .d-none {
            display: none;
        }

        .m-0 {
            margin: 0;
        }

        .row {
            display: flex;
            flex-wrap: wrap;
        }

        .col {
            flex: 1 0 0%;
        }

        .col-auto {
            flex: 0 0 auto;
            width: auto;
        }

        .d-flex {
            /* display: flex; */
        }

        .flex-column {
            flex-direction: column;
        }

        .flex-grow-1 {
            flex-grow: 1;
        }

        .align-items-center {
            align-items: center;
        }

        .align-top {
            vertical-align: top;
        }

        .invoice {
            margin: 0;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }

        .invoice-header {
            margin-bottom: 0px;
            position: relative;
            text-align: center;
        }

        .invoice-header .logo {
            width: 120px;
        }

        .invoice-header .company-info {
            align-items: center;
            flex-direction: column;
            margin: auto;
            width: max-content;
        }

        .invoice-details {
            display: flex;
            flex-direction: column;
            padding: 0 20px;
        }

        .invoice-details .invoice-info {
            display: flex;
            flex-direction: column;
        }

        .invoice-details .customer-info {
            margin-bottom: 10px;
        }

        .invoice-items {
            width: 100%;
            margin-bottom: 13px;
            padding: 0 20px
        }

        .invoice-items td ,.invoice-items th{
            border-bottom: 1px dashed black;
            vertical-align: top;
        }

        .text-break-container {
            max-width: 100px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .dashed-line {
            border-top: 1px dashed black;
        }

        .Info_staff {
            display: flex;
            justify-content: space-between;
        }

        .Info_Total_Sub_name {}

        .invoice-items td {
            vertical-align: top;
        }

        .item-note {
            font-weight: bold;
        }

        .item-extra {
            font-weight: bold;

        }


        .invoice-details .invoice-info .left {
            font-size: 13pt;
        }

        .item-row {
            font-size: 16pt;
        }

        .table-name {
            font-size: 16pt
        }
        .table-name span{
            text-align: center;
        }

        .invoice-items th {
            font-size: 16pt;
        }

        .invoice-title {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            margin: 10px 0 10px 0;
        }

        .invoice-items th {
            font-size: 13pt;
            font-weight: normal;
        }
        .item-quantity, .title-quantity{
            text-align: right;
        }
        .title-dish{
            text-align: left;
        }
    </style>
</head>

<body>
    @php
        $paymentCodeExploded = explode('-', $payment['payment_code'] ?? '');
        $language = App::getLocale();
        $currentDate = $language == 'jp' ? now($timeZone ?? config('app.timezone'))->format('Y年m月d日 H:i:s') : now($timeZone ?? config('app.timezone'))->format('Y-m-d H:i:s');
    @endphp
    <div class="invoice" id="print">
        <div class="invoice-details">
            <div class="table-name">
                <span style="{{ \App\Helpers\SettingKitchenHelper::printStyle($setting_print_kitchen, 80, 'table') }}">{{ __('front/pos_order.kitchen_order_ticket.Tên bàn') }}:</span>
                <span style="{{ \App\Helpers\SettingKitchenHelper::printStyle($setting_print_kitchen, 80, 'table') }}" class="table_name">{{$payment['tablename']}}/</span>
                {{-- <span>NO.</span> --}}
                <span style="{{ \App\Helpers\SettingKitchenHelper::printStyle($setting_print_kitchen, 80, 'code') }}">NO.{{$paymentCodeExploded[1] ?? ''}}</span>
            </div>
            <div class="invoice-info">
                <div style="{{ \App\Helpers\SettingKitchenHelper::printStyle($setting_print_kitchen, 80, 'title') }}" class="invoice-title">{{ __('front/pos_order.kitchen_order_ticket.PHIẾU GỌI MÓN TỔNG') }}</div>
                <div class="left">
                    <div class="Info_staff row">
                        <label style="{{ \App\Helpers\SettingKitchenHelper::printStyle($setting_print_kitchen, 80, 'date') }}">{{ __('front/pos_order.kitchen_order_ticket.Ngày') }}:</label>
                        <label style="{{ \App\Helpers\SettingKitchenHelper::printStyle($setting_print_kitchen, 80, 'date') }}" class="current_date">{{ $currentDate }}</label>
                    </div>
                    <div class="Info_staff row">
                        <label style="{{ \App\Helpers\SettingKitchenHelper::printStyle($setting_print_kitchen, 80, 'staff') }}">{{ __('front/pos_order.kitchen_order_ticket.Nhân viên') }}:</label>
                        <label style="{{ \App\Helpers\SettingKitchenHelper::printStyle($setting_print_kitchen, 80, 'staff') }}" class="staff_name">{{ $payment['user']['name'] ?? '' }}</label>
                    </div>
                    {{-- <div class="Info_staff row">
                    <label>{{ __('front/pos_order.kitchen_order_ticket.Mã hóa đơn') }}:</label>
                    <label class="staff_name">{{$payment['payment_code'] ?? ''}}</label>
            </div> --}}
                    {{-- <div class="Info_staff row">
                    <label>{{ __('front/pos_order.kitchen_order_ticket.Tên bàn') }}:</label>
                    <label class="table_name">{{$payment['tablename']}}</label>
            </div> --}}
                </div>
            </div>
            <div class="dashed-line"></div>
        </div>
        <table class="invoice-items" aria-label="Danh sách món ăn">
            <thead>
                <tr>
                    <th style="{{ \App\Helpers\SettingKitchenHelper::printStyle($setting_print_kitchen, 80, 'header') }}" class="title-dish" style="width:75%;">{{ __('front/pos_order.kitchen_order_ticket.Món ăn') }}</th>
                    <th style="{{ \App\Helpers\SettingKitchenHelper::printStyle($setting_print_kitchen, 80, 'header') }}" class="title-quantity">{{ __('front/pos_order.kitchen_order_ticket.Số lượng') }}</th>
                    {{-- <th>{{ __('front/pos_order.kitchen_order_ticket.Ghi chú') }}</th> --}}
                </tr>
            </thead>
            <tbody id="itemPrint">
                @if(isset($payment['products']))
                    @foreach ($payment['products'] as $item)
                        @php
                            $note = '';
                            if(isset($item['note']) && $item['note'] !== null && $item['note'] != '') $note = $item['note'].',';
                            if(!empty($item['product_types'])){
                                foreach ($item['product_types'] as $index => $product_type_item) {
                                    $note .= $product_type_item['productTypeValue'];
                                    if ($index < count($item['product_types']) - 1) {
                                        $note .= ',';
                                    }
                                }
                            }
                            else{
                                $note = rtrim($note, ',');
                            }
                        @endphp
                        <tr class="item-row">
                            <td class="d-flex flex-column">
                                <div class="item-title" style="{{ \App\Helpers\SettingKitchenHelper::printStyle($setting_print_kitchen, 80, 'body') }}">
                                    <label>{{$item['title']}}</label>
                                    <div class="item-extra">
                                        @if(!empty($item['extra_product_list']))
                                            @foreach ($item['extra_product_list'] as $index => $extra_product_item)
                                                <label>+{{$extra_product_item['title']}}</label>
                                            @endforeach 
                                        @endif
                                        
                                        @if(!empty($item['combo_products']))
                                            @foreach ($item['combo_products'] as $index => $combo_product_item)
                                                @if ($combo_product_item['is_required'] == 1)
                                                <div class="d-flex align-items-center">
                                                    <label class="col-3">{{$combo_product_item['quantity']}}</label>
                                                    <label class="flex-grow-1">{{$combo_product_item['product']['title']}}</label>
                                                </div>
                                                @endif
                                                
                                            @endforeach 
                                        @endif

                                        @if(!empty($item['optional_products']))
                                            @foreach ($item['optional_products'] as $index => $optional_products_item)
                                                <div class="d-flex align-items-center">
                                                    <label class="col-3">{{$optional_products_item['quantity']}}</label>
                                                    <label class="flex-grow-1">{{$optional_products_item['product']['title']}}</label>
                                                </div>
                                            @endforeach 
                                        @endif
                                    </div>
                                </div>
                                @if (!empty($note) && $note !== null && $note != '')
                                    <div class="item-note">
                                        {{ __('front/pos_order.kitchen_order_ticket.Ghi chú') }}: {{$note ?? ''}}
                                    </div>
                                @endif
                            </td>
                            <td class="align-top item-quantity">
                                {{ intval($item['quantity'])}}
                            </td>
                            {{-- <td class="align-top">{{$note ?? ''}}</td> --}}
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
</body>

</html>
