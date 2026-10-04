<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\Finance\ReportService;
use App\Services\Finance\StatisticsService;
use App\Support\XlsxWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports, private readonly StatisticsService $statistics) {}

    public function catalogue(Request $request): JsonResponse
    {
        return $this->ok($this->reports->catalogue($request->user()));
    }

    public function show(Request $request, string $slug): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'format' => ['nullable', Rule::in(['json', 'csv', 'xlsx', 'pdf'])],
            'vessel_id' => ['nullable', 'integer'], 'voyage_id' => ['nullable', 'integer'], 'customer_company_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:30'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'as_of' => ['nullable', 'date'],
        ]);
        $format = $f['format'] ?? 'json';
        // Files get a larger allowance than the screen (PDF less: it is a page-by-page document), and are refused rather than silently cut.
        $limit = match ($format) {
            'json' => (int) config('offshore.reports.view_limit'),
            'pdf' => (int) config('offshore.reports.pdf_limit'),
            default => (int) config('offshore.reports.export_limit'),
        };
        $report = $this->reports->run($request->user(), $slug, $f, $limit);

        if ($format === 'json') {
            return $this->ok($report);
        }
        $this->reports->assertExportable($request->user());
        if ($report['truncated']) {
            throw new BusinessRuleException('This report has more than '.number_format($limit).' rows for '.strtoupper($format).'. Narrow the filters (dates, voyage, status) and export again.', 'report_too_large', ['format' => ['Too many rows.']], 422);
        }

        if ($format === 'xlsx') {
            return response()->streamDownload(
                fn () => print XlsxWriter::build($report['title'], $report['columns'], $report['rows']),
                $slug.'-'.now()->format('Ymd').'.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            );
        }

        if ($format === 'pdf') {
            return response()->streamDownload(
                fn () => print (Pdf::loadView('finance.report-pdf', ['report' => $report, 'generatedAt' => now()->format('Y-m-d H:i')])->setPaper('a4', 'landscape')->output()),
                $slug.'-'.now()->format('Ymd').'.pdf',
                ['Content-Type' => 'application/pdf'],
            );
        }

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_column($report['columns'], 'label'));
            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map(fn (array $c) => $this->safeCell($row[$c['key']] ?? null), $report['columns']));
            }
            fclose($out);
        }, $slug.'-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function statisticsCatalogue(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('statistics.view'), 403);

        return $this->ok($this->statistics->catalogue($request->user()));
    }

    public function statistic(Request $request, string $metric): JsonResponse
    {
        $f = $request->validate(['group_by' => ['nullable', 'string', 'max:20'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return $this->ok($this->statistics->metric($request->user(), $metric, $f));
    }

    /** Neutralise spreadsheet formula injection: cells starting with = + - @ are prefixed with a quote. */
    private function safeCell(?string $value): string
    {
        $value ??= '';

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value) ? "'".$value : $value;
    }
}
