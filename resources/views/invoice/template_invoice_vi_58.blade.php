<!DOCTYPE html>
<html lang="en">
  <head>
    <title>Document</title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
    @page {
        size: 58mm auto;
        margin: 0;
    }
    
    body {
        font-family: DejaVu Sans !important;
        padding: 10px;
        background-color: #f9f9f9;
        width: 58mm;
        margin: 0 auto;
    }

    .p-0{
        padding: 0;
    }
    .m-0{
        margin: 0;
    }
    .p-2{
        padding: 0.5rem;
    }
    .d-none{
        display: none;
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
        font-weight: bold;
    }

    .invoice-details {
        display: flex;
        flex-direction: column; 
        /* margin-bottom: 10px; */
    }
    .invoice-details .invoice-info {
        display: flex;
        flex-direction: column;
    }
    .invoice-details .invoice-info .left{
    }
    .invoice-details .customer-info {
        margin-bottom: 10px;
    }
    .invoice-items {
        width: 100%;
        border-collapse: collapse;
    }
    .invoice-items th,
    .invoice-items td {
        border-bottom: 1px dashed black;
        text-align: left;
    }
    .invoice-items td{
        font-weight: normal;
    }
    .text-break-container {
        max-width: 100px;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    .invoice-total {
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .items-result {
        display: flex;
        justify-content: flex-start;
        flex-direction: column;
    }
    .items-info {
        display: flex;
        justify-content: flex-start;
        flex-direction: column;
    }

    .dashed-line {
        border-top: 1px dashed black;
    }
    .footer {
        text-align: center;
        margin-top: 50px;
    }
    
    .Info_Total_Sub {
        padding: 0;
    }
    .image_header {
        width: 100%;
        height: 50px;
        object-fit: contain;
    }
    .info-table {
        font-size: 14px;
        width: 100%;
        border-collapse: collapse;
    }

    .Info_Total_Sub_name {
        text-align: left;
        font-weight: bold;
    }

    .Info_Total_Sub_number {
        text-align: right;
    }
    .company-info {
        text-align: center;
        margin-bottom: 10px;
    }

    .company-info h3 {
        margin: 0;
        padding: 0;
    }

    .store-detail {
        display: block;
    }

    .d-none {
        display: none;
    }

    .invoice-info {
        text-align: left;
        margin-bottom: 10px;
    }

    .invoice-title {
        display: block;
        text-align: center;
        margin-bottom: 10px;
    }

    .info-table td {
        vertical-align: top;
    }

    .Info_staff_input {
        text-align: right;
    }
    .info_label{
        text-align: left;
        font-weight: bold;
    }

    #itemPrint {
        font-size: 15px;
    }

    .txt-right{
        text-align: right !important;
    }
    .txt-left{
        text-align: left !important;
    }
    .txt-center{
        text-align: center !important;
    }
   
    </style>
  </head>
  <body>
    <div class="invoice mt-0" id="print">
        @php
            $logo = !empty($bill_setting) ? ($bill_setting['logo'] ?? null ) : null;
            $store_name = !empty($bill_setting) ? ($bill_setting['store_name'] ?? null ) : null;
            $address = !empty($bill_setting) ? ($bill_setting['store_address'] ?? null ) : null;
            $phone = !empty($bill_setting) ? ($bill_setting['store_phone'] ?? null ) : null;
            $table_name = $table_name ?? null;
            $titleHeader = $payment['status'] == 1 ?  __('front/pos_order.invoices.HÓA ĐƠN THANH TOÁN') :  __('front/pos_order.invoices.HÓA ĐƠN TẠM TÍNH');
        @endphp
        <div class="invoice-header">
            @if (!empty($logo))
            <img class="logo" src="{{ $logo }}" alt="Logo">
            @endif
            <div class="company-info">
                <div id="store_name" class=" {{ empty($store_name) ? 'd-none' : '' }}">{{ $store_name }}</div>
                <div id="store_address" class="store-detail {{ empty($address) ? 'd-none' : '' }}">{{ $address }}</div>
                @if(!empty($phone))
                    <div id="store_phone_wrapper" class="store-detail">
                        Hotline:<span id="store_phone">{{ $phone }}</span>
                    </div>
                @endif
            </div>
        </div>
        <div class="invoice-details">
            <div class="invoice-info">
                <span class="invoice-title">{{ $titleHeader }}</span>
                <table class="info-table" aria-label="Thông tin hóa đơn">
                    <tr> 
                        <th class="info_label">{{ __('front/pos_order.invoices.Ngày') }}:</th>
                        <td class="Info_staff_input current_date">{{ $payment['created_at'] ?? '' }}</td>
                    </tr>
                    <tr>
                        <th class="info_label">{{ __('front/pos_order.invoices.Nhân viên') }}:</th>
                        <td class="Info_staff_input staff_name">{{ $payment['user']['name'] ?? '' }}</td>
                    </tr>
                    <tr>
                        <th class="info_label">{{ __('front/pos_order.invoices.Mã hóa đơn') }}:</th>
                        <td class="Info_staff_input payment_id">{{ $payment['payment_code'] ?? '' }}</td>
                    </tr>
                    @if (!empty($payment['user']['industries']) && $payment['user']['industries']['type'] == 2 && !empty($table_name))
                    <tr>
                        <th class="info_label">{{ __('front/pos_order.invoices.Bàn') }}:</th>
                        <td class="Info_staff_input table_name">{{ $table_name }}</td>
                    </tr>
                    @endif
                    @if (!empty($payment['table']['number_of_people']))
                        <tr>
                            <th class="info_label">{{ __('front/pos_order.invoices.Số khách') }}:</th>
                            <td class="Info_staff_input number_of_people">{{$payment['table']['number_of_people']}}</td>
                        </tr>
                    @endif
                   
                </table>
            </div>
            
        </div>
        <div class="dashed-line"></div>
        <table class="invoice-items" aria-label="Danh sách sản phẩm">
            <thead>
                <tr>
                    <th>{{__('front/pos_order.invoices.Sản phẩm')}}</th>
                    <th>{{__('front/pos_order.invoices.Đơn giá')}}</th>
                    <th>{{__('front/pos_order.invoices.Số lượng')}}</th>
                    <th class="txt-right">{{__('front/pos_order.invoices.Tổng Tiền')}}</th>
                </tr>
            </thead>
            <tbody id="itemPrint">
            @if (isset($payment))
                @php
                    $totalProductQuantity = array_sum(array_column($payment['payment_details'], 'quantity'));
                    $surcharge = $payment['surcharge'] ?? 0;
                    $discount = $payment['discount'] ?? 0;
                    $seniorDiscount = $payment['senior_discount_amount'] ?? 0;
                    $totalTax = $payment['total_tax'] ?? 0;
                    $valueTotal = $payment['valuetotal'] ?? 0;
                    $amount_received = $payment['amount_received'] ?? 0;
                    $subTotal = 0;
                    foreach ($payment['payment_details'] as $item) {
                        $itemVat = ($item['products']['vat'] ?? 0) / 100;
                        $subTotal += $is_tax_included == 1 ? $item['total_price'] : round($item['total_price'] / (1 + $itemVat));
                    }
                    if($discount > 0 || $seniorDiscount > 0){
                        $subTotalAfterDiscount = $subTotal - $discount - $seniorDiscount;
                    }
                @endphp
                @foreach ($payment['payment_details'] as $item)

                    @php
                        $productExtra = [];
                        $itemVat = $item['products']['vat']/100;
                        $totalItemPrice = $is_tax_included == 1 ? $item['total_price'] : round($item['total_price']/(1+$itemVat));
                        $itemQuantity = $item['quantity'];
                        $itemPrice = $itemQuantity > 0 ? $totalItemPrice / $itemQuantity : 0;
                        if(!empty($item['product_extra'])){
                            $productExtra = json_decode($item['product_extra'], true);
                        }
                        $productTile = $item['products']['title'] ?? '';
                    @endphp

                    <tr class="align-top">
                    <td class="text-break-container">
                        {{$productTile}}
                        @if(!empty($productExtra))
                            @foreach ($productExtra as $extra_product_item)
                                <div>+{{$extra_product_item['title'] ?? ''}}</div>
                            @endforeach 
                        @endif
                    </td>
                    <td>{{number_format($itemPrice)}}</td>
                    <td>x{{$itemQuantity}}</td>
                    <td class="txt-right">{{number_format($totalItemPrice)}}</td>
                    </tr>
                @endforeach
            @endif
            
            </tbody>
        </table>
        <div class="items-info">
            <table class="info-table" aria-label="Thông tin thanh toán" role="presentation">
                <tr>
                    <td class="Info_Total_Sub_name">{{ __('front/pos_order.invoices.Tổng số lượng') }}:</td>
                    <td class="Info_Total_Sub_number">{{ $totalProductQuantity ?? 0 }}</td>
                </tr>
                <tr>
                    <td class="Info_Total_Sub_name">{{ __('front/pos_order.content.Tổng tiền hàng') }}:</td>
                    <td class="Info_Total_Sub_number">{{ isset($subTotal) ? number_format($subTotal) : '' }}</td>
                </tr>
                @if (!empty($discount))
                <tr>
                    <td class="Info_Total_Sub_name">{{ __('front/pos_order.content.Giảm giá') }}{{ !empty($payment['discount_percent']) ? '('.$payment['discount_percent'].'%)' : '' }}:</td>
                    <td class="Info_Total_Sub_number">{{ number_format(str_replace(",", "", $discount)) }}</td>
                </tr>
                @endif
                @if (($seniorDiscount ?? 0) > 0)
                <tr>
                    <td class="Info_Total_Sub_name">Senior Discount 20%:</td>
                    <td class="Info_Total_Sub_number">{{ number_format($seniorDiscount) }}</td>
                </tr>
                @endif
                @if(isset($subTotalAfterDiscount))
                    <tr>
                        <td class="Info_Total_Sub_name">{{ __('front/pos_order.content.Tổng tiền hàng sau giảm giá') }}:</td>
                        <td class="Info_Total_Sub_number">{{ isset($subTotalAfterDiscount) ? number_format($subTotalAfterDiscount) : '' }}</td>
                    </tr>
                @endif
                @if (!empty($totalTax))
                <tr>
                    <td class="Info_Total_Sub_name">{{ __('front/pos_order.content.Thuế') }}:</td>
                    <td class="Info_Total_Sub_number">{{ number_format($totalTax) }}</td>
                </tr>
                @endif
                @if(($payment['service_charge_amount'] ?? 0) > 0 || ($timeZone ?? '') == 'Asia/Manila')
                <tr>
                    <td class="Info_Total_Sub_name">{{ __('front/pos_order.content.Phí dịch vụ') }}:</td>
                    <td class="Info_Total_Sub_number">{{ number_format($payment['service_charge_amount'] ?? 0) }}</td>
                </tr>
                @endif
                @if (!empty($surcharge))
                <tr>
                    <td class="Info_Total_Sub_name">{{ __('front/pos_order.content.Phụ thu') }}:</td>
                    <td class="Info_Total_Sub_number">{{ number_format(str_replace(",", "", $surcharge)) }}</td>
                </tr>
                @endif
                <tr>
                    <td class="Info_Total_Sub_name">{{ __('front/pos_order.invoices.Khách hàng phải trả') }}:</td>
                    <td class="Info_Total_Sub_number">{{ isset($valueTotal) ? number_format(str_replace(",", "", $valueTotal)) : 0 }}</td>
                </tr>
                @if (!empty($amount_received))
                <tr>
                    <td class="Info_Total_Sub_name">{{ __('front/pos_order.content.Nhận của khách') }}:</td>
                    <td class="Info_Total_Sub_number">{{ number_format(str_replace(",", "", $amount_received)) }}</td>
                </tr>
                <tr>
                    <td class="Info_Total_Sub_name">{{ __('front/pos_order.content.Tiền thối lại') }}:</td>
                    <td class="Info_Total_Sub_number">{{ number_format(str_replace(",", "", $amount_received) - str_replace(",", "", $valueTotal)) }}</td>
                </tr>
                @endif
            </table>
        </div>
        <div class="dashed-line"></div>
       <div class="invoice-total p-2">
            @if (!empty($qrImagePath))
                <img src="{{ $qrImagePath }}" style="height:200px;" alt="QR Code"> <br>
            @endif
            @if (!empty($bill_setting))
                @if(!empty($bill_setting['footer_content']))
                    <span id="footer_content">{{ $bill_setting['footer_content'] }}</span> <br>
                @endif
                @if(!empty($bill_setting['wifi_information']))
                    <span id="wifi_information">{{ $bill_setting['wifi_information'] }}</span> <br>
                @endif
            @endif
            <span>Powered by Delta Pos</span>
       </div>
    </div>
  </body>
</html>
