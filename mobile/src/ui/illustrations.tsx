import Svg, { Circle, G, Line, Path, Rect, Text as SvgText } from 'react-native-svg';
import { palette } from './theme';

/** The three welcome scenes, drawn for this app: an approved order, a counted crate, a tracked route. */

export function ApprovedOrder({ size = 220 }: { size?: number }) {
  return (
    <Svg width={size} height={size} viewBox="0 0 220 220" accessibilityElementsHidden importantForAccessibility="no">
      <Rect x={38} y={26} width={128} height={168} rx={2} fill={palette.sheet} stroke={palette.ink} strokeWidth={3} />
      <Rect x={38} y={26} width={128} height={12} fill={palette.ink} />
      <Line x1={54} y1={60} x2={148} y2={60} stroke={palette.ink} strokeWidth={4} />
      {[84, 104, 124].map((y) => (
        <Line key={y} x1={54} y1={y} x2={150} y2={y} stroke={palette.ruleStrong} strokeWidth={3} />
      ))}
      <G>
        <Rect x={92} y={136} width={102} height={42} rx={2} fill={palette.approveWash} stroke={palette.approve} strokeWidth={4} />
        <SvgText x={143} y={164} fontSize={18} fontWeight="bold" fill={palette.approve} textAnchor="middle" letterSpacing={2}>
          APPROVED
        </SvgText>
      </G>
      <Rect x={160} y={26} width={32} height={32} rx={2} fill={palette.plum} />
      <Path d="m169 42 5 5 9-10" stroke={palette.white} strokeWidth={3} fill="none" />
    </Svg>
  );
}

export function CountedCrate({ size = 220 }: { size?: number }) {
  return (
    <Svg width={size} height={size} viewBox="0 0 220 220" accessibilityElementsHidden importantForAccessibility="no">
      <Path d="M40 92 110 60l70 32v74l-70 32-70-32V92z" fill={palette.sheet} stroke={palette.ink} strokeWidth={3} />
      <Path d="M40 92l70 32 70-32M110 124v74" stroke={palette.ink} strokeWidth={3} fill="none" />
      <Path d="M58 120l36 16v24l-36-16v-24z" fill={palette.paper} stroke={palette.ink} strokeWidth={2} />
      {[0, 5, 9, 14, 18, 23, 27].map((x) => (
        <Line key={x} x1={63 + x} y1={127 + x * 0.44} x2={63 + x} y2={143 + x * 0.44} stroke={palette.ink} strokeWidth={2} />
      ))}
      <Path d="M150 34h22v22M72 34H50v22" stroke={palette.plum} strokeWidth={4} fill="none" />
      <Line x1={50} y1={70} x2={172} y2={70} stroke={palette.plum} strokeWidth={3} strokeDasharray="6 5" />
      <Rect x={140} y={150} width={56} height={34} rx={4} fill={palette.ink} />
      <SvgText x={168} y={173} fontSize={18} fontWeight="bold" fill={palette.plumLight} textAnchor="middle">
        12/12
      </SvgText>
    </Svg>
  );
}

export function TrackedRoute({ size = 220 }: { size?: number }) {
  const stops = [
    { x: 46, y: 54, done: true },
    { x: 110, y: 54, done: true },
    { x: 174, y: 110, done: true },
    { x: 110, y: 166, done: false },
    { x: 46, y: 166, done: false },
  ];
  return (
    <Svg width={size} height={size} viewBox="0 0 220 220" accessibilityElementsHidden importantForAccessibility="no">
      <Path d="M46 54h64a56 56 0 0 1 0 112H46" stroke={palette.ruleStrong} strokeWidth={6} fill="none" />
      <Path d="M46 54h64a56 56 0 0 1 64 56" stroke={palette.ink} strokeWidth={6} fill="none" />
      {stops.map((p, i) => (
        <Circle key={i} cx={p.x} cy={p.y} r={11} fill={p.done ? palette.ink : palette.sheet} stroke={palette.ink} strokeWidth={3} />
      ))}
      <Circle cx={174} cy={110} r={18} fill="none" stroke={palette.plum} strokeWidth={4} />
      <Rect x={70} y={92} width={60} height={36} rx={4} fill={palette.sheet} stroke={palette.ink} strokeWidth={2} />
      <Line x1={80} y1={104} x2={120} y2={104} stroke={palette.ink} strokeWidth={3} />
      <Line x1={80} y1={116} x2={108} y2={116} stroke={palette.ruleStrong} strokeWidth={3} />
    </Svg>
  );
}
