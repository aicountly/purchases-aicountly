import AsyncStorage from '@react-native-async-storage/async-storage';
import { Redirect } from 'expo-router';
import { useEffect, useState } from 'react';
import { useIdentity } from '../identity/IdentityProvider';
import { useScope } from '../scope/ScopeProvider';
import { StatePanel } from '../ui/kit';

const WELCOMED_KEY = 'purchases.welcomed';

/** Where the app opens: welcome (first launch) → sign in → choose company → the desk. */
export default function Entry() {
  const { status } = useIdentity();
  const { ready, scope } = useScope();
  const [welcomed, setWelcomed] = useState<boolean | null>(null);

  useEffect(() => {
    AsyncStorage.getItem(WELCOMED_KEY)
      .then((v) => setWelcomed(v === '1'))
      .catch(() => setWelcomed(true));
  }, []);

  if (status === 'loading' || welcomed === null || (status === 'signedIn' && !ready)) return <StatePanel kind="loading" text="Opening Purchases…" />;
  if (status === 'signedOut') return <Redirect href={welcomed ? '/sign-in' : '/welcome'} />;
  return <Redirect href={scope ? '/desk' : '/company'} />;
}
