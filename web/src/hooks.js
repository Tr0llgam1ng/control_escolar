import { useCallback, useEffect, useState } from 'react';
import { api } from './api.js';

/** Small data-fetching hook: { data, error, loading, reload }. */
export function useApi(path, options) {
  const key = JSON.stringify([path, options]);
  const [state, setState] = useState({ data: null, error: null, loading: true });

  const reload = useCallback(async () => {
    if (!path) return;
    setState((s) => ({ ...s, loading: true }));
    try {
      const data = await api(path, options);
      setState({ data, error: null, loading: false });
    } catch (error) {
      setState({ data: null, error, loading: false });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  useEffect(() => {
    reload();
  }, [reload]);

  return { ...state, reload };
}
