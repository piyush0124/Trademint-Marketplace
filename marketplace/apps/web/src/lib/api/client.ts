import axios, { AxiosError, InternalAxiosRequestConfig } from 'axios';

const TOKEN_KEY = 'marketplace_token';

// In production the API URL must come from the VITE_API_URL environment variable
// (set it in Vercel). The localhost fallback only applies during local development.
const API_URL: string =
  import.meta.env.VITE_API_URL ??
  (import.meta.env.DEV ? 'http://localhost:8000/api/v1' : '');

if (!API_URL) {
  // eslint-disable-next-line no-console
  console.error(
    'VITE_API_URL is not set. Add it in your hosting environment variables and redeploy.',
  );
}

export const api = axios.create({
  baseURL: API_URL.replace(/\/+$/, ''), // strip any trailing slash
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = getAuthToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export function setAuthToken(token: string | null): void {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token);
  } else {
    localStorage.removeItem(TOKEN_KEY);
  }
}

export function getAuthToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function getErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const axiosError = error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
    const data = axiosError.response?.data;
    if (data?.errors) {
      return Object.values(data.errors).flat().join(' ');
    }
    if (data?.message) {
      return data.message;
    }
  }
  return 'Something went wrong. Please try again.';
}

export function formatPrice(price: string | number | null | undefined, currency: string): string {
  if (price === null || price === undefined || price === '') {
    return 'Price on request';
  }
  const amount = typeof price === 'string' ? parseFloat(price) : price;
  if (Number.isNaN(amount)) {
    return 'Price on request';
  }
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  }).format(amount);
}

export function uploadMedia(listingUuid: string, file: File) {
  const formData = new FormData();
  formData.append('file', file);
  return api.post(`/listings/${listingUuid}/media`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
}
