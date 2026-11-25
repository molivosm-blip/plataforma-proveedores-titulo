import apiClient from './apiClient';

import type { RawApiData } from '../../../utils/apiTypes';

export type InvitationStatus =
  | 'pending'
  | 'used'
  | 'revoked';

export type TipoProveedorDetalle =
  | 'Proveedor de servicios'
  | 'Proveedor de productos nacional'
  | 'Proveedor de productos internacional';

export type TipoProveedorSap =
  | 'Nacional'
  | 'Internacional';

export interface ProveedorPreliminarPublic {
  id: string;
  razonSocial?: string;
  rut?: string;
  giro?: string;
  tipo?: TipoProveedorSap;
  tipoProveedorDetalle?: TipoProveedorDetalle | null;
}

export interface ProveedorInvitationPublic {
  id: string;
  email: string;
  token: string;
  status: InvitationStatus;

  proveedorId?: string | null;
  proveedor?: ProveedorPreliminarPublic | null;

  createdAt?: string;
  usedAt?: string | null;
  revokedAt?: string | null;
}

export interface ActivarProveedorPayload {
  token: string;

  password: string;
  passwordConfirm?: string;

  /*
  |--------------------------------------------------------------------------
  | Identificación
  |--------------------------------------------------------------------------
  |
  | La empresa principal y las sociedades habilitadas no se envían.
  | Esos valores fueron definidos por el PM en la solicitud preliminar.
  |
  */

  razonSocial: string;
  rut: string;
  giro: string;
  tipo: TipoProveedorSap;

  /*
  |--------------------------------------------------------------------------
  | Dirección y datos generales
  |--------------------------------------------------------------------------
  */

  direccion: string;
  comuna: string;
  region?: string;
  codigoPostal?: string;
  telefono?: string;
  moneda: string;
  pais: string;

  /*
  |--------------------------------------------------------------------------
  | Representante legal
  |--------------------------------------------------------------------------
  */

  representanteLegal?: string;
  representanteCi?: string;
  representanteDireccion?: string;
  representanteEmail?: string;
  representanteCargo?: string;

  /*
  |--------------------------------------------------------------------------
  | Persona de contacto
  |--------------------------------------------------------------------------
  */

  proveedorNombre: string;
  personaContacto?: string;
  personaContactoCargo?: string;
  personaContactoTelefono: string;
  personaContactoEmail: string;

  /*
  |--------------------------------------------------------------------------
  | Personería jurídica
  |--------------------------------------------------------------------------
  */

  fechaEscrituraPublica?: string;
  notaria?: string;

  /*
  |--------------------------------------------------------------------------
  | Condiciones comerciales y bancarias
  |--------------------------------------------------------------------------
  */

  plazoPago: string;
  formaPago: string;
  diasDespacho?: string;
  bancoPago: string;
  cuentaCorriente: string;
  codigoSwift?: string;

  dimensionNegocioTexto?: string;
}

export interface ActivarProveedorResponse {
  message?: string;

  session: {
    token: string;
    user: RawApiData;
  };

  proveedor?: {
    id?: string;
    razonSocial?: string;
    rut?: string;
    giro?: string;
    tipo?: TipoProveedorSap;
    empresaId?: string | null;
    estado?: string;

    detalle?: {
      direccion?: string;
      comuna?: string;
      region?: string;
      codigoSwift?: string | null;
      dimensionNegocioTexto?: string;
      tipoProveedorDetalle?: TipoProveedorDetalle | null;
    };

    sociedadesHabilitadas?: string[];
  };
}

/* ================= HELPERS ================= */

const unwrapResponse = (data: RawApiData): RawApiData => {
  return data?.data ?? data;
};

const normalizeStatus = (
  value: unknown
): InvitationStatus => {
  if (value === 'used') {
    return 'used';
  }

  if (value === 'revoked') {
    return 'revoked';
  }

  return 'pending';
};

const normalizeTipo = (
  value: unknown
): TipoProveedorSap | undefined => {
  if (value === 'Internacional') {
    return 'Internacional';
  }

  if (value === 'Nacional') {
    return 'Nacional';
  }

  return undefined;
};

const normalizeTipoProveedorDetalle = (
  value: unknown
): TipoProveedorDetalle | null => {
  if (value === 'Proveedor de servicios') {
    return value;
  }

  if (
    value ===
    'Proveedor de productos nacional'
  ) {
    return value;
  }

  if (
    value ===
    'Proveedor de productos internacional'
  ) {
    return value;
  }

  return null;
};

const normalizeProveedorPreliminar = (
  raw: RawApiData
): ProveedorPreliminarPublic | null => {
  if (!raw) {
    return null;
  }

  return {
    id: String(raw?.id ?? ''),

    razonSocial:
      raw?.razonSocial ??
      raw?.razon_social ??
      undefined,

    rut:
      raw?.rut ??
      undefined,

    giro:
      raw?.giro ??
      undefined,

    tipo:
      normalizeTipo(raw?.tipo),

    tipoProveedorDetalle:
      normalizeTipoProveedorDetalle(
        raw?.tipoProveedorDetalle ??
          raw?.tipo_proveedor_detalle ??
          raw?.detalle?.tipoProveedorDetalle ??
          raw?.detalle?.tipo_proveedor_detalle
      ),
  };
};

const normalizeInvitation = (
  raw: RawApiData
): ProveedorInvitationPublic => {
  return {
    id: String(raw?.id ?? ''),

    proveedorId:
      raw?.proveedorId ??
      raw?.proveedor_id ??
      raw?.proveedor?.id ??
      null,

    email: String(
      raw?.email ?? ''
    )
      .trim()
      .toLowerCase(),

    token: String(
      raw?.token ?? ''
    ),

    status:
      normalizeStatus(raw?.status),

    proveedor:
      normalizeProveedorPreliminar(
        raw?.proveedor
      ),

    createdAt:
      raw?.createdAt ??
      raw?.created_at ??
      undefined,

    usedAt:
      raw?.usedAt ??
      raw?.used_at ??
      null,

    revokedAt:
      raw?.revokedAt ??
      raw?.revoked_at ??
      null,
  };
};

/* ================= SERVICE ================= */

export class ProveedorActivationService {
  /*
  |--------------------------------------------------------------------------
  | Obtener invitación pública por token
  |--------------------------------------------------------------------------
  |
  | GET /v1/proveedor/invitations/{token}
  |
  | La respuesta incluye únicamente los datos preliminares necesarios:
  | - Razón social
  | - RUT
  | - Giro
  | - Tipo nacional/internacional
  | - Tipo de proveedor definido por el PM
  |
  | No expone empresa principal ni sociedades habilitadas.
  |
  */

  static async getInvitationByToken(
    token: string
  ): Promise<ProveedorInvitationPublic> {
    const response = await apiClient.get(
      `/proveedor/invitations/${encodeURIComponent(
        token
      )}`
    );

    const payload =
      unwrapResponse(response.data);

    return normalizeInvitation(payload);
  }

  /*
  |--------------------------------------------------------------------------
  | Activar proveedor
  |--------------------------------------------------------------------------
  |
  | POST /v1/proveedor/activar
  |
  | No envía:
  | - empresaId
  | - sociedadesHabilitadas
  | - tipoProveedorDetalle
  |
  | Esos valores son recuperados por el backend desde la solicitud preliminar.
  |
  */

  static async activate(
    payload: ActivarProveedorPayload
  ): Promise<ActivarProveedorResponse> {
    const response = await apiClient.post(
      '/proveedor/activar',
      payload
    );

    return unwrapResponse(response.data);
  }
}

export default ProveedorActivationService;