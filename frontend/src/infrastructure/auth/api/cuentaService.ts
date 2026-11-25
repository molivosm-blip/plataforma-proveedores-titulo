import apiClient from './apiClient';

/*
|--------------------------------------------------------------------------
| Servicios de "Mi cuenta"
|--------------------------------------------------------------------------
|
| Todas las rutas actúan sobre el usuario autenticado.
|
*/

export interface PerfilCuenta {
  id: string;
  email: string;
  displayName: string | null;
  role: string;
  area: string | null;
  authType: 'local' | 'entra';
  puedeCambiarPassword: boolean;
  creadoEn: string | null;
  ingresoActual: string | null;
  ingresoAnterior: string | null;
  proveedor: {
    id: string;
    razonSocial: string | null;
    rut: string | null;
    tipo: string | null;
    giro: string | null;
    estado: string | null;
  } | null;
}

export interface PreferenciaAviso {
  grupo: string;
  titulo: string;
  descripcion: string;
  correo: boolean;
}

export interface PreferenciasAvisos {
  items: PreferenciaAviso[];
  disponible: boolean;
}

export const CuentaService = {
  async obtenerPerfil(): Promise<PerfilCuenta> {
    const { data } = await apiClient.get('/v1/cuenta');
    return data.data as PerfilCuenta;
  },

  async cambiarPassword(passwordActual: string, passwordNueva: string): Promise<void> {
    await apiClient.put('/v1/cuenta/password', { passwordActual, passwordNueva });
  },

  async obtenerPreferencias(): Promise<PreferenciasAvisos> {
    const { data } = await apiClient.get('/v1/cuenta/notificaciones');
    return data.data as PreferenciasAvisos;
  },

  async guardarPreferencias(preferencias: Record<string, boolean>): Promise<PreferenciasAvisos> {
    const { data } = await apiClient.put('/v1/cuenta/notificaciones', { preferencias });
    return data.data as PreferenciasAvisos;
  },
};

export const mensajeErrorCuenta = (caught: unknown, porDefecto: string): string => {
  const err = caught as { response?: { data?: { message?: string } } };
  return err?.response?.data?.message || porDefecto;
};
