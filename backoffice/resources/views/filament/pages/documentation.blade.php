<x-filament-panels::page>
    @php $chapitres = $this->chapitres(); @endphp

    <style>
        .doc-sommaire { columns: 3 220px; column-gap: 32px; font-size: 14px; }
        .doc-sommaire div { break-inside: avoid; margin-bottom: 12px; }
        .doc-sommaire strong { display: block; margin-bottom: 4px; }
        .doc-sommaire a, .doc-texte a { color: #D23A1F; }
        .doc-sommaire a:hover, .doc-texte a:hover { text-decoration: underline; }
        .doc-chapitre { scroll-margin-top: 80px; }
        .doc-texte { font-size: 15px; line-height: 1.6; max-width: 78ch; }
        .doc-texte p, .doc-texte ul, .doc-texte ol, .doc-texte table { margin: 0 0 12px; }
        .doc-texte ul { list-style: disc; padding-left: 22px; }
        .doc-texte ol { list-style: decimal; padding-left: 22px; }
        .doc-texte li { margin-bottom: 4px; }
        .doc-texte h3 { font-weight: 600; font-size: 15px; margin: 18px 0 6px; }
        .doc-texte strong { font-weight: 600; }
        .doc-texte code { font-size: 13px; padding: 1px 4px; border-radius: 4px; background: rgba(127,127,127,.12); }
        .doc-texte table { border-collapse: collapse; width: 100%; font-size: 14px; }
        .doc-texte th, .doc-texte td { text-align: left; vertical-align: top; padding: 6px 10px 6px 0; border-bottom: 1px solid rgba(127,127,127,.18); }
        .doc-texte th { font-weight: 600; }
        .doc-texte blockquote { border-left: 3px solid #D23A1F; padding: 2px 0 2px 12px; margin: 0 0 12px; opacity: .9; }
    </style>

    <x-filament::section heading="Sommaire" compact>
        <nav class="doc-sommaire">
            @foreach ($chapitres as $groupe => $ecrans)
                <div>
                    <strong>{{ $groupe }}</strong>
                    @foreach ($ecrans as $e)
                        <a href="#{{ $e['ancre'] }}">{{ $e['titre'] }}</a><br>
                    @endforeach
                </div>
            @endforeach
        </nav>
    </x-filament::section>

    @foreach ($chapitres as $groupe => $ecrans)
        <h2 style="font-size:18px;font-weight:600;margin-top:8px">{{ $groupe }}</h2>
        @foreach ($ecrans as $e)
            <x-filament::section :id="$e['ancre']" class="doc-chapitre" :heading="$e['titre']">
                @if ($e['url'])
                    <x-slot name="afterHeader">
                        <x-filament::link :href="$e['url']" icon="heroicon-m-arrow-right" icon-position="after" size="sm">Ouvrir</x-filament::link>
                    </x-slot>
                @endif
                <div class="doc-texte">{!! $e['html'] !!}</div>
            </x-filament::section>
        @endforeach
    @endforeach
</x-filament-panels::page>
