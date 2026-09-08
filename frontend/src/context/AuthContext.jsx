import { createContext, useContext, useState, useEffect, useCallback } from 'react';
import api from '../api/axios';

const AuthContext = createContext();

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  // Load the authenticated profile once on mount (token present → fetch /user).
  useEffect(() => {
    const token = localStorage.getItem('token');
    if (!token) {
      setLoading(false);
      return;
    }
    api
      .get('/user')
      .then((res) => setUser(res.data.user ?? res.data))
      .catch(() => {
        localStorage.removeItem('token');
        setUser(null);
      })
      .finally(() => setLoading(false));
  }, []);

  const applyAuth = (data) => {
    localStorage.setItem('token', data.token);
    setUser(data.user);
  };

  const login = async (email, password) => {
    const { data } = await api.post('/login', { email, password });
    applyAuth(data);
    return data;
  };

  const register = async (payload) => {
    const { data } = await api.post('/register', payload);
    applyAuth(data);
    return data;
  };

  const logout = async () => {
    try {
      await api.post('/logout');
    } catch {
      /* token already invalid — clear locally anyway */
    }
    localStorage.removeItem('token');
    setUser(null);
  };

  /** Refetch /user to sync wallet + payment methods after mutations. */
  const refresh = useCallback(async () => {
    const { data } = await api.get('/user');
    const next = data.user ?? data;
    setUser((prev) => ({ ...prev, ...next }));
    return next;
  }, []);

  /** Refetch just the wallet sub-resource after balance changes. */
  const refreshWallet = useCallback(async () => {
    const next = await refresh();
    return next.wallet ?? null;
  }, [refresh]);

  return (
    <AuthContext.Provider
      value={{ user, loading, login, register, logout, refresh, refreshWallet, setUser }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => useContext(AuthContext);
