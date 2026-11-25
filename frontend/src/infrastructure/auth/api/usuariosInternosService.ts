import apiClient from './apiClient';
import type { UserRole } from '../../../domain/auth/AuthEntity';

/*
|--------------------------------------------------------------------------
| Tipos
|--------------------------------------------------------------------------
*/

export interface UsuarioInterno {
  id: string;
  email: string;
  displayName: string;
  role: UserRole;
  area: string | null;
  active: boolean;
  authType: 'entra' | 'local';
  createdAt?: string;
}

export interface AreaPortal {
  id: string;
  code: string;
  name: string;
}

export interface CrearUsuarioInternoPayload {
  email: string;
  displayName: string;
  role: UserRole;
  area?: string | null;
}

interface RawUsuario {
  id: string | number;
  email: string;
  displayName?: string;
  display_name?: string;
  role: UserRole;
  area?: string | null;
  active?: number | boolean | null;
  authType?: 'entra' | 'local';
  auth_type?: 'entra' | 'local';
  createdAt?: string;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

const mapUsuario = (raw: RawUsuario): UsuarioInterno => ({
  id: String(raw.id),
  email: raw.email,
  displayName: raw.displayName ?? raw.display_name ?? raw.email,
  role: raw.role,
  area: raw.area ?? null,
  active: raw.active === undefined || raw.active === null
    ? true
    : raw.active === true || raw.active === 1,
  authType: raw.authType ?? raw.auth_type ?? 'entra',
  createdAt: raw.createdAt,
});

/**
 * Los usuarios internos se autentican con Microsoft Entra ID, por lo que
 * no existe una contraseña que el administrador deba conocer. El backend
 * exige el campo, así que se envía una clave aleatoria que nunca se muestra
 * ni se puede usar (el login local está reservado a proveedores).
 */
const generarClaveNoUtilizable = (): string => {
  const bytes = new Uint8Array(24);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
};

/*
|--------------------------------------------------------------------------
| Servicio
|--------------------------------------------------------------------------
*/

export class UsuariosInternosService {
  static async listar(): Promise<UsuarioInterno[]> {
    const response = await apiClient.get('/admin/usuarios');
    const data = (response.data?.data ?? []) as RawUsuario[];
    return data.map(mapUsuario);
  }

  static async listarAreas(): Promise<AreaPortal[]> {
    const response = await apiClient.get('/admin/areas');
    const data = (response.data?.data ?? []) as AreaPortal[];
    return data.map(area => ({
      id: String(area.id),
      code: area.code,
      name: area.name,
    }));
  }

  static async crear(
    payload: CrearUsuarioInternoPayload
  ): Promise<UsuarioInterno> {
    const response = await apiClient.post('/admin/usuarios', {
      email: payload.email.trim().toLowerCase(),
      displayName: payload.displayName.trim(),
      role: payload.role,
      area: payload.area || null,
      password: generarClaveNoUtilizable(),
    });

    return mapUsuario(response.data.data as RawUsuario);
  }

  /** El backend hace una desactivación lógica (active = 0). */
  static async desactivar(id: string): Promise<UsuarioInterno> {
    const response = await apiClient.delete(
      `/admin/usuarios/${encodeURIComponent(id)}`
    );

    return mapUsuario(response.data.data as RawUsuario);
  }
}
