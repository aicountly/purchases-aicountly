import { IBMPlexMono_500Medium } from '@expo-google-fonts/ibm-plex-mono/500Medium';
import { IBMPlexMono_600SemiBold } from '@expo-google-fonts/ibm-plex-mono/600SemiBold';
import { IBMPlexSans_400Regular } from '@expo-google-fonts/ibm-plex-sans/400Regular';
import { IBMPlexSans_500Medium } from '@expo-google-fonts/ibm-plex-sans/500Medium';
import { IBMPlexSans_600SemiBold } from '@expo-google-fonts/ibm-plex-sans/600SemiBold';
import { IBMPlexSans_700Bold } from '@expo-google-fonts/ibm-plex-sans/700Bold';
import { useFonts } from 'expo-font';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { IdentityProvider } from '../identity/IdentityProvider';
import { NetworkProvider } from '../net/NetworkProvider';
import { ScopeProvider } from '../scope/ScopeProvider';
import { CrashScreen } from '../ui/CrashScreen';
import { palette } from '../ui/theme';

SplashScreen.preventAutoHideAsync().catch(() => undefined);

export { CrashScreen as ErrorBoundary };

export default function RootLayout() {
  const [fontsReady, fontError] = useFonts({
    IBMPlexSans_400Regular,
    IBMPlexSans_500Medium,
    IBMPlexSans_600SemiBold,
    IBMPlexSans_700Bold,
    IBMPlexMono_500Medium,
    IBMPlexMono_600SemiBold,
  });

  useEffect(() => {
    if (fontsReady || fontError) SplashScreen.hideAsync().catch(() => undefined);
  }, [fontsReady, fontError]);

  // A font that fails to load falls back to the system face; the app still opens.
  if (!fontsReady && !fontError) return null;

  return (
    <SafeAreaProvider>
      <NetworkProvider>
        <IdentityProvider>
          <ScopeProvider>
            <StatusBar style="light" />
            <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: palette.paper }, animation: 'slide_from_right' }} />
          </ScopeProvider>
        </IdentityProvider>
      </NetworkProvider>
    </SafeAreaProvider>
  );
}
