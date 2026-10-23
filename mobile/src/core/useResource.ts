import { useCallback, useEffect, useRef, useState } from 'react';
import { isProblem, type Problem, Problem as ProblemClass } from './problems';

/**
 * One read, in the four states every screen shows: loading, failed (with offline told apart),
 * empty, and loaded. A reload keeps the last good data on screen while it runs, so a refresh
 * that fails offline still shows what was there, with a banner saying so.
 */

export type Resource<T> =
  | { phase: 'loading'; data: T | null; error: null; loadedAt: number | null }
  | { phase: 'ready'; data: T; error: null; loadedAt: number }
  | { phase: 'failed'; data: T | null; error: Problem; loadedAt: number | null };

export function useResource<T>(load: () => Promise<T>, deps: unknown[]): Resource<T> & { reload: () => void; refreshing: boolean } {
  const [state, setState] = useState<Resource<T>>({ phase: 'loading', data: null, error: null, loadedAt: null });
  const [refreshing, setRefreshing] = useState(false);
  const [nonce, setNonce] = useState(0);
  const latest = useRef(0);
  const loader = useRef(load);
  useEffect(() => {
    loader.current = load;
  });

  useEffect(() => {
    const ticket = ++latest.current;
    setState((prev) => (prev.data === null ? { phase: 'loading', data: null, error: null, loadedAt: null } : prev));
    loader
      .current()
      .then((data) => {
        if (ticket !== latest.current) return;
        setState({ phase: 'ready', data, error: null, loadedAt: Date.now() });
      })
      .catch((err: unknown) => {
        if (ticket !== latest.current) return;
        const problem = isProblem(err) ? err : new ProblemClass('unknown', 0, 'error', err instanceof Error ? err.message : 'That did not load.');
        setState((prev) => ({ phase: 'failed', data: prev.data, error: problem, loadedAt: prev.loadedAt }));
      })
      .finally(() => {
        if (ticket === latest.current) setRefreshing(false);
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, nonce]);

  const reload = useCallback(() => {
    setRefreshing(true);
    setNonce((n) => n + 1);
  }, []);

  return { ...state, reload, refreshing };
}
