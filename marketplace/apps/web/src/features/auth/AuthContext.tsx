import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, getAuthToken, setAuthToken } from '../../lib/api/client';
import type { ApiUser, AuthResponse } from '../../types/api';

export interface OwnerRegistrationPayload {
  name: string;
  email: string;
  password: string;
  passwordConfirmation: string;
  phone?: string;
  businessName: string;
  businessDescription?: string;
  country?: string;
  state?: string;
  city: string;
  locality?: string;
  pincode?: string;
}

interface AuthContextValue {
  user: ApiUser | null;
  role: ApiUser['attributes']['role'] | null;
  isLoading: boolean;
  isAuthenticated: boolean;
  isCustomer: boolean;
  isOwner: boolean;
  isAdmin: boolean;
  login: (email: string, password: string) => Promise<ApiUser>;
  registerCustomer: (name: string, email: string, password: string, passwordConfirmation: string) => Promise<ApiUser>;
  registerOwner: (payload: OwnerRegistrationPayload) => Promise<ApiUser>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<ApiUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  const fetchMe = useCallback(async () => {
    const token = getAuthToken();
    if (!token) {
      setUser(null);
      setIsLoading(false);
      return;
    }

    try {
      const { data } = await api.get<{ data: ApiUser }>('/auth/me');
      setUser(data.data);
    } catch {
      setAuthToken(null);
      setUser(null);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchMe();
  }, [fetchMe]);

  const login = useCallback(async (email: string, password: string) => {
    const { data } = await api.post<AuthResponse>('/auth/login', { email, password });
    setAuthToken(data.data.token);
    setUser(data.data.user);
    return data.data.user;
  }, []);

  const registerCustomer = useCallback(
    async (name: string, email: string, password: string, passwordConfirmation: string) => {
      const { data } = await api.post<AuthResponse>('/auth/register', {
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });
      setAuthToken(data.data.token);
      setUser(data.data.user);
      return data.data.user;
    },
    [],
  );

  const registerOwner = useCallback(async (payload: OwnerRegistrationPayload) => {
    const { data } = await api.post<AuthResponse>('/auth/register/owner', {
      name: payload.name,
      email: payload.email,
      password: payload.password,
      password_confirmation: payload.passwordConfirmation,
      phone: payload.phone,
      business_name: payload.businessName,
      business_description: payload.businessDescription,
      country: payload.country,
      state: payload.state,
      city: payload.city,
      locality: payload.locality,
      pincode: payload.pincode,
    });
    setAuthToken(data.data.token);
    setUser(data.data.user);
    return data.data.user;
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } finally {
      setAuthToken(null);
      setUser(null);
    }
  }, []);

  const role = user?.attributes.role ?? null;

  const value = useMemo(
    () => ({
      user,
      role,
      isLoading,
      isAuthenticated: user !== null,
      isCustomer: role === 'customer',
      isOwner: role === 'owner',
      isAdmin: role === 'admin',
      login,
      registerCustomer,
      registerOwner,
      logout,
    }),
    [user, role, isLoading, login, registerCustomer, registerOwner, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return context;
}
