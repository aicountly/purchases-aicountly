import React from 'react';
import Svg, { Circle, Path, Rect } from 'react-native-svg';
import { palette } from './theme';

/**
 * Purchases' own glyphs: drawn on a 24-unit grid with a 1.8 stroke and square ends, like marks
 * on a docket. No icon font.
 */

export type IconName =
  | 'stamp'
  | 'crate'
  | 'scan'
  | 'ledger'
  | 'route'
  | 'check'
  | 'cross'
  | 'next'
  | 'back'
  | 'plus'
  | 'minus'
  | 'alert'
  | 'offline'
  | 'search'
  | 'person'
  | 'desk'
  | 'exit'
  | 'bin'
  | 'refresh'
  | 'clock'
  | 'torch'
  | 'tag'
  | 'building';

const SHAPES: Record<IconName, (c: string) => React.ReactNode> = {
  stamp: (c) => (
    <>
      <Path d="M9 3h6v4.5c0 1.5 1 2.5 2.5 3.2L19 11.5V15H5v-3.5l1.5-.8C8 10 9 9 9 7.5V3z" stroke={c} />
      <Path d="M4 19h16" stroke={c} />
    </>
  ),
  crate: (c) => (
    <>
      <Path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9z" stroke={c} />
      <Path d="M3 7.5 12 12l9-4.5M12 12v9" stroke={c} />
    </>
  ),
  scan: (c) => (
    <>
      <Path d="M3 8V4h4M17 4h4v4M21 16v4h-4M7 20H3v-4" stroke={c} />
      <Path d="M7 8v8M10 8v8M13 8v8M16.5 8v8" stroke={c} />
    </>
  ),
  ledger: (c) => (
    <>
      <Rect x={4} y={3} width={16} height={18} rx={1} stroke={c} />
      <Path d="M8 3v18M11 8h6M11 12h6M11 16h4" stroke={c} />
    </>
  ),
  route: (c) => (
    <>
      <Circle cx={6} cy={6} r={2.2} stroke={c} />
      <Circle cx={18} cy={18} r={2.2} stroke={c} />
      <Path d="M8.2 6H15a3 3 0 0 1 0 6H9a3 3 0 0 0 0 6h6.8" stroke={c} />
    </>
  ),
  check: (c) => <Path d="m4.5 12.5 4.5 4.5L19.5 6.5" stroke={c} />,
  cross: (c) => <Path d="M6 6l12 12M18 6 6 18" stroke={c} />,
  next: (c) => <Path d="m9 5 7 7-7 7" stroke={c} />,
  back: (c) => <Path d="m15 5-7 7 7 7" stroke={c} />,
  plus: (c) => <Path d="M12 5v14M5 12h14" stroke={c} />,
  minus: (c) => <Path d="M5 12h14" stroke={c} />,
  alert: (c) => (
    <>
      <Path d="M12 3 22 20H2L12 3z" stroke={c} />
      <Path d="M12 9.5v5M12 17v.5" stroke={c} />
    </>
  ),
  offline: (c) => (
    <>
      <Path d="M3 3l18 18" stroke={c} />
      <Path d="M8.5 6.4A6 6 0 0 1 17.6 11 4 4 0 0 1 19.4 18M15 19H7a4.5 4.5 0 0 1-1.4-8.8" stroke={c} />
    </>
  ),
  search: (c) => (
    <>
      <Circle cx={10.5} cy={10.5} r={6} stroke={c} />
      <Path d="m15 15 5.5 5.5" stroke={c} />
    </>
  ),
  person: (c) => (
    <>
      <Circle cx={12} cy={8} r={4} stroke={c} />
      <Path d="M4 21c1-4.5 4.3-6.5 8-6.5s7 2 8 6.5" stroke={c} />
    </>
  ),
  desk: (c) => (
    <>
      <Rect x={3} y={4} width={18} height={12} rx={1} stroke={c} />
      <Path d="M9 20h6M12 16v4" stroke={c} />
    </>
  ),
  exit: (c) => (
    <>
      <Path d="M10 4H5v16h5" stroke={c} />
      <Path d="M14 8l4 4-4 4M18 12H9" stroke={c} />
    </>
  ),
  bin: (c) => (
    <>
      <Path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13" stroke={c} />
    </>
  ),
  refresh: (c) => (
    <>
      <Path d="M20 11a8 8 0 1 0-2.3 5.7" stroke={c} />
      <Path d="M20 4v7h-7" stroke={c} />
    </>
  ),
  clock: (c) => (
    <>
      <Circle cx={12} cy={12} r={8.5} stroke={c} />
      <Path d="M12 7v5l3.5 2" stroke={c} />
    </>
  ),
  torch: (c) => <Path d="M13 3 6 13h5l-1 8 7-10h-5l1-8z" stroke={c} />,
  tag: (c) => (
    <>
      <Path d="M3 12V4h8l10 10-8 8L3 12z" stroke={c} />
      <Circle cx={7.5} cy={8} r={1.3} stroke={c} />
    </>
  ),
  building: (c) => (
    <>
      <Path d="M4 21V5l8-2v18M12 8l8 2v11M2 21h20" stroke={c} />
      <Path d="M7.5 9h1M7.5 13h1M7.5 17h1M15.5 13h1M15.5 17h1" stroke={c} />
    </>
  ),
};

export function Icon({ name, size = 22, color = palette.text, strokeWidth = 1.8 }: { name: IconName; size?: number; color?: string; strokeWidth?: number }) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" fill="none" strokeWidth={strokeWidth} strokeLinecap="square" strokeLinejoin="miter" accessibilityElementsHidden importantForAccessibility="no">
      {SHAPES[name](color)}
    </Svg>
  );
}
