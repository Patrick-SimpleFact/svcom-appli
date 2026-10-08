<x-filament-panels::page>
    {{ $this->form }}

    @php $r = $this->rapport(); @endphp
    @if ($r)
        <x-filament::section>
            <x-slot name="heading">{{ $r['titre'] }} — {{ number_format($r['total'], 0, ',', ' ') }} clic(s)</x-slot>
            <x-slot name="description">{{ $r['sous_titre'] }}, du {{ $r['du']->format('d/m/Y') }} au {{ $r['au']->format('d/m/Y') }}</x-slot>
            @forelse ($r['faits'] as $fait)
                <p style="font-size:14px;margin:2px 0">• {{ $fait }}</p>
            @empty
                <p style="font-size:14px;opacity:.7">Aucun clic compté sur cette période.</p>
            @endforelse
        </x-filament::section>

        @if ($r['total'] > 0)
            <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr))">
                @foreach ($r['sections'] as $titre => $section)
                    <x-filament::section :heading="$titre" compact>
                        <table style="width:100%;font-size:14px;border-collapse:collapse">
                            @foreach ($section as $l)
                                <tr style="border-bottom:1px solid rgba(127,127,127,.15)">
                                    <td style="padding:4px 8px 4px 0">{{ $l['libelle'] }}</td>
                                    <td style="padding:4px 0;width:33%"><div style="height:8px;border-radius:4px;background:#D23A1F;width: {{ max(2, $l['part']) }}%"></div></td>
                                    <td style="padding:4px 0 4px 8px;text-align:right;font-variant-numeric:tabular-nums">{{ $l['clics'] }}</td>
                                    <td style="padding:4px 0 4px 8px;text-align:right;font-variant-numeric:tabular-nums;opacity:.6;white-space:nowrap">{{ str_replace('.', ',', $l['part']) }} %</td>
                                </tr>
                            @endforeach
                        </table>
                    </x-filament::section>
                @endforeach
            </div>
        @endif
        <p style="font-size:12px;opacity:.65">{{ \App\Statistiques\ExportRapport::methode() }}</p>
    @endif
</x-filament-panels::page>
