import type { ErrorBoundaryProps } from 'expo-router';
import { View } from 'react-native';
import { Icon } from './Icon';
import { Button, T } from './kit';
import { palette, space } from './theme';

/** Expo Router's error boundary for every route: a calm screen and a way back, never a white page. */
export function CrashScreen({ retry }: ErrorBoundaryProps) {
  return (
    <View style={{ flex: 1, backgroundColor: palette.paper, alignItems: 'center', justifyContent: 'center', padding: space.xl }}>
      <Icon name="alert" size={40} color={palette.reject} />
      <T v="title" style={{ marginTop: space.l, textAlign: 'center' }}>
        This screen stopped working
      </T>
      <T v="small" color={palette.textSoft} style={{ marginTop: space.s, textAlign: 'center' }}>
        Nothing you had already recorded is affected. Try the screen again.
      </T>
      <Button label="Try again" icon="refresh" onPress={() => void retry()} style={{ marginTop: space.xl, alignSelf: 'stretch' }} />
    </View>
  );
}
