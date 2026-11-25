import type { AxiosError } from 'axios';

/**
 * JSON tal como lo entrega el backend, antes de normalizarlo.
 *
 * Los normalizadores (normalizeProveedor, normalizeDocumento, etc.)
 * aceptan respuestas con claves camelCase o snake_case y validan cada
 * campo con fallbacks, por eso la entrada no se puede tipar de forma
 * estricta. Usar este alias solo en esa frontera; el resto del código
 * debe trabajar con los tipos ya normalizados.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- frontera de datos sin tipar del backend
export type RawApiData = any;

/**
 * Cuerpo de error estándar del backend Lumen.
 */
export interface ApiErrorBody {
  message?: string;
  error?: string;
  [key: string]: unknown;
}

/**
 * Error de axios con el cuerpo de error del backend.
 */
export type ApiError = AxiosError<ApiErrorBody>;
