import { CameraView, useCameraPermissions, type BarcodeType } from 'expo-camera';
import * as Haptics from 'expo-haptics';
import { useRef, useState } from 'react';
import { Linking, Modal, Pressable, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Icon } from './Icon';
import { Button, T } from './kit';
import { palette, radius, space } from './theme';

/** Item barcodes and serial labels seen on goods: retail codes, logistics codes, 2D labels. */
const TYPES: BarcodeType[] = ['ean13', 'ean8', 'upc_a', 'upc_e', 'code128', 'code39', 'code93', 'itf14', 'codabar', 'qr', 'datamatrix'];

export interface ScanFeedback {
  tone: 'good' | 'warn' | 'bad';
  text: string;
}

/**
 * The camera, over the count. Each distinct code is handed to `onCode` once; the same code is
 * ignored for 1.5 s so a label held in frame is not counted ten times. Counting the same item
 * again means taking it out of frame and back.
 */
export function ScannerSheet({
  visible,
  title,
  feedback,
  onCode,
  onClose,
}: {
  visible: boolean;
  title: string;
  feedback: ScanFeedback | null;
  onCode: (code: string) => Promise<ScanFeedback>;
  onClose: () => void;
}) {
  const [permission, requestPermission] = useCameraPermissions();
  const [torch, setTorch] = useState(false);
  const last = useRef<{ code: string; at: number }>({ code: '', at: 0 });
  const busy = useRef(false);
  const [shown, setShown] = useState<ScanFeedback | null>(null);

  const handle = async (code: string) => {
    const now = Date.now();
    if (busy.current || (code === last.current.code && now - last.current.at < 1500)) return;
    last.current = { code, at: now };
    busy.current = true;
    try {
      const result = await onCode(code);
      setShown(result);
      Haptics.notificationAsync(result.tone === 'good' ? Haptics.NotificationFeedbackType.Success : result.tone === 'warn' ? Haptics.NotificationFeedbackType.Warning : Haptics.NotificationFeedbackType.Error).catch(() => undefined);
    } finally {
      busy.current = false;
    }
  };

  const message = shown ?? feedback;
  const toneColor = message?.tone === 'good' ? palette.approve : message?.tone === 'warn' ? palette.signal : palette.reject;

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose} presentationStyle="fullScreen">
      <View style={{ flex: 1, backgroundColor: palette.ink }}>
        {!permission ? null : !permission.granted ? (
          <SafeAreaView style={st.center}>
            <Icon name="scan" size={44} color={palette.signal} />
            <T v="title" color={palette.onInk} style={{ marginTop: space.l, textAlign: 'center' }}>
              Scan item barcodes
            </T>
            <T v="body" color={palette.onInkMuted} style={{ marginTop: space.s, textAlign: 'center' }}>
              Purchases uses the camera only to read barcodes on delivered goods. Nothing is photographed or kept.
            </T>
            {permission.canAskAgain ? (
              <Button label="Allow camera" onPress={() => void requestPermission()} style={{ marginTop: space.xl, alignSelf: 'stretch' }} />
            ) : (
              <Button label="Open Settings" onPress={() => void Linking.openSettings()} style={{ marginTop: space.xl, alignSelf: 'stretch' }} />
            )}
            <Button label="Count by hand instead" kind="quiet" onPress={onClose} style={{ marginTop: space.m, alignSelf: 'stretch', borderColor: palette.inkLine }} />
          </SafeAreaView>
        ) : (
          <>
            <CameraView
              style={StyleSheet.absoluteFill}
              facing="back"
              enableTorch={torch}
              barcodeScannerSettings={{ barcodeTypes: TYPES }}
              onBarcodeScanned={visible ? (r) => void handle(r.data) : undefined}
            />
            <SafeAreaView style={{ flex: 1, justifyContent: 'space-between' }} pointerEvents="box-none">
              <View style={st.top}>
                <T v="heading" color={palette.onInk} style={{ flex: 1 }} numberOfLines={1}>
                  {title}
                </T>
                <Pressable accessibilityRole="button" accessibilityLabel={torch ? 'Torch off' : 'Torch on'} onPress={() => setTorch((t) => !t)} hitSlop={10} style={[st.round, torch && { backgroundColor: palette.signal }]}>
                  <Icon name="torch" color={torch ? palette.ink : palette.onInk} />
                </Pressable>
              </View>
              <View style={st.frame} pointerEvents="none">
                <View style={[st.corner, { top: 0, left: 0, borderTopWidth: 4, borderLeftWidth: 4 }]} />
                <View style={[st.corner, { top: 0, right: 0, borderTopWidth: 4, borderRightWidth: 4 }]} />
                <View style={[st.corner, { bottom: 0, left: 0, borderBottomWidth: 4, borderLeftWidth: 4 }]} />
                <View style={[st.corner, { bottom: 0, right: 0, borderBottomWidth: 4, borderRightWidth: 4 }]} />
              </View>
              <View style={{ padding: space.l, gap: space.m }}>
                {message ? (
                  <View style={[st.toast, { borderLeftColor: toneColor }]} accessibilityLiveRegion="polite">
                    <T v="bodyStrong" color={palette.onInk}>
                      {message.text}
                    </T>
                  </View>
                ) : (
                  <View style={st.toast}>
                    <T v="small" color={palette.onInkMuted}>
                      Hold a barcode inside the frame.
                    </T>
                  </View>
                )}
                <Button label="Done scanning" kind="signal" onPress={onClose} />
              </View>
            </SafeAreaView>
          </>
        )}
      </View>
    </Modal>
  );
}

const st = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space.xl },
  top: { flexDirection: 'row', alignItems: 'center', padding: space.l, backgroundColor: 'rgba(27,26,23,0.7)' },
  round: { width: 44, height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: palette.inkLine },
  frame: { alignSelf: 'center', width: 260, height: 170 },
  corner: { position: 'absolute', width: 34, height: 34, borderColor: palette.signal },
  toast: { backgroundColor: 'rgba(27,26,23,0.88)', padding: space.m, borderRadius: radius.m, borderLeftWidth: 4, borderLeftColor: palette.inkLine },
});
