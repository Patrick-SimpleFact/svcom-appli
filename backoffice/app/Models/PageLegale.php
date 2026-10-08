<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Page légale (F1.6) : texte Markdown modifiable dans le back-office, avec sa date de mise à jour. */
class PageLegale extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    public const SLUGS = ['confidentialite', 'conditions', 'mentions-legales'];

    /** Repère des passages à compléter avant la mise en ligne. */
    public const A_COMPLETER = '[À COMPLÉTER';

    protected $table = 'pages_legales';

    protected $fillable = ['titre', 'contenu', 'mis_a_jour_le'];

    protected function casts(): array
    {
        return ['mis_a_jour_le' => 'date'];
    }

    /** HTML de la page : Markdown sans HTML brut ni lien dangereux ; les passages à compléter sont surlignés. */
    public function html(): string
    {
        $html = Str::markdown($this->contenu, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        return preg_replace('/\[À COMPLÉTER[^\]]*\]/u', '<mark>$0</mark>', $html);
    }

    public function aCompleter(): int
    {
        return mb_substr_count($this->contenu, self::A_COMPLETER);
    }

    public function url(): string
    {
        return url('/'.$this->slug);
    }
}
