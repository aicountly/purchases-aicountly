import { JetBrainsMono_500Medium } from '@expo-google-fonts/jetbrains-mono/500Medium';
import { JetBrainsMono_700Bold } from '@expo-google-fonts/jetbrains-mono/700Bold';
import { PublicSans_400Regular } from '@expo-google-fonts/public-sans/400Regular';
import { PublicSans_500Medium } from '@expo-google-fonts/public-sans/500Medium';
import { PublicSans_600SemiBold } from '@expo-google-fonts/public-sans/600SemiBold';
import { PublicSans_700Bold } from '@expo-google-fonts/public-sans/700Bold';
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
    PublicSans_400Regular,
    PublicSans_500Medium,
    PublicSans_600SemiBold,
    PublicSans_700Bold,
    JetBrainsMono_500Medium,
    JetBrainsMono_700Bold,
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
