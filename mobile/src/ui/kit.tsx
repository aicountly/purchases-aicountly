import { useRouter } from 'expo-router';
import React from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
  type StyleProp,
  type TextInputProps,
  type TextStyle,
  type ViewStyle,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import type { Problem } from '../core/problems';
import { Icon, type IconName } from './Icon';
import { palette, radius, space, toneColors, type } from './theme';

type Variant = keyof typeof type;

export function T({ v = 'body', color, style, children, ...rest }: { v?: Variant; color?: string; style?: StyleProp<TextStyle>; children: React.ReactNode; numberOfLines?: number; accessibilityRole?: 'header' | 'text'; selectable?: boolean }) {
  return (
    <Text style={[type[v], { color: color ?? palette.text }, style]} {...rest}>
      {children}
    </Text>
  );
}

type ButtonKind = 'signal' | 'approve' | 'reject' | 'ink' | 'quiet';

const BUTTON: Record<ButtonKind, { bg: string; fg: string; border: string }> = {
  signal: { bg: palette.signal, fg: palette.ink, border: palette.signal },
  approve: { bg: palette.approve, fg: palette.white, border: palette.approve },
  reject: { bg: palette.sheet, fg: palette.reject, border: palette.reject },
  ink: { bg: palette.ink, fg: palette.onInk, border: palette.ink },
  quiet: { bg: 'transparent', fg: palette.text, border: palette.ruleStrong },
};

export function Button({
  label,
  onPress,
  kind = 'signal',
  icon,
  busy,
  disabled,
  style,
  testID,
}: {
  label: string;
  onPress: () => void;
  kind?: ButtonKind;
  icon?: IconName;
  busy?: boolean;
  disabled?: boolean;
  style?: StyleProp<ViewStyle>;
  testID?: string;
}) {
  const c = BUTTON[kind];
  const off = disabled || busy;
  return (
    <Pressable
      testID={testID}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: !!off, busy: !!busy }}
      onPress={off ? undefined : onPress}
      style={({ pressed }) => [s.button, { backgroundColor: c.bg, borderColor: c.border, opacity: off ? 0.45 : pressed ? 0.8 : 1 }, style]}
    >
      {busy ? <ActivityIndicator color={c.fg} /> : icon ? <Icon name={icon} size={18} color={c.fg} /> : null}
      <Text style={[type.bodyStrong, { color: c.fg, fontFamily: 'IBMPlexSans_600SemiBold' }]}>{label}</Text>
    </Pressable>
  );
}

/** A status mark, drawn like an ink stamp: bordered, uppercase, tracked. */
export function Stamp({ label, tone }: { label: string; tone: keyof typeof toneColors }) {
  const c = toneColors[tone];
  return (
    <View style={[s.stamp, { borderColor: c.fg, backgroundColor: c.bg }]} accessibilityLabel={`Status: ${label}`}>
      <Text style={[type.label, { color: c.fg }]}>{label}</Text>
    </View>
  );
}

/** A docket: a sheet with a perforated left edge. */
export function Docket({ children, style, onPress, testID, accessibilityLabel }: { children: React.ReactNode; style?: StyleProp<ViewStyle>; onPress?: () => void; testID?: string; accessibilityLabel?: string }) {
  const body = (
    <View style={[s.docket, style]}>
      <View style={s.perforation}>
        {Array.from({ length: 6 }).map((_, i) => (
          <View key={i} style={s.hole} />
        ))}
      </View>
      <View style={{ flex: 1, padding: space.l }}>{children}</View>
    </View>
  );
  if (!onPress) return body;
  return (
    <Pressable testID={testID} accessibilityRole="button" accessibilityLabel={accessibilityLabel} onPress={onPress} style={({ pressed }) => ({ opacity: pressed ? 0.85 : 1 })}>
      {body}
    </Pressable>
  );
}

export function Rule({ style }: { style?: StyleProp<ViewStyle> }) {
  return <View style={[{ height: StyleSheet.hairlineWidth, backgroundColor: palette.ruleStrong, marginVertical: space.m }, style]} />;
}

export function Field({ label, hint, error, ...input }: TextInputProps & { label: string; hint?: string; error?: string }) {
  return (
    <View style={{ marginBottom: space.l }}>
      <T v="label" color={palette.textSoft}>
        {label}
      </T>
      <TextInput
        placeholderTextColor={palette.textMuted}
        accessibilityLabel={label}
        style={[s.input, error ? { borderColor: palette.reject } : null]}
        {...input}
      />
      {error ? (
        <T v="small" color={palette.reject}>
          {error}
        </T>
      ) : hint ? (
        <T v="small" color={palette.textMuted}>
          {hint}
        </T>
      ) : null}
    </View>
  );
}

/** The ink bar at the top of every signed-in screen. */
export function Bar({ title, subtitle, back = true, right }: { title: string; subtitle?: string; back?: boolean; right?: React.ReactNode }) {
  const router = useRouter();
  return (
    <SafeAreaView edges={['top']} style={{ backgroundColor: palette.ink }}>
      <View style={s.bar}>
        {back ? (
          <Pressable accessibilityRole="button" accessibilityLabel="Back" hitSlop={12} onPress={() => (router.canGoBack() ? router.back() : router.replace('/desk'))} style={{ marginRight: space.m }}>
            <Icon name="back" color={palette.onInk} />
          </Pressable>
        ) : null}
        <View style={{ flex: 1 }}>
          <T v="heading" color={palette.onInk} numberOfLines={1} accessibilityRole="header">
            {title}
          </T>
          {subtitle ? (
            <T v="small" color={palette.onInkMuted} numberOfLines={1}>
              {subtitle}
            </T>
          ) : null}
        </View>
        {right}
      </View>
    </SafeAreaView>
  );
}

export function Banner({ tone, icon, text, action }: { tone: keyof typeof toneColors; icon: IconName; text: string; action?: { label: string; onPress: () => void } }) {
  const c = toneColors[tone];
  return (
    <View style={[s.banner, { backgroundColor: c.bg, borderColor: c.fg }]} accessibilityRole="alert">
      <Icon name={icon} size={18} color={c.fg} />
      <T v="small" color={c.fg} style={{ flex: 1 }}>
        {text}
      </T>
      {action ? (
        <Pressable accessibilityRole="button" onPress={action.onPress} hitSlop={8}>
          <T v="label" color={c.fg}>
            {action.label}
          </T>
        </Pressable>
      ) : null}
    </View>
  );
}

/** Loading, empty, failed and offline, the same way on every screen. */
export function StatePanel(
  props:
    | { kind: 'loading'; text?: string }
    | { kind: 'empty'; title: string; text?: string; icon?: IconName; action?: { label: string; onPress: () => void } }
    | { kind: 'error'; problem: Problem; onRetry?: () => void },
) {
  if (props.kind === 'loading') {
    return (
      <View style={s.panel} testID="state-loading">
        <ActivityIndicator color={palette.signalDeep} />
        <T v="small" color={palette.textMuted} style={{ marginTop: space.s }}>
          {props.text ?? 'Loading…'}
        </T>
      </View>
    );
  }
  if (props.kind === 'empty') {
    return (
      <View style={s.panel} testID="state-empty">
        <Icon name={props.icon ?? 'check'} size={34} color={palette.textMuted} />
        <T v="heading" style={{ marginTop: space.m, textAlign: 'center' }}>
          {props.title}
        </T>
        {props.text ? (
          <T v="small" color={palette.textSoft} style={{ marginTop: space.xs, textAlign: 'center' }}>
            {props.text}
          </T>
        ) : null}
        {props.action ? <Button kind="quiet" label={props.action.label} onPress={props.action.onPress} style={{ marginTop: space.l }} /> : null}
      </View>
    );
  }
  const offline = props.problem.kind === 'offline';
  return (
    <View style={s.panel} testID={offline ? 'state-offline' : 'state-error'}>
      <Icon name={offline ? 'offline' : 'alert'} size={34} color={offline ? palette.steel : palette.reject} />
      <T v="heading" style={{ marginTop: space.m, textAlign: 'center' }}>
        {offline ? "You're offline" : props.problem.kind === 'no_access' ? 'No access here' : 'That did not load'}
      </T>
      <T v="small" color={palette.textSoft} style={{ marginTop: space.xs, textAlign: 'center' }}>
        {offline ? 'Connect to the internet to see the latest. Nothing is lost.' : props.problem.message}
      </T>
      {props.onRetry && props.problem.kind !== 'no_access' ? <Button kind="quiet" icon="refresh" label="Try again" onPress={props.onRetry} style={{ marginTop: space.l }} /> : null}
    </View>
  );
}

/** A bottom sheet asking for one piece of text (a reason), with a confirm button. */
export function ReasonSheet({
  visible,
  title,
  text,
  placeholder,
  confirmLabel,
  confirmKind = 'reject',
  minLength = 3,
  busy,
  error,
  onConfirm,
  onClose,
}: {
  visible: boolean;
  title: string;
  text: string;
  placeholder: string;
  confirmLabel: string;
  confirmKind?: ButtonKind;
  minLength?: number;
  busy?: boolean;
  error?: string | null;
  onConfirm: (reason: string) => void;
  onClose: () => void;
}) {
  const [reason, setReason] = React.useState('');
  React.useEffect(() => {
    if (visible) setReason('');
  }, [visible]);
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.sheetBackdrop}>
        <Pressable style={{ flex: 1 }} accessibilityLabel="Close" onPress={onClose} />
        <SafeAreaView edges={['bottom']} style={s.sheet}>
          <T v="title">{title}</T>
          <T v="small" color={palette.textSoft} style={{ marginTop: space.xs, marginBottom: space.l }}>
            {text}
          </T>
          <TextInput
            testID="reason-input"
            value={reason}
            onChangeText={setReason}
            placeholder={placeholder}
            placeholderTextColor={palette.textMuted}
            multiline
            accessibilityLabel={title}
            style={[s.input, { minHeight: 88, textAlignVertical: 'top' }]}
          />
          {error ? (
            <T v="small" color={palette.reject} style={{ marginTop: space.s }}>
              {error}
            </T>
          ) : null}
          <View style={{ flexDirection: 'row', gap: space.m, marginTop: space.l }}>
            <Button kind="quiet" label="Cancel" onPress={onClose} style={{ flex: 1 }} />
            <Button kind={confirmKind} label={confirmLabel} busy={busy} disabled={reason.trim().length < minLength} onPress={() => onConfirm(reason.trim())} style={{ flex: 1 }} testID="reason-confirm" />
          </View>
        </SafeAreaView>
      </KeyboardAvoidingView>
    </Modal>
  );
}

export function Page({ children, scroll = true, refresh }: { children: React.ReactNode; scroll?: boolean; refresh?: React.ReactElement }) {
  if (!scroll) return <View style={{ flex: 1, backgroundColor: palette.paper }}>{children}</View>;
  return (
    <ScrollView style={{ flex: 1, backgroundColor: palette.paper }} contentContainerStyle={{ padding: space.l, paddingBottom: space.xxl * 2 }} keyboardShouldPersistTaps="handled" refreshControl={refresh as never}>
      {children}
    </ScrollView>
  );
}

export function KeyValue({ k, v, mono }: { k: string; v: string; mono?: boolean }) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 4, gap: space.m }}>
      <T v="small" color={palette.textMuted}>
        {k}
      </T>
      <T v={mono ? 'docNo' : 'small'} style={{ flexShrink: 1, textAlign: 'right' }}>
        {v}
      </T>
    </View>
  );
}

const s = StyleSheet.create({
  button: {
    minHeight: 48,
    paddingHorizontal: space.l,
    borderRadius: radius.m,
    borderWidth: 1.5,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: space.s,
  },
  stamp: { alignSelf: 'flex-start', borderWidth: 1.5, borderRadius: radius.s, paddingHorizontal: 6, paddingVertical: 2 },
  docket: { flexDirection: 'row', backgroundColor: palette.sheet, borderRadius: radius.m, borderWidth: StyleSheet.hairlineWidth, borderColor: palette.ruleStrong, marginBottom: space.m, overflow: 'hidden' },
  perforation: { width: 10, backgroundColor: palette.paper, justifyContent: 'space-around', alignItems: 'center', borderRightWidth: 1, borderRightColor: palette.rule, borderStyle: 'dashed' },
  hole: { width: 4, height: 4, borderRadius: 2, backgroundColor: palette.ruleStrong },
  input: {
    marginTop: space.xs,
    borderWidth: 1,
    borderColor: palette.ruleStrong,
    borderRadius: radius.m,
    backgroundColor: palette.sheet,
    paddingHorizontal: space.m,
    paddingVertical: Platform.OS === 'ios' ? space.m : space.s,
    fontFamily: 'IBMPlexSans_400Regular',
    fontSize: 16,
    color: palette.text,
  },
  bar: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: space.l, paddingVertical: space.m, minHeight: 56 },
  banner: { flexDirection: 'row', alignItems: 'center', gap: space.s, padding: space.m, borderLeftWidth: 4, borderRadius: radius.s, marginBottom: space.m },
  panel: { alignItems: 'center', justifyContent: 'center', paddingVertical: space.xxl * 1.5, paddingHorizontal: space.xl },
  sheetBackdrop: { flex: 1, backgroundColor: 'rgba(27,26,23,0.45)' },
  sheet: { backgroundColor: palette.paper, padding: space.xl, borderTopLeftRadius: radius.l, borderTopRightRadius: radius.l },
});
