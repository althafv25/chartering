<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} · {{ $reference }}</title>
    <style>
        @page { margin: 34px 40px 54px; }
        body { font-family: "DejaVu Sans", sans-serif; color: #253344; font-size: 10px; line-height: 1.6; }
        table { width: 100%; border-collapse: collapse; }
        .header { border-bottom: 3px solid #078b82; margin-bottom: 22px; }
        .header td { vertical-align: top; padding-bottom: 15px; }
        .brand { color: #103f56; font-size: 17px; font-weight: bold; }
        .contact { color: #607080; font-size: 9px; }
        .document-meta { text-align: right; width: 43%; }
        h1 { color: #103f56; font-size: 22px; line-height: 1.3; margin: 0 0 6px; }
        .reference { font-weight: bold; font-size: 10px; }
        .status { display: inline-block; margin-top: 6px; padding: 3px 9px; border: 1px solid #b8d2d8; color: #103f56; border-radius: 3px; font-size: 9px; }
        .draft { color: #966017; border-color: #dbc69e; background: #fff8e9; }
        .subtitle { margin-bottom: 18px; color: #607080; }
        h2 { color: #103f56; font-size: 12px; margin: 20px 0 7px; padding-bottom: 4px; border-bottom: 1px solid #dbe4e9; page-break-after: avoid; }
        h3 { color: #103f56; font-size: 10px; margin: 13px 0 4px; page-break-after: avoid; }
        .details td { padding: 6px 10px; vertical-align: top; border-bottom: 1px solid #edf1f4; }
        .details .label { width: 28%; color: #607080; background: #f6f8fa; }
        .summary-section { page-break-inside: avoid; }
        .value, .terms, .clause-body { white-space: pre-wrap; overflow-wrap: break-word; word-wrap: break-word; }
        .grid th { color: #103f56; background: #eff5f7; font-weight: bold; text-align: left; }
        .grid th, .grid td { padding: 7px 8px; border: 1px solid #dbe4e9; vertical-align: top; }
        .grid tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .terms { margin: 0; }
        .notice { background: #fff8e9; border: 1px solid #dbc69e; padding: 8px 12px; margin: 8px 0; }
        .signatures { margin-top: 35px; page-break-inside: avoid; }
        .signatures td { width: 50%; padding-right: 24px; vertical-align: top; }
        .signature-line { margin-top: 36px; border-top: 1px solid #8c9ba5; padding-top: 6px; }
        footer { position: fixed; bottom: -33px; left: 0; right: 0; border-top: 1px solid #dbe4e9; padding-top: 6px; font-size: 8px; color: #71808d; }
    </style>
</head>
<body>
    <footer>{{ $reference }} · {{ $company['name'] }} · Generated {{ $generated_at }}</footer>
    <table class="header">
        <tr>
            <td>
                <div class="brand">{{ $company['name'] }}</div>
                @if($company['address'])<div class="contact value">{{ $company['address'] }}</div>@endif
                @if($company['email'])<div class="contact">{{ $company['email'] }}</div>@endif
                @if($company['phone'])<div class="contact">{{ $company['phone'] }}</div>@endif
                @if($company['tax_number'])<div class="contact">Tax no. {{ $company['tax_number'] }}</div>@endif
            </td>
            <td class="document-meta">
                <h1>{{ $title }}</h1>
                <div class="reference">{{ $reference }}</div>
                <div class="status {{ in_array($status, ['draft', 'submitted', 'under_review']) ? 'draft' : '' }}">{{ \Illuminate\Support\Str::headline($status) }}</div>
            </td>
        </tr>
    </table>
    <div class="subtitle">{{ $subtitle }}</div>

    @foreach($notices ?? [] as $notice)
        <div class="notice">{{ $notice }}</div>
    @endforeach

    @foreach($sections as $section)
        <div class="summary-section">
            <h2>{{ $section['title'] }}</h2>
            <table class="details">
                @foreach($section['rows'] as [$label, $value])
                    @if($value !== null && $value !== '')
                        <tr><td class="label">{{ $label }}</td><td class="value">{{ $value }}</td></tr>
                    @endif
                @endforeach
            </table>
        </div>
    @endforeach

    @foreach($tables ?? [] as $table)
        @if($table['rows'])
            <h2>{{ $table['title'] }}</h2>
            <table class="grid">
                <thead><tr>@foreach($table['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                <tbody>@foreach($table['rows'] as $row)<tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>@endforeach</tbody>
            </table>
        @endif
    @endforeach

    @if($itinerary)
        <h2>Itinerary</h2>
        <table class="grid">
            <thead><tr><th style="width: 8%">#</th><th>Port / offshore location</th><th style="width: 26%">Purpose</th></tr></thead>
            <tbody>
                @foreach($itinerary as $point)
                    <tr><td>{{ $loop->iteration }}</td><td>{{ $point['label'] }}</td><td>{{ $point['purpose'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($rates)
        <h2>Rates</h2>
        <table class="grid">
            <thead><tr><th>Rate / description</th><th style="width: 25%">Amount &amp; basis</th><th style="width: 28%">Validity</th></tr></thead>
            <tbody>
                @foreach($rates as $rate)
                    <tr>
                        <td><strong>{{ $rate['type'] }}</strong>@if($rate['description'])<br>{{ $rate['description'] }}@endif @if($rate['activity'])<br>{{ $rate['activity'] }}@endif</td>
                        <td>{{ $rate['amount'] }}<br>{{ $rate['unit'] }}</td>
                        <td>{{ $rate['dates'] ?? 'Whole contract' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($terms)
        <h2>Terms</h2>
        <div class="terms">{{ $terms }}</div>
    @endif

    @if($clauses)
        <h2>Contract clauses</h2>
        @foreach($clauses as $clause)
            <h3>{{ $clause['reference'] ?: $loop->iteration }}. {{ $clause['title'] }}</h3>
            <div class="clause-body">{{ $clause['body'] }}</div>
        @endforeach
    @endif

    @if($signatures)
        <table class="signatures">
            <tr>
                <td><strong>For {{ $company['name'] }}</strong><div class="signature-line">Authorized signature</div>Name:<br>Date:</td>
                <td><strong>For the customer</strong><div class="signature-line">Authorized signature</div>Name:<br>Date:</td>
            </tr>
        </table>
    @endif
</body>
</html>
