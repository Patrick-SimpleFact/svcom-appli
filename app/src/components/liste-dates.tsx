import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';

import { CarteSansHoraire, CarteSpectacle, ouvrirFiche } from '@/components/carte-spectacle';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { useDemarrage } from '@/demarrage/demarrage';
import { jourLong } from '@/fiche/fiche';
import { type CarteRecherche, versCarte } from '@/fiche/pages';

/** Dates à venir d'un lieu ou d'un artiste, jour par jour (maquettes « Page lieu » et « Page artiste »). */
export function ListeDates(props: {
  jours: { date: string; cartes: CarteRecherche[] }[];
  position: { lat: number; lon: number } | null;
  origine: 'page_lieu' | 'page_artiste';
  suite: boolean;
  vide: string;
}) {
  const { configuration } = useDemarrage();
  const libelleGenre = (id: number) => configuration?.genres.find((g) => g.id === id)?.libelle ?? '';

  if (props.jours.length === 0) {
    return (
      <View style={styles.vide}>
        <Text style={styles.videTitre}>Aucune date à venir dans nos sources</Text>
        <Text style={styles.videTexte}>{props.vide}</Text>
      </View>
    );
  }

  return (
    <View style={styles.liste}>
      {props.jours.map((jour) => (
        <View key={jour.date} style={styles.jour}>
          <Text style={styles.jourTitre}>{jourLong(jour.date)}</Text>
          {jour.cartes.map((c) => {
            const carte = versCarte(c);
            const ouvrir = () =>
              ouvrirFiche(c.spectacle.id, 'horaire' in carte ? carte.horaire.seances[0]?.representation_id : carte.journee.representation_id, props.position, props.origine);

            return 'horaire' in carte ? (
              <CarteSpectacle key={`${c.spectacle.id}-${c.lieu.id}-${c.date_locale}`} carte={carte.horaire} genre={libelleGenre(c.spectacle.genre_id)} onPress={ouvrir} />
            ) : (
              <CarteSansHoraire key={`${c.spectacle.id}-${carte.journee.representation_id}`} carte={carte.journee} genre={libelleGenre(c.spectacle.genre_id)} onPress={ouvrir} />
            );
          })}
        </View>
      ))}
      {props.suite ? <ActivityIndicator color={Couleurs.rouge} style={styles.suite} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  liste: { gap: 10 },
  jour: { gap: 8 },
  jourTitre: { marginTop: 6, fontFamily: Polices.texteTresGras, fontSize: 13, letterSpacing: 0.8, textTransform: 'uppercase', color: Couleurs.gris },
  vide: { gap: 6, paddingVertical: 18, paddingHorizontal: 16, borderRadius: Espaces.rayonCarte, backgroundColor: Couleurs.carte, borderWidth: 1, borderColor: Couleurs.traitCarte },
  videTitre: { fontFamily: Polices.texteTresGras, fontSize: 15, color: Couleurs.encre },
  videTexte: { fontFamily: Polices.texte, fontSize: 14, lineHeight: 20, color: '#4A4A55' },
  suite: { marginVertical: 16 },
});
