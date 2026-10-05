<?php

namespace App\Actions;

use App\Models\Offre;
use App\Models\Spectacle;
use App\Support\Texte;
use Illuminate\Database\Eloquent\Builder;

/**
 * Étape « spectacle » de la chaîne (COLLECTE §7.3, F5.3) : chaque séance collectée est rattachée à un spectacle,
 * pour les « Autres dates » et les tournées. Dans l'ordre :
 * 1. le spectacle de son groupe de séances (même séance vendue par une autre billetterie, K06) ;
 * 2. même titre et même source (« Edmond » vendu par BilletRéduc à Paris et à Lyon) ;
 * 3. même titre et même lieu, quelle que soit la source (BilletRéduc le vendredi, la Fnac le samedi ; décidé le 05/10/2026) ;
 * 4. même titre et au moins un artiste en commun, quelle que soit la source ;
 * 5. sinon un nouveau spectacle.
 * Un titre générique (« Concert », « Spectacle de Noël ») n'est jamais regroupé entre deux lieux différents.
 * Le spectacle n'est visible dans l'app qu'avec des représentations (K08).
 */
class RattacherSpectacle
{
    /** Mots qui, seuls, ne désignent pas un spectacle précis. */
    private const MOTS_GENERIQUES = [
        'concert', 'concerts', 'spectacle', 'spectacles', 'noel', 'soiree', 'gala', 'recital', 'fete', 'fetes', 'musique',
        'musiques', 'theatre', 'humour', 'danse', 'cirque', 'opera', 'festival', 'bal', 'apero', 'cabaret', 'piano',
        'jazz', 'orgue', 'chorale', 'choeur', 'conte', 'contes', 'lecture', 'scene', 'ouverte', 'jam', 'session',
        'enfants', 'enfant', 'jeune', 'public', 'famille', 'improvisation', 'impro', 'match', 'nouvel', 'an',
        'annee', 'printemps', 'ete', 'automne', 'hiver', 'classique', 'live', 'dj', 'set', 'gratuit',
    ];

    public function handle(Offre $offre): Spectacle
    {
        $spectacle = $this->spectacleDuGroupe($offre)
            ?? $this->memeTitreMemeSource($offre)
            ?? $this->premierSpectacle($this->memeTitre($offre)->where('lieu_id', $offre->lieu_id), $offre)
            ?? $this->memeTitreMemesArtistes($offre)
            ?? $this->creer($offre);

        // Toutes les offres de la séance suivent le même spectacle.
        $premiere = $offre->meme_seance_que_id ?? $offre->id;
        Offre::where(fn ($q) => $q->whereKey($premiere)->orWhere('meme_seance_que_id', $premiere))
            ->update(['spectacle_id' => $spectacle->id]);

        return $spectacle;
    }

    public function estGenerique(?string $titreComparable): bool
    {
        $mots = array_filter(explode(' ', (string) $titreComparable));

        return mb_strlen((string) $titreComparable) < 4 || array_diff($mots, self::MOTS_GENERIQUES) === [];
    }

    private function spectacleDuGroupe(Offre $offre): ?Spectacle
    {
        $premiere = $offre->meme_seance_que_id ?? $offre->id;
        $id = Offre::where(fn ($q) => $q->whereKey($premiere)->orWhere('meme_seance_que_id', $premiere))
            ->whereKeyNot($offre->id)
            ->whereNotNull('spectacle_id')
            ->orderBy('id')
            ->value('spectacle_id');

        return $id ? Spectacle::find($id) : null;
    }

    private function memeTitreMemeSource(Offre $offre): ?Spectacle
    {
        return $this->premierSpectacle($this->memeTitre($offre)->where('source_id', $offre->source_id), $offre);
    }

    private function memeTitreMemesArtistes(Offre $offre): ?Spectacle
    {
        $artistes = $this->artistes($offre);

        if ($artistes === []) {
            return null;
        }

        $candidates = $this->memeTitre($offre)->where('source_id', '!=', $offre->source_id)->get()
            ->filter(fn (Offre $autre) => array_intersect($artistes, $this->artistes($autre)) !== []);

        return $this->premierSpectacle(Offre::whereKey($candidates->modelKeys()), $offre);
    }

    /** Autres offres au même titre, déjà rattachées (un titre générique : seulement dans le même lieu). */
    private function memeTitre(Offre $offre): Builder
    {
        return Offre::whereKeyNot($offre->id)
            ->whereNotNull('spectacle_id')
            ->where('titre_comparable', $offre->titre_comparable)
            ->when($this->estGenerique($offre->titre_comparable), fn ($q) => $q->where('lieu_id', $offre->lieu_id));
    }

    private function premierSpectacle(Builder $offres, Offre $offre): ?Spectacle
    {
        if ($offre->titre_comparable === null || $offre->titre_comparable === '') {
            return null;
        }

        // Le plus ancien spectacle : MIN plutôt que ORDER BY id LIMIT 1, qui ferait parcourir toute la table
        // dans l'ordre des identifiants au lieu d'utiliser l'index sur le titre (30 ms par séance chez BilletRéduc).
        $id = $offres->min('spectacle_id');

        return $id ? Spectacle::find($id) : null;
    }

    /** @return list<string> */
    private function artistes(Offre $offre): array
    {
        return array_values(array_filter(array_map(Texte::normaliser(...), $offre->donnees_normalisees['artistes'] ?? [])));
    }

    private function creer(Offre $offre): Spectacle
    {
        $donnees = $offre->donnees_normalisees;

        // Création automatique : pas de verrouillage ni de journal (ce n'est pas une correction à la main).
        return Spectacle::withoutEvents(fn () => Spectacle::create([
            'titre' => $donnees['titre'],
            'description' => $donnees['description'] ?? null,
            'genre_id' => $offre->genre_id,
            'classification_fine' => $donnees['categories_source'][0] ?? null,
            'jeune_public' => $offre->jeune_public,
            'image_url' => $donnees['image_url'] ?? null,
        ]));
    }
}
