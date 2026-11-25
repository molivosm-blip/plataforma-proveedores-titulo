import axios, { AxiosError, type AxiosInstance } from 'axios';

const API_BASE_URL = import.meta.env.VITE_PROVEEDORES_API_URL;

const apiClient: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  timeout: 30000,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

//
// ✅ REQUEST INTERCEPTOR
//
apiClient.interceptors.request.use((config) => {
  try {
    const raw = localStorage.getItem('auth_user');

    if (raw) {
      const parsed = JSON.parse(raw);
      const token = parsed?.token;

      if (token) {
        config.headers.Authorization = `Bearer ${token}`;
      }
    }
  } catch (error) {
    console.warn('Error leyendo auth_user:', error);
  }

  return config;
});

//
// ✅ RESPONSE INTERCEPTOR
//
apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    const status = error.response?.status;
    const requestUrl = error.config?.url ?? '';

    /*
     * Los endpoints /auth/* responden 401 ante credenciales inválidas.
     * Ahí no se debe cerrar la sesión ni recargar la página: la pantalla
     * de login necesita mostrar el mensaje de error al usuario.
     */
    const isAuthEndpoint = requestUrl.includes('/auth/');

    if (status === 401 && !isAuthEndpoint) {
      console.warn('Sesión expirada');

      localStorage.removeItem('auth_user');

      if (window.location.pathname !== '/login') {
        window.location.href = '/login';
      }
    }

    return Promise.reject(error);
  }
);

export default apiClient;
