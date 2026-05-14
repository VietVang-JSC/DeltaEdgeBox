<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu Bếp</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 13px;
            line-height: 1.5;
            color: #000;
            width: 80mm;
            margin: 0 auto;
            padding: 5mm;
        }

        .kitchen-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }

        .kitchen-title {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .order-type {
            display: inline-block;
            padding: 3px 10px;
            background-color: #000;
            color: #fff;
            font-weight: bold;
            font-size: 12px;
            margin-top: 5px;
        }

        .order-meta {
            margin: 10px 0;
            font-size: 12px;
        }

        .order-meta div {
            margin: 3px 0;
        }

        .order-meta strong {
            display: inline-block;
            width: 100px;
        }

        .items-list {
            margin: 10px 0;
        }

        .item-row {
            padding: 8px 0;
            border-bottom: 1px dotted #999;
        }

        .item-name {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .item-qty {
            display: inline-block;
            background-color: #000;
            color: #fff;
            padding: 2px 8px;
            font-weight: bold;
            margin-right: 8px;
        }

        .item-note {
            font-size: 11px;
            color: #666;
            font-style: italic;
            margin-top: 3px;
            padding-left: 5px;
            border-left: 2px solid #ff0000;
        }

        .special-instructions {
            margin-top: 15px;
            padding: 8px;
            background-color: #ffffcc;
            border: 1px solid #ffcc00;
        }

        .special-instructions h4 {
            font-size: 12px;
            margin-bottom: 5px;
            color: #ff0000;
        }

        .footer {
            margin-top: 15px;
            text-align: center;
            font-size: 11px;
            border-top: 2px solid #000;
            padding-top: 10px;
        }

        .timestamp {
            font-size: 10px;
            color: #666;
            margin-top: 5px;
        }

        .urgent {
            background-color: #ff0000;
            color: #fff;
            padding: 5px;
            text-align: center;
            font-weight: bold;
            margin: 10px 0;
            animation: blink 1s infinite;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
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
    <!-- Kitchen Header -->
    <div class="kitchen-header">
        <div class="kitchen-title">🍳 PHIẾU BẾP</div>
        <div class="order-type">{{ strtoupper($order['order_type'] ?? 'DINE-IN') }}</div>
    </div>

    <!-- Order Meta Information -->
    <div class="order-meta">
        <div>
            <strong>Số_order:</strong>
            <span style="font-size: 16px; font-weight: bold;">#{{ str_pad($order['id'] ?? 0, 6, '0', STR_PAD_LEFT) }}</span>
        </div>
        <div>
            <strong>Bàn:</strong>
            <span style="font-size: 14px; font-weight: bold;">{{ $order['table_number'] ?? 'N/A' }}</span>
        </div>
        <div>
            <strong>Thời gian:</strong>
            <span>{{ isset($order['created_at']) ? date('H:i:s', strtotime($order['created_at'])) : date('H:i:s') }}</span>
        </div>
        <div>
            <strong>Ngày:</strong>
            <span>{{ isset($order['created_at']) ? date('d/m/Y', strtotime($order['created_at'])) : date('d/m/Y') }}</span>
        </div>
        @if(isset($order['staff_name']))
        <div>
            <strong>Nhân viên:</strong>
            <span>{{ $order['staff_name'] }}</span>
        </div>
        @endif
    </div>

    <!-- Urgent Warning (if applicable) -->
    @if(isset($order['priority']) && $order['priority'] === 'urgent')
    <div class="urgent">
        ⚡ GẤP - ƯU TIÊN CAO ⚡
    </div>
    @endif

    <!-- Items List -->
    <div class="items-list">
        @foreach(($order['items'] ?? []) as $index => $item)
        <div class="item-row">
            <div class="item-name">
                <span class="item-qty">{{ $item['quantity'] ?? 1 }}x</span>
                {{ $item['name'] ?? 'Item' }}
            </div>

            @if(isset($item['note']) && $item['note'])
            <div class="item-note">
                📝 {{ $item['note'] }}
            </div>
            @endif

            @if(isset($item['modifiers']) && count($item['modifiers']) > 0)
            <div style="margin-top: 3px; padding-left: 5px; font-size: 11px;">
                @foreach($item['modifiers'] as $modifier)
                    • {{ $modifier }}<br>
                @endforeach
            </div>
            @endif
        </div>
        @endforeach
    </div>

    <!-- Special Instructions -->
    @if(isset($order['special_instructions']) && $order['special_instructions'])
    <div class="special-instructions">
        <h4>⚠️ LƯU Ý ĐẶC BIỆT:</h4>
        <div>{{ $order['special_instructions'] }}</div>
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <div style="font-weight: bold; margin-bottom: 5px;">--- HẾT ---</div>
        <div>Vui lòng xác nhận khi hoàn thành</div>
        <div class="timestamp">
            In lúc: {{ date('d/m/Y H:i:s') }}
        </div>
    </div>
</body>
</html>
