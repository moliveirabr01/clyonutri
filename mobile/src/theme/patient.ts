/** Patient reference palette. Professional screens retain theme/tokens.ts. */
export const patientTokens = {
  navy: '#183D32', copper: '#087C53', copperAccent: '#F5B746',
  background: '#F5F7F2', white: '#FFFFFF', ink: '#202E28', muted: '#57675E',
  border: '#D6E1D8', inputBorder: '#7A8D80', divider: '#E3EBE3',
  error: '#AC302B', errorBg: '#FFF1EF', success: '#136B47', successBg: '#E6F5EB',
  info: '#285D51', mint: '#BFEADB', mintSoft: '#EDF7EF', forest: '#102D28',
  onForest: '#F8FFF9', onForestMuted: '#C1D8CC', accent: '#F5B746',
  accentInk: '#50360C', chartWash: '#ECF4DF',
  inputRadius: 14, buttonRadius: 24, cardRadius: 22, space: 16, touch: 48,
  maxNavFontScale: 1.5,
} as const;
export const patientType = {
  title: { fontSize: 28, lineHeight: 35, fontWeight: '700' as const },
  section: { fontSize: 21, lineHeight: 28, fontWeight: '700' as const },
  body: { fontSize: 15, lineHeight: 23 },
  caption: { fontSize: 12, lineHeight: 18 },
};
