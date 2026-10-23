import NetInfo from '@react-native-community/netinfo';
import React, { createContext, useContext, useEffect, useState } from 'react';
import { client } from '../core/http';

/** Whether the phone has a connection. Writes are refused while it has none (core/http.ts). */
const Ctx = createContext<boolean>(true);

export function NetworkProvider({ children }: { children: React.ReactNode }) {
  const [online, setOnline] = useState(true);
  useEffect(() => {
    const unsubscribe = NetInfo.addEventListener((s) => {
      // isInternetReachable is null while unknown: treat unknown as online and let the request decide.
      const next = s.isConnected !== false && s.isInternetReachable !== false;
      client.setOnline(next);
      setOnline(next);
    });
    return unsubscribe;
  }, []);
  return <Ctx.Provider value={online}>{children}</Ctx.Provider>;
}

export function useOnline(): boolean {
  return useContext(Ctx);
}
