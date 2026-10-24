<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 34pt 34pt 44pt; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #0f172a; line-height: 1.35; }
        h1 { font-size: 15pt; margin: 0 0 2pt; }
        h2 { font-size: 10.5pt; margin: 16pt 0 5pt; padding-bottom: 2pt; border-bottom: 1pt solid #cbd5e1; page-break-after: avoid; }
        h3 { font-size: 9pt; margin: 10pt 0 4pt; page-break-after: avoid; }
        p { margin: 0 0 4pt; }
        .muted { color: #475569; }
        .small { font-size: 7.5pt; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        .notice { padding: 5pt 7pt; margin: 6pt 0; border: 1pt solid #fcd34d; background: #fffbeb; color: #78350f; }
        .ok { padding: 5pt 7pt; margin: 6pt 0; border: 1pt solid #a7f3d0; background: #ecfdf5; color: #064e3b; }
        table { width: 100%; border-collapse: collapse; margin: 0 0 4pt; }
        th, td { padding: 3pt 4pt; vertical-align: top; text-align: left; }
        thead th { background: #e2e8f0; font-weight: bold; font-size: 7.5pt; }
        tbody tr { page-break-inside: avoid; }
        tbody td { border-bottom: 0.5pt solid #e2e8f0; }
        tfoot td { font-weight: bold; border-top: 1pt solid #94a3b8; }
        .num { text-align: right; white-space: nowrap; }
        .fields td { border: none; padding: 2pt 4pt 2pt 0; }
        .fields td.label { width: 28%; color: #475569; }
        .tiles td { width: 25%; border: 0.5pt solid #cbd5e1; padding: 5pt 6pt; }
        .tiles .value { font-size: 13pt; font-weight: bold; }
        .tag { padding: 0 3pt; background: #f1f5f9; font-size: 7.5pt; }
        .warn { color: #92400e; }
        .bar { height: 6pt; background: #3b82f6; }
    </style>
</head>
<body>
    @yield('content')
</body>
</html>
