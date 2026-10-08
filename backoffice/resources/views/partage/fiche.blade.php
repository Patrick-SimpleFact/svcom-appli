@php
    use Carbon\CarbonImmutable;
    $fuseau = 'Europe/Paris';
    $jour = $f['jour'] ? CarbonImmutable::parse($f['jour'], $fuseau)->locale('fr') : null;
    $lieu = $f['lieu'];
    $euros = fn (?float $v) => $v === null ? null : number_format($v, floor($v) == $v ? 0 : 2, ',', ' ').' €';
    $prix = $f['prix']['gratuit'] ? 'Gratuit' : (($f['prix']['min'] !== null)
        ? ($f['prix']['max'] !== null && $f['prix']['max'] > $f['prix']['min'] ? 'De '.$euros($f['prix']['min']).' à '.$euros($f['prix']['max']) : $euros($f['prix']['min']))
        : 'Tarifs sur la billetterie');
    $resume = collect([$jour?->isoFormat('dddd D MMMM'), $lieu ? collect([$lieu['nom'], $lieu['ville']])->filter()->implode(', ') : null])->filter()->implode(' · ');
    $ouvrir = $app['schema'].'://spectacle/'.$f['id'].(request('r') ? '?representation='.(int) request('r') : '');
@endphp
@extends('web.mise-en-page')

@section('titre', $f['titre'])

@section('meta')
    {{-- Aperçu du lien dans les messageries (WhatsApp, SMS, Messenger…). --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $f['titre'] }}">
    <meta property="og:description" content="{{ $resume ?: 'Sur Spettacoli' }}">
    <meta property="og:url" content="{{ $adresse }}">
    @if ($f['image_url'])<meta property="og:image" content="{{ $f['image_url'] }}">@endif
    <meta name="description" content="{{ $resume }}">
@endsection

@section('contenu')
    {{-- Sans visuel, ou visuel refusé par le navigateur (BilletRéduc interdit l'affichage de ses images sur un autre site) :
         affiche typographique, jamais de photo générique (F5.10). --}}
    @if ($f['image_url'])
        <img class="visuel" src="{{ $f['image_url'] }}" alt="" onerror="this.hidden = true; this.nextElementSibling.hidden = false">
    @endif
    <div class="affiche" aria-hidden="true" @if ($f['image_url']) hidden @endif>{{ $f['titre'] }}</div>

    <h1>{{ $f['titre'] }}</h1>
    <div class="genre">{{ collect([$f['genre']['libelle'] ?? null, $f['classification'], $f['jeune_public'] ? 'Jeune public' : null])->filter()->unique()->implode(' · ') }}</div>

    @if ($f['termine'])
        <section class="bloc"><strong>Ce spectacle est terminé.</strong> <span class="lieu">Plus aucune date à venir.</span></section>
    @else
        <section class="bloc">
            <div class="jour">{{ $jour?->isoFormat('dddd D MMMM YYYY') }}</div>
            @if ($lieu)
                <div class="lieu">{{ $lieu['nom'] }}@if ($lieu['adresse']), {{ $lieu['adresse'] }}@endif{{ $lieu['ville'] ? ', '.$lieu['ville'] : '' }}</div>
            @endif

            @foreach ($f['seances'] as $s)
                @php $billetterie = collect($s['billetteries'])->first(); @endphp
                <div class="seance">
                    <div>
                        <div class="heure">{{ $s['debut'] ? CarbonImmutable::parse($s['debut'])->format('G\hi') : 'Horaire à confirmer' }}</div>
                        @if ($billetterie)<div class="prix">{{ $billetterie['source'] }}{{ $billetterie['prix_min'] !== null ? ' · '.$euros($billetterie['prix_min']) : '' }}</div>@endif
                    </div>
                    @if ($s['complet'])
                        <span class="complet">Complet</span>
                    @elseif ($billetterie)
                        <a class="bouton principal" href="{{ $billetterie['lien_sortie'] }}?origine=lien_partage" rel="nofollow">{{ $billetterie['billetterie'] ? 'Réserver' : 'Voir le site' }}</a>
                    @elseif ($lieu && $lieu['telephone'])
                        <a class="bouton secondaire" href="tel:{{ preg_replace('/[^0-9+]/', '', $lieu['telephone']) }}">Appeler</a>
                    @endif
                </div>
            @endforeach

            <div class="prix" style="margin-top:6px">{{ $prix }}</div>
            @if ($f['autres_dates'] > 0)
                <p class="petit">{{ $f['autres_dates'] }} autre(s) date(s) à voir dans l’app.</p>
            @endif
        </section>
    @endif

    @if ($f['description'])
        <section class="bloc"><p style="margin:0;white-space:pre-line">{{ \Illuminate\Support\Str::limit($f['description'], 600) }}</p></section>
    @endif

    @include('partage.app', ['ouvrir' => $ouvrir])

    @if (count($f['sources']))
        <p class="petit" style="margin-top:16px">Source : {{ collect($f['sources'])->implode(', ') }}@if ($f['mis_a_jour_le']) · mis à jour le {{ CarbonImmutable::parse($f['mis_a_jour_le'])->setTimezone($fuseau)->format('d/m à G \h') }}@endif
            @foreach ($f['mentions'] as $mention)<br>{{ $mention }}@endforeach
        </p>
    @endif
@endsection
