// Espelho dos tokens da web (public/assets/css/clinical-botanical.css, :root) — NI2-25.
// As chaves de `cssVars` são os nomes das variáveis CSS sem o "--". Mudou lá, muda aqui:
// tests/ni2_25_design_tokens_test.php compara os dois arquivos valor a valor.
//
// Clinical Botanical é a paleta oficial. tokens.ts continua com os valores Premium antigos
// porque as telas importam dele; a migração das telas para este arquivo está pendente
// no card do app (NI2-140), para não brigar com aquela correção.

export const cssVars = {
  // Paleta oficial: Clinical Botanical
  'botanical-forest-950': '#0A2A10',
  'botanical-forest-900': '#0F3E17',
  'botanical-forest-800': '#1B5226',
  'botanical-green-700': '#2F6140',
  'botanical-green-600': '#39734A',
  'botanical-sage-300': '#BFD8C2',
  'botanical-sage-200': '#D3E5D5',
  'botanical-sage-100': '#E5F0E5',
  'botanical-cream-50': '#FAF9F4',
  'botanical-copper-400': '#F4A06A',
  'botanical-copper-500': '#EF8F4F',
  'botanical-copper-600': '#B04A1B',
  'botanical-copper-700': '#8F3D14',
  'botanical-copper-100': '#FBEADF',

  // Legado do DS Premium (deprecado): aliases com o valor já resolvido. Não use em código novo.
  'brand-navy-900': '#0F3E17',
  'brand-navy-reference': '#0F3E17',
  'brand-copper-500': '#EF8F4F',
  'brand-copper-600': '#B04A1B',
  'brand-copper-reference': '#EF8F4F',
  'brand-green-500': '#39734A',
  'brand-green-200': '#BFD8C2',

  // Neutros
  'neutral-warm-50': '#FAF9F4',
  'neutral-white': '#FFFFFF',
  'neutral-gray-100': '#F4F5F6',
  'neutral-gray-200': '#E5E7EB',
  'neutral-gray-300': '#D1D5DB',
  'neutral-gray-500': '#667085',
  'neutral-ink-900': '#1F2937',

  // Semânticos (independentes da marca; sucesso é teal)
  'semantic-success': '#0A7075',
  'semantic-success-light': '#E3F4F2',
  'semantic-success-text': '#0A5F63',
  'semantic-success-on-dark': '#4FC3B8',
  'semantic-warning': '#946200',
  'semantic-warning-light': '#FEF3C7',
  'semantic-error': '#B42318',
  'semantic-error-light': '#FEE2E2',
  'semantic-info': '#175CD3',
  'semantic-info-light': '#EFF6FF',

  // Raios (px)
  'radius-input': 8,
  'radius-button': 10,
  'radius-card': 12,
  'radius-modal': 16,
  'radius-pill': 9999,

  // Espaçamento (px)
  'space-1': 4,
  'space-2': 8,
  'space-3': 12,
  'space-4': 16,
  'space-5': 20,
  'space-6': 24,
  'space-8': 32,
  'space-10': 40,
  'space-12': 48,
  'space-16': 64,
  'space-20': 80,
  'space-24': 96,

  // Tipografia (px; 1rem = 16px)
  'font-size-caption': 12,
  'font-size-compact': 14,
  'font-size-body': 16,
  'font-size-card-title': 18,
  'font-size-h3': 20,
  'font-size-h2': 24,
  'font-size-h1': 32,
  'font-size-display': 40,
  'font-size-hero': 64,
  'line-height-tight': 1.25,
  'line-height-body': 1.55,
  'font-weight-regular': 400,
  'font-weight-medium': 500,
  'font-weight-semibold': 600,
  'font-weight-bold': 700,

  'touch-target-min': 44,
} as const;

// No React Native line-height é absoluto (px), não multiplicador.
export const lineHeightFor = (fontSize: number, ratio: number = cssVars['line-height-body']): number =>
  Math.round(fontSize * ratio);

// Sombras: o RN não lê box-shadow. Aproximação de --shadow-sm / --shadow-md / --shadow-lg.
export const shadows = {
  sm: { shadowColor: '#000000', shadowOffset: { width: 0, height: 1 }, shadowOpacity: 0.05, shadowRadius: 2, elevation: 1 },
  md: { shadowColor: '#000000', shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.05, shadowRadius: 6, elevation: 2 },
  lg: { shadowColor: '#000000', shadowOffset: { width: 0, height: 20 }, shadowOpacity: 0.1, shadowRadius: 25, elevation: 8 },
} as const;

// Inter só aparece no app depois de embutida com expo-font (pendente). Até lá, a fonte do sistema.
export const plannedFontFamily = { body: 'Inter', editorial: 'PlayfairDisplay' } as const;
