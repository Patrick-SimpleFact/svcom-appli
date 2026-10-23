<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Mes actions du jour</x-slot>
        <x-slot name="description">
            @if ($total === 0)
                Rien à décider pour l’instant.
            @else
                {{ $total }} décision(s) à prendre, dont {{ $nouvelles }} ajoutée(s) aujourd’hui. Les plus récentes d’abord, puis la plus urgente.
            @endif
            @if ($enExamen > 0)
                {{ $enExamen }} autre(s) élément(s) en cours d’examen par l’IA.
            @endif
        </x-slot>

        @if ($carte)
            <div wire:key="carte-{{ $element->id }}" style="display:flex;flex-direction:column;gap:14px">
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                    <x-filament::badge color="gray">{{ $carte['file'] }}</x-filament::badge>
                    <x-filament::badge color="info" icon="heroicon-o-calendar">{{ $carte['ajoutee'] }}</x-filament::badge>
                    @if ($carte['urgence'])
                        <x-filament::badge color="danger">{{ $carte['urgence'] }}</x-filament::badge>
                    @endif
                    <span style="font-size:13px;opacity:.65">Carte {{ $rang }} sur {{ $total }}</span>
                </div>

                <h2 style="font-size:20px;font-weight:600;line-height:1.3;margin:0">{{ $carte['question'] }}</h2>

                @if ($carte['comparaison'])
                    <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
                        @foreach ($carte['comparaison'] as $i => $seance)
                            <div style="border:1px solid rgba(127,127,127,.25);border-radius:10px;padding:12px">
                                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;opacity:.6;margin-bottom:6px">Séance {{ $i === 0 ? 'A' : 'B' }}</div>
                                @if ($seance)
                                    <div style="font-weight:600">{{ $seance['titre'] }}</div>
                                    <div style="font-size:14px;margin-top:4px"><strong>{{ $seance['quand'] }}</strong></div>
                                    <div style="font-size:14px">{{ $seance['lieu'] }}</div>
                                    <div style="font-size:14px;opacity:.75">{{ $seance['billetterie'] }}@if ($seance['prix']) · {{ $seance['prix'] }}@endif</div>
                                    @if ($seance['lien'])
                                        <a href="{{ $seance['lien'] }}" target="_blank" rel="noopener" style="font-size:13px;text-decoration:underline">Voir chez la billetterie</a>
                                    @endif
                                @else
                                    <div style="font-size:14px;opacity:.7">Cette annonce n’existe plus.</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @php $faits = array_filter($carte['faits'], fn ($f) => filled($f[1])); @endphp
                @if ($faits)
                    <table style="font-size:14px;border-collapse:collapse;width:100%">
                        @foreach ($faits as $fait)
                            <tr>
                                <td style="padding:3px 12px 3px 0;opacity:.65;white-space:nowrap;vertical-align:top;width:1%">{{ $fait[0] }}</td>
                                <td style="padding:3px 0">
                                    @if (! empty($fait[2]))
                                        <a href="{{ $fait[2] }}" target="_blank" rel="noopener" style="text-decoration:underline">{{ $fait[1] }}</a>
                                    @else
                                        {{ $fait[1] }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                @if ($carte['billetteries'])
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                        <span style="font-size:13px;opacity:.65">Voir chez la billetterie :</span>
                        @foreach ($carte['billetteries'] as [$nom, $url])
                            <x-filament::link :href="$url" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square" size="sm">{{ $nom }}</x-filament::link>
                        @endforeach
                    </div>
                @endif

                @if ($carte['lieux'])
                    <div style="display:flex;flex-direction:column;gap:6px">
                        <div style="font-size:13px;opacity:.65">Cochez les lieux qui jouent un AUTRE spectacle, si besoin :</div>
                        @foreach ($carte['lieux'] as $lieu)
                            <label style="display:flex;gap:8px;align-items:flex-start;font-size:14px;cursor:pointer">
                                <input type="checkbox" wire:model="lieux" value="{{ $lieu['id'] }}" style="margin-top:3px">
                                <span><strong>{{ $lieu['nom'] }}</strong> — {{ $lieu['dates'] }} date(s), {{ $lieu['billetteries'] }}
                                    @if ($lieu['titres'])<br><span style="opacity:.7">{{ $lieu['titres'] }}</span>@endif
                                    @foreach ($lieu['liens'] as [$nom, $url])
                                        <br><a href="{{ $url }}" target="_blank" rel="noopener" style="font-size:13px;text-decoration:underline">Voir chez {{ $nom }}</a>
                                    @endforeach
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endif

                @if ($carte['lieuAVerifier'])
                    <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
                        <div style="border:1px solid rgba(127,127,127,.25);border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:8px">
                            <div style="font-weight:600">Corriger le nom</div>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" wire:model="nouveauNom" placeholder="Le vrai nom de la salle" maxlength="255" />
                            </x-filament::input.wrapper>
                            <div>
                                <x-filament::button color="primary" wire:click="decider({{ $element->id }}, 'renommer')" wire:loading.attr="disabled">Enregistrer ce nom</x-filament::button>
                                <div style="font-size:12px;opacity:.65;margin-top:4px">Affiché dans l’app ; les collectes ne le changeront plus.</div>
                            </div>
                        </div>
                        <div style="border:1px solid rgba(127,127,127,.25);border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:8px">
                            <div style="font-weight:600">Rattacher à un lieu connu</div>
                            @if ($carte['lieuAVerifier']['proches'])
                                <div style="font-size:13px;opacity:.65">Lieux connus à moins de {{ \App\Ia\MesActions::RAYON_LIEUX_PROCHES }} m :</div>
                                @foreach ($carte['lieuAVerifier']['proches'] as $proche)
                                    <label style="display:flex;gap:8px;align-items:flex-start;font-size:14px;cursor:pointer">
                                        <input type="radio" wire:model.live="lieuChoisi" value="{{ $proche['id'] }}" style="margin-top:3px">
                                        <span>{{ $proche['nom'] }} <span style="opacity:.6">({{ $proche['distance'] }} m)</span></span>
                                    </label>
                                @endforeach
                            @endif
                            <x-filament::input.wrapper>
                                <x-filament::input type="search" wire:model.live.debounce.400ms="rechercheLieu" placeholder="Chercher un lieu par son nom ou son adresse" />
                            </x-filament::input.wrapper>
                            @foreach ($lieuxTrouves as $trouve)
                                <label style="display:flex;gap:8px;align-items:flex-start;font-size:14px;cursor:pointer">
                                    <input type="radio" wire:model.live="lieuChoisi" value="{{ $trouve['id'] }}" style="margin-top:3px">
                                    <span>{{ $trouve['nom'] }}</span>
                                </label>
                            @endforeach
                            @if (strlen($rechercheLieu) >= 2 && ! $lieuxTrouves)
                                <div style="font-size:13px;opacity:.65">Aucun lieu connu ne correspond.</div>
                            @endif
                            <div>
                                <x-filament::button color="primary" wire:click="decider({{ $element->id }}, 'rattacher')" wire:loading.attr="disabled" :disabled="! $lieuChoisi">Rattacher au lieu choisi</x-filament::button>
                                <div style="font-size:12px;opacity:.65;margin-top:4px">Ses séances passent au lieu choisi ; les collectes suivantes y iront directement.</div>
                            </div>
                        </div>
                    </div>
                    <a href="{{ $carte['lieuAVerifier']['fiche'] }}" style="font-size:13px;text-decoration:underline;opacity:.75">Ouvrir la fiche du lieu (adresse, position sur la carte)</a>
                @endif

                @if ($carte['proposition'])
                    <div style="border-radius:10px;padding:10px 12px;background:rgba(34,197,94,.10)">
                        <strong>L’IA propose :</strong> {{ $carte['proposition'] }}
                    </div>
                @elseif ($carte['avis'])
                    <div style="border-radius:10px;padding:10px 12px;background:rgba(245,158,11,.12)">
                        <strong>L’IA n’a pas tranché.</strong> {{ $carte['avis'] }}
                    </div>
                @endif

                <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start">
                    @if ($carte['proposition'])
                        <div style="max-width:260px">
                            <x-filament::button color="success" icon="heroicon-o-sparkles" wire:click="decider({{ $element->id }}, 'ia')" wire:loading.attr="disabled">
                                Appliquer la proposition de l’IA
                            </x-filament::button>
                        </div>
                    @endif
                    @foreach ($carte['boutons'] as $cle => [$libelle, $consequence, $couleur])
                        <div style="max-width:260px">
                            <x-filament::button :color="$couleur" wire:click="decider({{ $element->id }}, '{{ $cle }}')" wire:loading.attr="disabled">
                                {{ $libelle }}
                            </x-filament::button>
                            <div style="font-size:12px;opacity:.65;margin-top:4px">{{ $consequence }}</div>
                        </div>
                    @endforeach
                    <div>
                        <x-filament::button color="gray" outlined wire:click="plusTard({{ $element->id }})" wire:loading.attr="disabled">Plus tard</x-filament::button>
                        <div style="font-size:12px;opacity:.65;margin-top:4px">Passe à la carte suivante.</div>
                    </div>
                </div>

                @if ($carte['lien'])
                    <a href="{{ $carte['lien'] }}" style="font-size:13px;text-decoration:underline;opacity:.75">Ouvrir la file « {{ $carte['file'] }} » pour plus de détails</a>
                @endif
            </div>
        @else
            <div style="text-align:center;padding:24px 8px">
                <div style="font-size:20px;font-weight:600">Rien à faire aujourd’hui</div>
                @if ($remises > 0)
                    <p style="font-size:14px;opacity:.75;margin-top:8px">{{ $remises }} carte(s) remise(s) à plus tard.</p>
                    <div style="margin-top:8px"><x-filament::button color="gray" wire:click="toutReprendre">Les reprendre</x-filament::button></div>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
