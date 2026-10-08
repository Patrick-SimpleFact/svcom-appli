{{-- Écran du téléphone de la page d'accueil : les vrais spectacles autour du visiteur, comme dans l'app (maquette « Autour de moi »). --}}
<div class="ecran-haut">
    <span class="ville"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>{{ $apercu['ville'] }}</span>
</div>
<div class="ecran-titre">{{ $apercu['quand'] }},<br>près de vous</div>
<div class="ecran-compte">
    @if ($apercu['total'] > 0)
        {{ $apercu['total'] }} spectacle{{ $apercu['total'] > 1 ? 's' : '' }} à moins de {{ $apercu['rayon_km'] }} km
    @else
        Rien de prévu tout près pour l’instant
    @endif
</div>
<div class="tickets">
    @forelse ($apercu['cartes'] as $c)
        <a class="ticket {{ $c['complet'] ? 'complet' : '' }}" href="{{ $c['lien'] }}">
            <span class="talon"><b>{{ $c['heure'] }}</b><small>{{ $c['plus'] }}</small></span>
            <span class="corps">
                <span class="genre">{{ $c['genre'] }}</span>
                <span class="titre">{{ $c['titre'] }}</span>
                <span class="lieu">{{ $c['lieu'] }} · {{ $c['distance'] }}@if ($c['complet']) · Complet @endif</span>
            </span>
        </a>
    @empty
        <p class="vide">La couverture de cette zone est en cours d’enrichissement.</p>
    @endforelse
</div>
<p class="origine" data-origine="{{ $apercu['origine'] }}">
    @switch($apercu['origine'])
        @case('position') Autour de votre position. @break
        @case('adresse_ip') Près de {{ $apercu['ville'] }}, estimé d’après votre connexion. @break
        @default Exemple à {{ $apercu['ville'] }}.
    @endswitch
</p>
