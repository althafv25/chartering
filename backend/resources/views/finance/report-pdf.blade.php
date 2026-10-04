<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #222; }
        h1 { font-size: 15px; margin: 0; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border-bottom: 1px solid #ddd; padding: 3px 5px; text-align: left; }
        th { background: #f5f5f5; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $report['title'] }}</h1>
    <p class="muted">{{ config('offshore.company.name', config('app.name')) }} · generated {{ $generatedAt }} · amounts in {{ $report['base_currency'] }} · {{ count($report['rows']) }} row(s)</p>
    <table>
        <thead>
            <tr>
                @foreach ($report['columns'] as $column)
                    <th class="{{ $column['align'] === 'right' ? 'right' : '' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($report['rows'] as $row)
                <tr>
                    @foreach ($report['columns'] as $column)
                        <td class="{{ $column['align'] === 'right' ? 'right' : '' }}">{{ $row[$column['key']] ?? '—' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
