import AsyncStorage from '@react-native-async-storage/async-storage';
import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { Platform } from 'react-native';

import { appelApi, BUILD_APP, comparerVersions, quandVersionObsolete, VERSION_APP } from '@/api/client';
import { jetonNatif } from '@/notifications/notifications';

/** Message de service saisi dans le BO (F2.8) : maintenance, incident… */
export type MessageService = { id: number; type: 'info' | 'alerte'; texte: string };

/** Configuration du moment renvoyée par `POST /v1/appareils` (API §2). */
export type Configuration = {
  version_minimale: string;
  /** Dernière version publiée de chaque plateforme (réglages du BO) : plus récente que l'app → mise à jour proposée. */
  dernier_build?: { ios: number; android: number };
  parametres: {
    rayon_auto_min_representations: number;
    seuil_couverture_faible: number;
    delais_messages_s: number[];
    paliers_prix: number[];
    pistes_max_par_jour: number;
  };
  genres: { id: number; slug: string; libelle: string }[];
  motifs_signalement: string[];
  messages_service: MessageService[];
  poser_question_suggestion: boolean;
  /** Villes pilotes cochées dans le BO, la plus peuplée d'abord (Rechercher). */
  villes_pilotes: { id: number; nom: string; lat: number; lon: number }[];
  liens: Record<string, string>;
};

type Etat = {
  /** `null` tant que le BO n'a pas répondu (ou s'il est injoignable : l'app continue sans). */
  configuration: Configuration | null;
  /** `true` dès que le BO a répondu ou a échoué : on sait alors s'il y aura une configuration. */
  configurationTerminee: boolean;
  versionObsolete: boolean;
  /** Une version plus récente est publiée : bandeau « Mettre à jour », sans bloquer l'app. */
  miseAJourDisponible: boolean;
  /** « Plus tard » : le bandeau de mise à jour revient à la prochaine ouverture de l'app. */
  fermerMiseAJour: () => void;
  messagesAffiches: MessageService[];
  fermerMessage: (id: number) => void;
};

const CLE_FERMES = 'spettacoli.messages_fermes';

const Contexte = createContext<Etat>({ configuration: null, configurationTerminee: false, versionObsolete: false, miseAJourDisponible: false, fermerMiseAJour: () => {}, messagesAffiches: [], fermerMessage: () => {} });

export const useDemarrage = () => useContext(Contexte);

/** Messages encore à afficher : ceux du BO, moins ceux que l'utilisateur a fermés. */
/** Le BO annonce un build plus récent que celui installé sur cette plateforme. */
export function miseAJourProposee(dernier: Configuration['dernier_build'], plateforme: 'ios' | 'android', build: number): boolean {
  return build > 0 && (dernier?.[plateforme] ?? 0) > build;
}

export function messagesAAfficher(messages: MessageService[], fermes: number[]): MessageService[] {
  return messages.filter((m) => !fermes.includes(m.id));
}

/**
 * Démarrage de l'app (M02, API §2) : à chaque ouverture, l'appareil s'annonce au BO et reçoit la configuration du moment
 * (paramètres, genres, messages de service, version minimale).
 */
export function Demarrage({ children }: { children: ReactNode }) {
  const [configuration, setConfiguration] = useState<Configuration | null>(null);
  const [configurationTerminee, setConfigurationTerminee] = useState(false);
  const [versionObsolete, setVersionObsolete] = useState(false);
  const [miseAJourFermee, setMiseAJourFermee] = useState(false);
  const [fermes, setFermes] = useState<number[]>([]);

  useEffect(() => {
    quandVersionObsolete(() => setVersionObsolete(true));

    AsyncStorage.getItem(CLE_FERMES)
      .then((json) => setFermes(json ? (JSON.parse(json) as number[]) : []))
      .catch(() => {});

    // Jeton de notification si l'autorisation est déjà donnée (F3.4, M12b) ; jamais de position à cette étape.
    jetonNatif()
      .catch(() => null)
      .then((jeton) =>
        appelApi<Configuration>('/v1/appareils', {
          methode: 'POST',
          corps: { plateforme: Platform.OS === 'android' ? 'android' : 'ios', ...(jeton ? { jeton_push: jeton } : {}) },
        }),
      )
      .then((config) => {
        setConfiguration(config);
        if (comparerVersions(VERSION_APP, config.version_minimale) < 0) {
          setVersionObsolete(true);
        }
      })
      // Réseau ou BO indisponible : l'app continue sans configuration (les écrans d'erreur arrivent en M05).
      .catch(() => {})
      .finally(() => setConfigurationTerminee(true));

    return () => quandVersionObsolete(null);
  }, []);

  const fermerMessage = useCallback((id: number) => {
    setFermes((avant) => {
      // Seuls les messages encore actifs sont gardés, pour que la liste ne grossisse pas indéfiniment.
      const actifs = new Set(configuration?.messages_service.map((m) => m.id));
      const apres = [...avant.filter((f) => actifs.has(f)), id];
      AsyncStorage.setItem(CLE_FERMES, JSON.stringify(apres)).catch(() => {});

      return apres;
    });
  }, [configuration]);

  const valeur = useMemo(
    () => ({
      configuration,
      configurationTerminee,
      versionObsolete,
      miseAJourDisponible: !miseAJourFermee && miseAJourProposee(configuration?.dernier_build, Platform.OS === 'android' ? 'android' : 'ios', BUILD_APP),
      fermerMiseAJour: () => setMiseAJourFermee(true),
      messagesAffiches: messagesAAfficher(configuration?.messages_service ?? [], fermes),
      fermerMessage,
    }),
    [configuration, configurationTerminee, versionObsolete, miseAJourFermee, fermes, fermerMessage],
  );

  return <Contexte.Provider value={valeur}>{children}</Contexte.Provider>;
}
