<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Le parcours, jour par jour ({{ $periode }})</x-slot>
        <x-slot name="description">
            Appareils qui ont ouvert l’app, puis vu au moins une fiche, puis cliqué vers une billetterie. La part est calculée sur les appareils qui ont ouvert l’app.
        </x-slot>

        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:14px">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid rgba(127,127,127,.3)">
                        <th style="padding:8px 10px;font-weight:600">Jour</th>
                        <th style="padding:8px 10px;font-weight:600;text-align:right">Ont ouvert l’app</th>
                        <th style="padding:8px 10px;font-weight:600;text-align:right">Ont vu une fiche</th>
                        <th style="padding:8px 10px;font-weight:600;text-align:right">Ont cliqué vers une billetterie</th>
                        <th style="padding:8px 10px;font-weight:600">Actions de communication</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lignes as $l)
                        <tr wire:key="parcours-{{ $l['jour'] }}" style="border-bottom:1px solid rgba(127,127,127,.12)">
                            <td style="padding:8px 10px;white-space:nowrap">{{ ucfirst($l['libelle']) }}</td>
                            <td style="padding:8px 10px;text-align:right;font-variant-numeric:tabular-nums">{{ $l['actifs'] }}</td>
                            <td style="padding:8px 10px;text-align:right;font-variant-numeric:tabular-nums">
                                {{ $l['fiches'] }}@if ($l['part_fiches'] !== null) <span style="opacity:.6">({{ $l['part_fiches'] }} %)</span>@endif
                            </td>
                            <td style="padding:8px 10px;text-align:right;font-variant-numeric:tabular-nums">
                                {{ $l['clics'] }}@if ($l['part_clics'] !== null) <span style="opacity:.6">({{ $l['part_clics'] }} %)</span>@endif
                            </td>
                            <td style="padding:8px 10px;font-size:13px;opacity:.8">{{ implode(' — ', $l['actions']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
