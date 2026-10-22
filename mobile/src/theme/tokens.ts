// Espelha public/assets/css/clinical-botanical.css (DS NI2-3). Se mudar lá, mude aqui e rode
// tests/ni2_140_patient_app_design_a11y_test.php, que confere os valores e os contrastes.
// - copper (#B94F1F, copper-600) é a cor de ação: texto branco sobre ele dá 5,0:1.
// - copperAccent (#E67A3F, copper-500) é só decorativo: com branco dá 2,9:1, então nunca vai em fundo de botão nem em texto.
// - inputBorder (#858D9C) dá 3,3:1 sobre branco, o mínimo para borda de campo (WCAG 1.4.11).
export const tokens = {
  navy: '#0F2747', copper: '#B94F1F', copperAccent: '#E67A3F', background: '#FAF7F2', white: '#FFFFFF',
  ink: '#1F2937', muted: '#667085', border: '#D1D5DB', inputBorder: '#858D9C', divider: '#E5E7EB',
  error: '#B42318', errorBg: '#FEF3F2', success: '#18794E', successBg: '#ECFDF3', info: '#175CD3',
  inputRadius: 8, buttonRadius: 10, cardRadius: 12, space: 16,
  // Toque mínimo (WCAG 2.5.8 pede 24; usamos 44 como no iOS/Android).
  touch: 44,
  // Rótulos curtos (abas) param de crescer em 1,5x para não quebrar a barra; o resto do texto acompanha o sistema.
  maxNavFontScale: 1.5,
};
