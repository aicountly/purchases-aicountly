import AsyncStorage from '@react-native-async-storage/async-storage';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, client } from '../core/http';
import { isProblem } from '../core/problems';
import { useIdentity } from '../identity/IdentityProvider';

/**
 * The company, year and branch every Purchases call is made in, and what this person may do
 * there (`v1/session`: their granted permissions, and whether they own the company).
 * Only the ids and labels are remembered between launches.
 */

export interface ChosenScope {
  cmpId: number;
  companyName: string;
  fyId: number;
  yearLabel: string;
  boId: number;
  branchName: string;
}

export interface Seat {
  displayName: string | null;
  isOwner: boolean;
  permissions: string[];
}

type SeatState = { kind: 'idle' } | { kind: 'loading' } | { kind: 'ready'; seat: Seat } | { kind: 'failed'; message: string; noAccess: boolean };

interface ScopeValue {
  ready: boolean;
  scope: ChosenScope | null;
  seat: SeatState;
  restricted: boolean;
  choose(next: ChosenScope): Promise<void>;
  clear(): Promise<void>;
  reloadSeat(): void;
  can(permission: string): boolean;
}

const KEY = 'purchases.scope';
const Ctx = createContext<ScopeValue | null>(null);

export function ScopeProvider({ children }: { children: React.ReactNode }) {
  const { status } = useIdentity();
  const [ready, setReady] = useState(false);
  const [scope, setScope] = useState<ChosenScope | null>(null);
  const [seat, setSeat] = useState<SeatState>({ kind: 'idle' });
  const [restricted, setRestricted] = useState(false);
  const [seatNonce, setSeatNonce] = useState(0);

  useEffect(() => {
    if (status !== 'signedIn') {
      setScope(null);
      client.setScope(null);
      setReady(status === 'signedOut');
      return;
    }
    let alive = true;
    AsyncStorage.getItem(KEY)
      .then((raw) => {
        if (!alive) return;
        const saved = raw ? (JSON.parse(raw) as ChosenScope) : null;
        if (saved && saved.cmpId > 0 && saved.fyId > 0) {
          client.setScope({ cmp_id: saved.cmpId, fy_id: saved.fyId, bo_id: saved.boId });
          setScope(saved);
        }
      })
      .catch(() => undefined)
      .finally(() => alive && setReady(true));
    return () => {
      alive = false;
    };
  }, [status]);

  useEffect(() => {
    client.onRestricted(() => setRestricted(true));
    return () => client.onRestricted(null);
  }, []);

  useEffect(() => {
    if (!scope) {
      setSeat({ kind: 'idle' });
      return;
    }
    let alive = true;
    setSeat({ kind: 'loading' });
    api
      .get<{ data: { display_name?: string | null; is_owner?: boolean; permissions?: string[] } }>('v1/session')
      .then((res) => {
        if (!alive) return;
        const d = res?.data ?? {};
        setSeat({
          kind: 'ready',
          seat: {
            displayName: typeof d.display_name === 'string' ? d.display_name : null,
            isOwner: d.is_owner === true,
            permissions: Array.isArray(d.permissions) ? d.permissions.filter((p): p is string => typeof p === 'string') : [],
          },
        });
      })
      .catch((err) => {
        if (!alive) return;
        setSeat({ kind: 'failed', message: err instanceof Error ? err.message : 'Could not open this company.', noAccess: isProblem(err) && err.kind === 'no_access' });
      });
    return () => {
      alive = false;
    };
  }, [scope, seatNonce]);

  const choose = useCallback(async (next: ChosenScope) => {
    client.setScope({ cmp_id: next.cmpId, fy_id: next.fyId, bo_id: next.boId });
    setRestricted(false);
    setScope(next);
    await AsyncStorage.setItem(KEY, JSON.stringify(next)).catch(() => undefined);
  }, []);

  const clear = useCallback(async () => {
    client.setScope(null);
    setScope(null);
    await AsyncStorage.removeItem(KEY).catch(() => undefined);
  }, []);

  const can = useCallback(
    (permission: string) => seat.kind === 'ready' && (seat.seat.isOwner || seat.seat.permissions.includes(permission)),
    [seat],
  );

  const value = useMemo<ScopeValue>(
    () => ({ ready, scope, seat, restricted, choose, clear, reloadSeat: () => setSeatNonce((n) => n + 1), can }),
    [ready, scope, seat, restricted, choose, clear, can],
  );
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useScope(): ScopeValue {
  const v = useContext(Ctx);
  if (!v) throw new Error('useScope outside ScopeProvider');
  return v;
}
