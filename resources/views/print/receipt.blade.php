<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hóa Đơn Bán Hàng</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #000;
            width: 80mm; /* Standard thermal printer width */
            margin: 0 auto;
            padding: 5mm;
        }

        .receipt-header {
            text-align: center;
            margin-bottom: 10px;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
        }

        .store-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .store-info {
            font-size: 10px;
            color: #333;
        }

        .receipt-title {
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            margin: 10px 0;
            text-transform: uppercase;
        }

        .receipt-meta {
            margin: 8px 0;
            font-size: 11px;
        }

        .receipt-meta div {
            display: flex;
            justify-content: space-between;
            margin: 2px 0;
        }

        .items-table {
            width: 100%;
            margin: 10px 0;
            border-collapse: collapse;
        }

        .items-table th {
            text-align: left;
            border-bottom: 1px solid #000;
            padding: 5px 0;
            font-size: 11px;
            font-weight: bold;
        }

        .items-table td {
            padding: 5px 0;
            border-bottom: 1px dotted #ccc;
            font-size: 11px;
        }

        .items-table .qty {
            text-align: center;
            width: 40px;
        }

        .items-table .price {
            text-align: right;
            width: 80px;
        }

        .items-table .total {
            text-align: right;
            width: 90px;
            font-weight: bold;
        }

        .totals-section {
            margin-top: 10px;
            border-top: 1px dashed #000;
            padding-top: 10px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            margin: 3px 0;
            font-size: 11px;
        }

        .total-row.grand-total {
            font-size: 14px;
            font-weight: bold;
            margin-top: 5px;
            padding-top: 5px;
            border-top: 1px solid #000;
        }

        .payment-info {
            margin-top: 10px;
            padding: 8px;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
        }

        .payment-info div {
            display: flex;
            justify-content: space-between;
            margin: 2px 0;
            font-size: 11px;
        }

        .footer {
            margin-top: 15px;
            text-align: center;
            font-size: 10px;
            border-top: 1px dashed #000;
            padding-top: 10px;
        }

        .thank-you {
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .barcode {
            text-align: center;
            margin: 10px 0;
            font-family: monospace;
            font-size: 14px;
            letter-spacing: 2px;
        }

        .qr-code {
            text-align: center;
            margin: 10px 0;
        }

        @media print {
            body {
                width: 80mm;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Store Header -->
    <div class="receipt-header">
        <div class="store-name">{{ $store['name'] ?? 'DeltaPOS Store' }}</div>
        <div class="store-info">
            {{ $store['address'] ?? 'Address not available' }}<br>
            Tel: {{ $store['phone'] ?? 'N/A' }}
        </div>
    </div>

    <!-- Receipt Title -->
    <div class="receipt-title">HÓA ĐƠN BÁN HÀNG</div>

    <!-- Receipt Meta Information -->
    <div class="receipt-meta">
        <div>
            <span>Số HD:</span>
            <span><strong>#{{ str_pad($order['id'] ?? 0, 6, '0', STR_PAD_LEFT) }}</strong></span>
        </div>
        <div>
            <span>Ngày:</span>
            <span>{{ isset($order['created_at']) ? date('d/m/Y H:i:s', strtotime($order['created_at'])) : date('d/m/Y H:i:s') }}</span>
        </div>
        <div>
            <span>Nhân viên:</span>
            <span>{{ $order['staff_name'] ?? 'Staff' }}</span>
        </div>
        @if(isset($order['table_number']))
        <div>
            <span>Bàn:</span>
            <span>{{ $order['table_number'] }}</span>
        </div>
        @endif
        @if(isset($order['customer_name']))
        <div>
            <span>Khách hàng:</span>
            <span>{{ $order['customer_name'] }}</span>
        </div>
        @endif
    </div>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th>Món</th>
                <th class="qty">SL</th>
                <th class="price">Đơn giá</th>
                <th class="total">Thành tiền</th>
            </tr>
        </thead>
        <tbody>
            @foreach(($order['items'] ?? []) as $item)
            <tr>
                <td>
                    {{ $item['name'] ?? 'Item' }}
                    @if(isset($item['note']) && $item['note'])
                        <br><small style="color: #666;">({{ $item['note'] }})</small>
                    @endif
                </td>
                <td class="qty">{{ $item['quantity'] ?? 1 }}</td>
                <td class="price">{{ number_format($item['price'] ?? 0, 0, ',', '.') }}đ</td>
                <td class="total">{{ number_format(($item['price'] ?? 0) * ($item['quantity'] ?? 1), 0, ',', '.') }}đ</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals Section -->
    <div class="totals-section">
        <div class="total-row">
            <span>Tạm tính:</span>
            <span>{{ number_format($order['subtotal'] ?? 0, 0, ',', '.') }}đ</span>
        </div>
        @if(isset($order['discount']) && $order['discount'] > 0)
        <div class="total-row">
            <span>Giảm giá:</span>
            <span>-{{ number_format($order['discount'], 0, ',', '.') }}đ</span>
        </div>
        @endif
        <div class="total-row">
            <span>Thuế ({{ $order['tax_rate'] ?? 10 }}%):</span>
            <span>{{ number_format($order['tax_amount'] ?? 0, 0, ',', '.') }}đ</span>
        </div>
        <div class="total-row grand-total">
            <span>TỔNG CỘNG:</span>
            <span>{{ number_format($order['total'] ?? 0, 0, ',', '.') }}đ</span>
        </div>
    </div>

    <!-- Payment Information -->
    @if(isset($order['payment_method']))
    <div class="payment-info">
        <div>
            <span>Phương thức thanh toán:</span>
            <span><strong>{{ $order['payment_method'] }}</strong></span>
        </div>
        @if(isset($order['amount_paid']))
        <div>
            <span>Tiền khách đưa:</span>
            <span>{{ number_format($order['amount_paid'], 0, ',', '.') }}đ</span>
        </div>
        @endif
        @if(isset($order['change_amount']))
        <div>
            <span>Tiền thừa:</span>
            <span>{{ number_format($order['change_amount'], 0, ',', '.') }}đ</span>
        </div>
        @endif
    </div>
    @endif

    <!-- Barcode/QR Code (Optional) -->
    @if(isset($order['id']))
    <div class="barcode">
        ||| {{ str_pad($order['id'], 6, '0', STR_PAD_LEFT) }} |||
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <div class="thank-you">CẢM ƠN QUÝ KHÁCH!</div>
        <div>Hẹn gặp lại quý khách</div>
        @if(isset($store['website']))
        <div style="margin-top: 5px;">{{ $store['website'] }}</div>
        @endif
    </div>
</body>
</html>
