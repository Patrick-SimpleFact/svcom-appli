<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1d1a17; }
    h1 { font-size: 20px; margin: 0; color: #D23A1F; }
    h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #e7e1d8; padding-bottom: 3px; }
    .gris { color: #6b6560; }
    .chiffre { font-size: 28px; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 3px 4px; border-bottom: 1px solid #f0ebe3; }
    table.liste { table-layout: fixed; }
    td.n, td.p { text-align: right; white-space: nowrap; }
    td.b { padding-left: 6px; }
    .barre { background: #D23A1F; height: 7px; }
    .colonnes > tr > td, td.colonne { vertical-align: top; border: 0; padding: 0 10px 0 0; }
    .note { font-size: 8.5px; color: #6b6560; margin-top: 18px; }
</style>
</head>
<body>
    <div class="gris">Spettacoli · rapport de trafic</div>
    <h1>{{ $r['titre'] }}</h1>
    <div class="gris">{{ $r['sous_titre'] }} — du {{ $r['du']->format('d/m/Y') }} au {{ $r['au']->format('d/m/Y') }}</div>

    <p><span class="chiffre">{{ number_format($r['total'], 0, ',', ' ') }}</span> clics vers la billetterie</p>
    @foreach ($r['faits'] as $fait)<div>• {{ $fait }}</div>@endforeach

    @if ($r['total'] > 0)
        <table class="colonnes"><tr>
            @foreach (array_chunk($r['sections'], (int) ceil(count($r['sections']) / 2), true) as $colonne)
                <td class="colonne" width="50%">
                    @foreach ($colonne as $titre => $section)
                        <h2>{{ $titre }}</h2>
                        <table class="liste">
                            @foreach ($section as $l)
                                <tr><td class="l" width="54%">{{ \Illuminate\Support\Str::limit($l['libelle'], 45) }}</td><td class="n" width="11%">{{ $l['clics'] }}</td>
                                    <td class="b" width="21%"><div class="barre" style="width: {{ max(2, (int) $l['part']) }}%"></div></td><td class="p" width="14%">{{ str_replace('.', ',', $l['part']) }} %</td></tr>
                            @endforeach
                        </table>
                    @endforeach
                </td>
            @endforeach
        </tr></table>
    @endif

    <p class="note">{{ \App\Statistiques\ExportRapport::methode() }} Rapport généré le {{ now('Europe/Paris')->format('d/m/Y à H:i') }}.</p>
</body>
</html>
