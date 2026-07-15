<!DOCTYPE html>
{{-- Payment receipt (UC-1-121) — regenerated on demand, never stored (R3).
     English-only at T5; trilingual rides the i18n slice (s135 F-b — the
     Chromium renderer shapes Sinhala/Tamil once Noto fonts + translations land). --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #0f172a; margin: 48px; font-size: 14px; }
        .wordmark { color: #0D9488; font-size: 24px; font-weight: bold; letter-spacing: 1px; }
        .title { font-size: 18px; margin-top: 4px; color: #134E4A; }
        .meta { margin-top: 24px; width: 100%; border-collapse: collapse; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .meta .label { color: #64748b; width: 160px; }
        .items { margin-top: 28px; width: 100%; border-collapse: collapse; }
        .items th { text-align: left; border-bottom: 2px solid #0D9488; padding: 6px 8px 6px 0; color: #134E4A; }
        .items td { border-bottom: 1px solid #e2e8f0; padding: 6px 8px 6px 0; }
        .items .num { text-align: right; }
        .total-row td { border-bottom: none; font-weight: bold; padding-top: 12px; }
        .footnote { margin-top: 36px; color: #64748b; font-size: 11px; }
    </style>
</head>
<body>
    <div class="wordmark">lesily</div>
    <div class="title">Payment Receipt</div>

    <table class="meta">
        <tr><td class="label">Receipt for invoice</td><td>{{ $invoice->invoice_number }}</td></tr>
        <tr><td class="label">Issued</td><td>{{ $invoice->issued_at->format('Y-m-d') }}</td></tr>
        <tr><td class="label">Paid</td><td>{{ $invoice->paid_at->format('Y-m-d') }}</td></tr>
        <tr><td class="label">Billed to</td><td>{{ $invoice->recipient_name }} ({{ $invoice->recipient_email }})</td></tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit ({{ $invoice->currency }})</th>
                <th class="num">Amount ({{ $invoice->currency }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lineItems as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ number_format($item->quantity, 2) }}</td>
                    <td class="num">{{ number_format($item->unit_price_cents / 100, 2) }}</td>
                    <td class="num">{{ number_format($item->amount_cents / 100, 2) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="3">Total paid</td>
                <td class="num">{{ $invoice->currency }} {{ number_format($invoice->amount_cents / 100, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="footnote">
        This receipt reflects the original payment for invoice {{ $invoice->invoice_number }}.
        Generated on request by Lesily — a FusionRealm product.
    </p>
</body>
</html>
