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
        h2 { color: #134E4A; font-size: 18px; margin: 0 0 12px; }
        .contents { color: #334155; line-height: 1.8; }
        .section { break-before: page; }
        h3 { color: #334155; font-size: 14px; margin: 18px 0 8px; }
        table.fields, table.entries { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.fields td:first-child { color: #64748B; width: 34%; }
        table.entries th { text-align: left; color: #134E4A; border-bottom: 2px solid #0D9488; padding: 5px 8px 5px 0; }
        table.fields td, table.entries td { border-bottom: 1px solid #E2E8F0; padding: 5px 8px 5px 0; vertical-align: top; }
        .none { color: #64748B; font-style: italic; }
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
        <h2>{{ __('dsar.report.contents') }}</h2>
        <ol class="contents">
            @foreach ($report->sections as $section)
                <li>{{ $section->title }}</li>
            @endforeach
        </ol>
    </section>
    @foreach ($report->sections as $section)
        <section class="section">
            <h2>{{ $section->title }}</h2>
            @foreach ($section->tables as $table)
                @if ($table->caption !== null)
                    <h3>{{ $table->caption }}</h3>
                @endif
                @if ($table->rows === [])
                    <p class="none">{{ __('dsar.report.none') }}</p>
                @else
                    <table class="{{ $table->columns === [] ? 'fields' : 'entries' }}">
                        @if ($table->columns !== [])
                            <thead><tr>@foreach ($table->columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                        @endif
                        <tbody>
                            @foreach ($table->rows as $row)
                                <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endforeach
        </section>
    @endforeach
</body>
</html>
