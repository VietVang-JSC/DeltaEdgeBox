<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Invoice</title>
<style>
body{font-family:sans-serif;font-size:12px;padding:10px;width:80mm}
table{width:100%;border-collapse:collapse;margin:5px 0}
td,th{padding:3px;text-align:left;border-bottom:1px solid #ddd}
.right{text-align:right}.bold{font-weight:bold}.center{text-align:center}
</style></head>
<body>
<h3 class="center">INVOICE</h3>
<p><strong>Code:</strong> {{ $payment->payment_code ?? 'N/A' }}</p>
<p><strong>Date:</strong> {{ $payment->created_at ? date('d/m/Y H:i', strtotime($payment->created_at)) : '' }}</p>
<table>
<tr><th>Product</th><th class="right">Qty</th><th class="right">Price</th><th class="right">Total</th></tr>
@foreach($payment->details ?? [] as $d)
<tr>
<td>{{ $d->product_key ?? $d->product_id }}</td>
<td class="right">{{ $d->quantity }}</td>
<td class="right">{{ number_format($d->price) }}</td>
<td class="right">{{ number_format($d->total) }}</td>
</tr>
@endforeach
</table>
<hr>
<p class="right bold">Subtotal: {{ number_format($payment->total ?? 0) }}</p>
@if($payment->discount > 0)
<p class="right bold">Discount: -{{ number_format($payment->discount) }}</p>
@endif
@if($payment->is_senior_discount)
<p class="right bold">Senior Discount: -{{ number_format($payment->senior_discount_amount) }}</p>
@endif
@if($payment->surcharge > 0)
<p class="right bold">Surcharge: +{{ number_format($payment->surcharge) }}</p>
@endif
@if($payment->service_charge > 0)
<p class="right bold">Service: +{{ number_format($payment->service_charge_amount ?? 0) }}</p>
@endif
<p class="right bold">Total: {{ number_format($payment->final_total ?? $payment->total ?? 0) }}</p>
<script>window.print();</script>
</body>
</html>
