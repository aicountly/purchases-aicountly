/**
 * Purchases' own look: a goods-in desk. Cool stone-grey surfaces, graphite bars, a deep plum for
 * the one thing to do next, and plain signal colours for status — approve green, reject red, late
 * amber, in-transit teal. Body type is Public Sans; document numbers, codes and quantities are
 * JetBrains Mono, so a PO number or a count reads like a label printed on the carton. Cards are
 * square-cornered with a graphite cap along the top edge.
 */

export const palette = {
  ink: '#26282D',
  inkRaised: '#33363C',
  inkLine: '#4A4E57',
  onInk: '#EEF0F3',
  onInkMuted: '#A7ADB8',
  paper: '#DCDFE3',
  sheet: '#F8F9FA',
  rule: '#CDD1D7',
  ruleStrong: '#AEB4BD',
  text: '#1C1E22',
  textSoft: '#43474F',
  textMuted: '#585D66',
  plum: '#6E2A80',
  plumDeep: '#511C5F',
  plumWash: '#EEE1F1',
  /** Plum for text and marks on the graphite bars. */
  plumLight: '#D3A6E0',
  approve: '#13784A',
  approveWash: '#DDF0E6',
  reject: '#C0271D',
  rejectWash: '#FADFDC',
  late: '#9A5200',
  lateWash: '#FBEBD3',
  transit: '#0F6E7A',
  transitWash: '#D9EEF0',
  white: '#FFFFFF',
} as const;

export const font = {
  regular: 'PublicSans_400Regular',
  medium: 'PublicSans_500Medium',
  semibold: 'PublicSans_600SemiBold',
  bold: 'PublicSans_700Bold',
  mono: 'JetBrainsMono_500Medium',
  monoBold: 'JetBrainsMono_700Bold',
} as const;

/** Type scale (size / line height). Labels are small caps-style: uppercase, tracked. */
export const type = {
  display: { fontFamily: font.bold, fontSize: 30, lineHeight: 36, letterSpacing: -0.4 },
  title: { fontFamily: font.semibold, fontSize: 22, lineHeight: 28, letterSpacing: -0.2 },
  heading: { fontFamily: font.semibold, fontSize: 17, lineHeight: 22 },
  body: { fontFamily: font.regular, fontSize: 15, lineHeight: 21 },
  bodyStrong: { fontFamily: font.medium, fontSize: 15, lineHeight: 21 },
  small: { fontFamily: font.regular, fontSize: 13, lineHeight: 18 },
  label: { fontFamily: font.semibold, fontSize: 11, lineHeight: 14, letterSpacing: 1.2, textTransform: 'uppercase' as const },
  docNo: { fontFamily: font.monoBold, fontSize: 14, lineHeight: 20, letterSpacing: 0 },
  figure: { fontFamily: font.monoBold, fontSize: 22, lineHeight: 28 },
} as const;

export const space = { xs: 4, s: 8, m: 12, l: 16, xl: 24, xxl: 32 } as const;

/** Square corners: cartons and labels, not bubbles. */
export const radius = { s: 2, m: 3, l: 6 } as const;

export const toneColors = {
  neutral: { fg: palette.textSoft, bg: '#E4E7EB' },
  waiting: { fg: palette.plumDeep, bg: palette.plumWash },
  go: { fg: palette.transit, bg: palette.transitWash },
  done: { fg: palette.approve, bg: palette.approveWash },
  stop: { fg: palette.reject, bg: palette.rejectWash },
  late: { fg: palette.late, bg: palette.lateWash },
} as const;
