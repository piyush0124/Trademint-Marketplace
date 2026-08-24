import axios, { AxiosError, InternalAxiosRequestConfig } from 'axios';

const TOKEN_KEY = 'marketplace_token';

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1',
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
