import { createContext, useContext, useEffect, useState } from 'react';
import { api, loadSession, saveSession, setUnauthorizedHandler } from './api.js';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [session, setSession] = useState(() => loadSession());

  useEffect(() => {
    setUnauthorizedHandler(() => {
      saveSession(null);
      setSession(null);
    });
  }, []);

  async function login(email, password) {
    const data = await api('/auth/login', { method: 'POST', body: { email, password } });
    const next = { token: data.token, user: data.user, profile: data.profile };
    saveSession(next);
    setSession(next);
    return next;
  }

  async function logout() {
    try {
      await api('/auth/logout', { method: 'POST' });
    } catch {
      /* token may already be invalid */
    }
    saveSession(null);
    setSession(null);
  }

  return <AuthContext.Provider value={{ session, user: session?.user, profile: session?.profile, login, logout }}>{children}</AuthContext.Provider>;
}

export const useAuth = () => useContext(AuthContext);
