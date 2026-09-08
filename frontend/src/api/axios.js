import axios from 'axios';
import { API_BASE } from '../lib/constants';

const api = axios.create({
  baseURL: API_BASE,
  headers: {
    Accept: 'application/json',
  },
});

// Attach the Sanctum bearer token when present.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Centralise auth expiry handling.
api.interceptors.response.use(
  (res) => res,
  (error) => {
    if (error.response?.status === 401) {
      const onAuthPage = ['/login', '/register'].includes(window.location.pathname);
      const token = localStorage.getItem('token');
      // Only force a redirect when we held a token and lost it.
      if (token && !onAuthPage) {
        localStorage.removeItem('token');
        window.location.assign('/login');
      }
    }
    return Promise.reject(error);
  }
);

export default api;
