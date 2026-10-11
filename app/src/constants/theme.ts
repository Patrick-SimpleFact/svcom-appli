/**
 * Identité visuelle de Spettacoli, reprise des maquettes (canevas Spettacoli) :
 * fond crème, encre presque noire, accent rouge ; titres en Bricolage Grotesque, texte en DM Sans.
 */
export const Couleurs = {
  fond: '#F7F5F2',
  carte: '#FFFFFF',
  encre: '#16161D',
  gris: '#5B5B66',
  trait: '#E2DED8',
  traitCarte: '#E8E4DE',
  rouge: '#D23A1F',
} as const;

export const Polices = {
  titre: 'BricolageGrotesque_700Bold',
  titreFort: 'BricolageGrotesque_800ExtraBold',
  texte: 'DMSans_400Regular',
  texteMoyen: 'DMSans_500Medium',
  texteGras: 'DMSans_600SemiBold',
  texteTresGras: 'DMSans_700Bold',
} as const;

export const Espaces = {
  bord: 20,
  rayonCarte: 14,
} as const;
