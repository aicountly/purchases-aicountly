/**
 * Purchases' own look: a docket desk. Warm paper, ink-black bars, a saffron "signal" for the
 * one thing to do next, and decisions in two unmistakable colours — approve green, reject red.
 * Type is IBM Plex Sans, with Plex Mono for document numbers and quantities, so a PO number or
 * a count reads like it does on the printed docket.
 */

export const palette = {
  ink: '#1B1A17',
  inkRaised: '#2A2823',
  inkLine: '#3D3A33',
  onInk: '#F3EEE4',
  onInkMuted: '#B9B1A2',
  paper: '#F3EEE4',
  sheet: '#FFFDF8',
  rule: '#DDD5C7',
  ruleStrong: '#C6BBA8',
  text: '#1B1A17',
  textSoft: '#4D483F',
  textMuted: '#7A7366',
  signal: '#E3A008',
  signalDeep: '#8A5B00',
  signalWash: '#FBF0D2',
  approve: '#1F6B45',
  approveWash: '#E2F0E7',
  reject: '#B42318',
  rejectWash: '#FBE4E1',
  late: '#A15C07',
  lateWash: '#FCEFD9',
  steel: '#2C5866',
  steelWash: '#E1ECEF',
  white: '#FFFFFF',
} as const;

export const font = {
  regular: 'IBMPlexSans_400Regular',
  medium: 'IBMPlexSans_500Medium',
  semibold: 'IBMPlexSans_600SemiBold',
  bold: 'IBMPlexSans_700Bold',
  mono: 'IBMPlexMono_500Medium',
  monoBold: 'IBMPlexMono_600SemiBold',
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
  docNo: { fontFamily: font.monoBold, fontSize: 15, lineHeight: 20, letterSpacing: 0.2 },
  figure: { fontFamily: font.monoBold, fontSize: 24, lineHeight: 28 },
} as const;

export const space = { xs: 4, s: 8, m: 12, l: 16, xl: 24, xxl: 32 } as const;

/** Square-ish corners: dockets and stamps, not bubbles. */
export const radius = { s: 4, m: 6, l: 10 } as const;

export const toneColors = {
  neutral: { fg: palette.textSoft, bg: '#ECE6DA' },
  waiting: { fg: palette.signalDeep, bg: palette.signalWash },
  go: { fg: palette.steel, bg: palette.steelWash },
  done: { fg: palette.approve, bg: palette.approveWash },
  stop: { fg: palette.reject, bg: palette.rejectWash },
  late: { fg: palette.late, bg: palette.lateWash },
} as const;
