import apiClient from './apiClient';

import type { RawApiData } from '../../../utils/apiTypes';

export type InvitationStatus = 'pending' | 'used' | 'revoked';

export interface ProveedorInvitation {
  id: string;
  proveedorId?: string | null;

  email: string;
  token: string;
  status: InvitationStatus;

  activationLink?: string;

  createdById?: string;
  createdByEmail?: string;
  createdByName?: string;

  createdAt: string;
  usedAt?: string;
  revokedAt?: string;
}

export interface CrearInvitationPayload {
  email: string;
  proveedorId?: string | number | null;
}

/* ================= HELPERS ================= */

const normalizeStatus = (value: unknown): InvitationStatus => {
  if (value === 'used') return 'used';
  if (value === 'revoked') return 'revoked';

  return 'pending';
};

const unwrapResponse = (data: RawApiData): RawApiData => {
  return data?.data ?? data?.items ?? data;
};

const normalizeInvitation = (raw: RawApiData): ProveedorInvitation => {
  const token = String(raw?.token ?? '');

  return {
    id: String(raw?.id ?? ''),

    proveedorId:
      raw?.proveedorId !== undefined && raw?.proveedorId !== null
        ? String(raw.proveedorId)
        : raw?.proveedor_id !== undefined && raw?.proveedor_id !== null
          ? String(raw.proveedor_id)
          : null,

    email: String(raw?.email ?? '').trim().toLowerCase(),

    token,

    status: normalizeStatus(raw?.status),

    activationLink:
      raw?.activationLink ??
      raw?.activation_link ??
      (token
        ? `${window.location.origin}/proveedor/activar?token=${token}`
        : ''),

    createdById:
      raw?.createdById !== undefined && raw?.createdById !== null
        ? String(raw.createdById)
        : raw?.created_by_user_id !== undefined &&
            raw?.created_by_user_id !== null
          ? String(raw.created_by_user_id)
          : raw?.created_by?.id !== undefined && raw?.created_by?.id !== null
            ? String(raw.created_by.id)
            : raw?.creadoPor?.id !== undefined && raw?.creadoPor?.id !== null
              ? String(raw.creadoPor.id)
              : undefined,

    createdByEmail:
      raw?.createdByEmail ??
      raw?.created_by_email ??
      raw?.created_by?.email ??
      raw?.creadoPor?.email,

    createdByName:
      raw?.createdByName ??
      raw?.created_by_name ??
      raw?.created_by?.display_name ??
      raw?.created_by?.displayName ??
      raw?.creadoPor?.display_name ??
      raw?.creadoPor?.displayName,

    createdAt:
      raw?.createdAt ??
      raw?.created_at ??
      new Date().toISOString(),

    usedAt:
      raw?.usedAt ??
      raw?.used_at,

    revokedAt:
      raw?.revokedAt ??
      raw?.revoked_at,
  };
};

/* ================= SERVICE ================= */

export class InvitacionesService {
  /*
  |--------------------------------------------------------------------------
  | Obtener invitaciones
  |--------------------------------------------------------------------------
  |
  | Endpoint:
  | GET /v1/interno/proveedor-invitations
  |
  */
  static async getAll(): Promise<ProveedorInvitation[]> {
    const response = await apiClient.get('/interno/proveedor-invitations');

    const payload = unwrapResponse(response.data);

    if (!Array.isArray(payload)) {
      return [];
    }

    return payload.map(normalizeInvitation);
  }

  /*
  |--------------------------------------------------------------------------
  | Crear invitación asociada a proveedor preliminar
  |--------------------------------------------------------------------------
  |
  | Endpoint:
  | POST /v1/interno/proveedor-invitations
  |
  | Nuevo flujo:
  | - Primero se crea solicitud/proveedor preliminar.
  | - Luego se suben los 2 documentos del solicitante.
  | - Recién después se crea la invitación.
  |
  */
  static async create(
    email: string,
    proveedorId?: string | number | null
  ): Promise<ProveedorInvitation> {
    const payload: CrearInvitationPayload = {
      email: email.trim().toLowerCase(),
      proveedorId: proveedorId ?? null,
    };

    const response = await apiClient.post(
      '/interno/proveedor-invitations',
      payload
    );

    const data = unwrapResponse(response.data);

    return normalizeInvitation(data);
  }

  /*
  |--------------------------------------------------------------------------
  | Revocar invitación
  |--------------------------------------------------------------------------
  |
  | Endpoint:
  | PUT /v1/interno/proveedor-invitations/{id}/revoke
  |
  */
  static async revoke(id: string | number): Promise<void> {
    await apiClient.put(
      `/interno/proveedor-invitations/${encodeURIComponent(String(id))}/revoke`
    );
  }
}

export default InvitacionesService;