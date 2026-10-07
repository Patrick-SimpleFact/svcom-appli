<?php

namespace App\Enums;

/** Réponse d'un appareil à la question des suggestions (F6.2). */
enum ChoixSuggestion: string
{
    case NonDemande = 'non_demande';
    case Oui = 'oui';
    case NonMerci = 'non_merci';

    /** Désactivé volontairement dans le profil : jamais relancé. */
    case Desactive = 'desactive';
}
