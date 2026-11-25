import apiClient from './apiClient';

import {
  type DocumentoProveedor,
  type Proveedor,
  normalizeProveedor,
} from '../../../utils/proveedorHelpers';

import type { RawApiData } from '../../../utils/apiTypes';

export interface UploadDocumentoProveedorResult {
  proveedor?: Proveedor;
  documento?: DocumentoProveedor;
  message?: string;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

const unwrapResponse = (data: RawApiData): RawApiData => {
  return data?.data ?? data?.items ?? data;
};

const normalizeDocumento = (raw: RawApiData): DocumentoProveedor => {
  return {
    id: String(raw?.id ?? ''),
    proveedorId:
      raw?.proveedorId ??
      raw?.proveedor_id ??
      undefined,

    nombre:
      raw?.nombre ??
      raw?.documentoNombre ??
      raw?.documento_nombre ??
      'Documento',

    estado:
      raw?.estado === 'Aprobado' ||
      raw?.estado === 'Observado' ||
      raw?.estado === 'Subido' ||
      raw?.estado === 'Pendiente'
        ? raw.estado
        : 'Pendiente',

    archivoNombre:
      raw?.archivoNombre ??
      raw?.archivo_nombre ??
      raw?.archivo ??
      null,

    archivoUrl:
      raw?.archivoUrl ??
      raw?.archivo_url ??
      raw?.url ??
      null,

    archivoMimeType:
      raw?.archivoMimeType ??
      raw?.archivo_mime_type ??
      raw?.mime_type ??
      null,

    comentario:
      raw?.comentario ??
      raw?.comments ??
      undefined,

    createdAt:
      raw?.createdAt ??
      raw?.created_at ??
      undefined,

    updatedAt:
      raw?.updatedAt ??
      raw?.updated_at ??
      undefined,
  };
};

/*
|--------------------------------------------------------------------------
| Service
|--------------------------------------------------------------------------
*/

export class ProveedorSolicitudesService {
  /*
  |--------------------------------------------------------------------------
  | Obtener solicitudes del proveedor autenticado
  |--------------------------------------------------------------------------
  |
  | Endpoint backend esperado:
  | GET /v1/proveedor/solicitudes
  |
  */
  static async getMisSolicitudes(): Promise<Proveedor[]> {
    const response = await apiClient.get('/proveedor/solicitudes');

    const payload = unwrapResponse(response.data);

    if (!Array.isArray(payload)) {
      return [];
    }

    return payload.map(item => normalizeProveedor(item));
  }

  /*
  |--------------------------------------------------------------------------
  | Subir documento del proveedor
  |--------------------------------------------------------------------------
  |
  | Endpoint backend esperado:
  | POST /v1/documentos/{documentoId}/upload
  |
  | Importante:
  | - Se usa FormData.
  | - No seteamos manualmente Content-Type multipart/form-data.
  |   Axios lo hace automáticamente con boundary.
  |
  */
  static async uploadDocumento(
    documentoId: string,
    file: File
  ): Promise<UploadDocumentoProveedorResult> {
    const formData = new FormData();

    /*
     * Nombre de campo recomendado.
     * Si tu backend espera otro nombre, por ejemplo "archivo",
     * cambiamos esta línea.
     */
    formData.append('file', file);

    const response = await apiClient.post(
      `/documentos/${encodeURIComponent(documentoId)}/upload`,
      formData,
      {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      }
    );

    const payload = unwrapResponse(response.data);

    return {
      message:
        response.data?.message ??
        payload?.message ??
        undefined,

      proveedor: payload?.proveedor
        ? normalizeProveedor(payload.proveedor)
        : response.data?.proveedor
          ? normalizeProveedor(response.data.proveedor)
          : undefined,

      documento: payload?.documento
        ? normalizeDocumento(payload.documento)
        : response.data?.documento
          ? normalizeDocumento(response.data.documento)
          : undefined,
    };
  }

  /*
  |--------------------------------------------------------------------------
  | Obtener una solicitud específica desde la lista del proveedor
  |--------------------------------------------------------------------------
  |
  | Útil para refrescar el modal después de subir un documento.
  |
  */
  static async getSolicitudById(proveedorId: string): Promise<Proveedor | null> {
    const solicitudes = await this.getMisSolicitudes();

    return (
      solicitudes.find(
        proveedor => String(proveedor.id) === String(proveedorId)
      ) ?? null
    );
  }
}

export default ProveedorSolicitudesService;