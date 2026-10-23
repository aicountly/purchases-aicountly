import { Redirect, Stack } from 'expo-router';
import { useIdentity } from '../../identity/IdentityProvider';
import { useScope } from '../../scope/ScopeProvider';
import { StatePanel } from '../../ui/kit';
import { palette } from '../../ui/theme';

/** Everything that needs a signed-in person and a chosen company. */
export default function WorkLayout() {
  const { status } = useIdentity();
  const { ready, scope } = useScope();
  if (status === 'loading' || (status === 'signedIn' && !ready)) return <StatePanel kind="loading" />;
  if (status !== 'signedIn') return <Redirect href="/sign-in" />;
  if (!scope) return <Redirect href="/company" />;
  return <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: palette.paper } }} />;
}
