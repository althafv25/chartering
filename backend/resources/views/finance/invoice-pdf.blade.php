<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #ddd; padding: 4px 6px; text-align: left; }
        th { background: #f5f5f5; }
        .right { text-align: right; }
        .totals td { border: none; padding: 2px 6px; }
        .header-table { width: 100%; margin-bottom: 12px; }
        .header-table td { border: none; vertical-align: top; padding: 2px 0; }
    </style>
</head>
<body>
    {{-- Placeholder template: layout/branding to be finalized in a later phase. --}}
    <h1>INVOICE</h1>
    <p class="muted">{{ $invoice->invoice_number ?? 'DRAFT' }}</p>

    <table class="header-table">
        <tr>
            <td style="width: 50%;">
                <strong>{{ $company['name'] ?? config('offshore.company.name', 'Offshore Chartering') }}</strong><br>
            </td>
            <td style="width: 50%;">
                <strong>Bill To</strong><br>
                {{ $invoice->billing_snapshot['name'] ?? '' }}<br>
                {{ $invoice->billing_snapshot['address'] ?? '' }}<br>
                @if (!empty($invoice->billing_snapshot['tax_no']))
                    Tax No: {{ $invoice->billing_snapshot['tax_no'] }}
                @endif
            </td>
        </tr>
        <tr>
            <td>Issue Date: {{ optional($invoice->issue_date)->toDateString() }}</td>
            <td>Due Date: {{ optional($invoice->due_date)->toDateString() }}</td>
        </tr>
        <tr>
            <td>Currency: {{ $invoice->currency }}</td>
            <td>Status: {{ $invoice->status->value }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Description</th>
                <th class="right">Qty</th>
                <th class="right">Rate</th>
                <th class="right">Amount</th>
                <th class="right">Tax</th>
                <th class="right">Line Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->sequence }}</td>
                    <td>{{ $line->description }}</td>
                    <td class="right">{{ $line->quantity }}</td>
                    <td class="right">{{ $line->rate }}</td>
                    <td class="right">{{ $line->amount }}</td>
                    <td class="right">{{ $line->tax_amount }}</td>
                    <td class="right">{{ $line->line_total }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="width: 300px; margin-left: auto;">
        <tr>
            <td>Subtotal</td>
            <td class="right">{{ $invoice->subtotal }}</td>
        </tr>
        <tr>
            <td>Tax</td>
            <td class="right">{{ $invoice->tax_amount }}</td>
        </tr>
        <tr>
            <td><strong>Total</strong></td>
            <td class="right"><strong>{{ $invoice->total }} {{ $invoice->currency }}</strong></td>
        </tr>
        <tr>
            <td>Paid</td>
            <td class="right">{{ $invoice->amount_paid }}</td>
        </tr>
        <tr>
            <td><strong>Balance</strong></td>
            <td class="right"><strong>{{ $invoice->balance }}</strong></td>
        </tr>
    </table>

    @if ($invoice->remarks)
        <p class="muted">{{ $invoice->remarks }}</p>
    @endif
</body>
</html>
