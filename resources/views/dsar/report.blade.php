<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('dsar.report.title') }}</title>
    <style>
        @page { size: A4; margin: 18mm 16mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #0F172A; font-size: 12px; margin: 0; }
        .wordmark { color: #0D9488; font-size: 26px; font-weight: bold; letter-spacing: 1px; }
        .cover h1 { color: #134E4A; font-size: 28px; margin: 24px 0 20px; }
        .cover table { border-collapse: collapse; margin-bottom: 24px; }
        .cover td { padding: 4px 16px 4px 0; vertical-align: top; }
        .cover .label { color: #64748B; }
        .guide { border-left: 3px solid #0D9488; background: #F0FDFA; padding: 10px 14px; color: #334155; }
    </style>
</head>
<body>
    <section class="cover">
        <div class="wordmark">lesily</div>
        <h1>{{ __('dsar.report.title') }}</h1>
        <table>
            <tr><td colspan="2"><strong>{{ $report->cover->subjectName }}</strong><br>{{ $report->cover->subjectEmail }}</td></tr>
            <tr><td class="label">{{ __('dsar.report.company') }}</td><td>{{ $report->cover->companyName }}</td></tr>
            <tr><td class="label">{{ __('dsar.report.reference') }}</td><td>{{ $report->cover->reference }}</td></tr>
            <tr><td class="label">{{ __('dsar.report.requested') }}</td><td>{{ $report->cover->requestedAt }}</td></tr>
            <tr><td class="label">{{ __('dsar.report.generated') }}</td><td>{{ $report->cover->generatedAt }}</td></tr>
        </table>
        <p class="guide">{{ __('dsar.report.guide') }}</p>
    </section>
</body>
</html>
