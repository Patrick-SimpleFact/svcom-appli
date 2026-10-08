{{-- Inviter à installer l'app (boutons des boutiques quand elles sont connues). --}}
<section class="bloc app">
    <p><strong>Tous les spectacles près de chez vous, ce soir.</strong></p>
    @if ($app['app_store'] || $app['google_play'])
        <div class="boutiques">
            @if ($app['app_store'])<a class="bouton secondaire" href="{{ $app['app_store'] }}">App Store</a>@endif
            @if ($app['google_play'])<a class="bouton secondaire" href="{{ $app['google_play'] }}">Google Play</a>@endif
        </div>
    @else
        <p class="petit">Bientôt sur l’App Store et Google Play.</p>
    @endif
    @isset($ouvrir)
        <p class="petit" style="margin-top:12px">Vous avez l’app ? <a href="{{ $ouvrir }}">Ouvrir dans Spettacoli</a></p>
    @endisset
    <p class="petit" style="margin-top:12px"><a href="{{ url('/espace-salle/demande') }}">Vous êtes un théâtre ?</a></p>
</section>
