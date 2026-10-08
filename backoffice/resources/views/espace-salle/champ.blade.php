<div class="champ">
    <label for="{{ $nom }}">{{ $libelle }} @unless ($requis)<span class="facultatif">(facultatif)</span>@endunless</label>
    <input id="{{ $nom }}" name="{{ $nom }}" type="{{ $type }}" value="{{ old($nom, request($nom)) }}" @if ($requis) required @endif
        @if ($type === 'email') autocomplete="email" @elseif ($type === 'tel') autocomplete="tel" @endif>
    @if ($aide)<div class="petit">{{ $aide }}</div>@endif
    @if ($errors->has($nom))<div class="erreur">{{ $errors->first($nom) }}</div>@endif
</div>
