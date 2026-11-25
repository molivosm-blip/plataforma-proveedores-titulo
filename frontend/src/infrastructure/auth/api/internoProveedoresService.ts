import apiClient from './apiClient';

import {
  type DocumentoProveedor,
  type EstadoDocumento,
  type EstadoProveedor,
  type Proveedor,
  type WorkflowArea,
  type WorkflowStatus,
  normalizeProveedor,
} from '../../../utils/proveedorHelpers';
import type { RawApiData } from '../../../utils/apiTypes';

/*
|--------------------------------------------------------------------------
| Payloads
|--------------------------------------------------------------------------
*/

export interface CambiarEstadoProveedorPayload {
  estado: EstadoProveedor;
  comments?: string;
}

export interface RevisarDocumentoPayload {
  estado: EstadoDocumento;
  comentario?: string;
}

export interface ActualizarValidacionPayload {
  status: WorkflowStatus;
  comments?: string;
}

export interface ActualizarWorkflowPayload {
  status: WorkflowStatus;
  comments?: string;
}

export interface CrearSolicitudPreliminarPayload {
  emailProveedor: string;
  empresaIds: string[];
  tipo: 'Nacional' | 'Internacional';
  tipoProveedorDetalle: string;
  razonSocial?: string;
  rut?: string;
  giro?: string;
}

/*
|--------------------------------------------------------------------------
| Resultados de proveedores y documentos
|--------------------------------------------------------------------------
*/

export interface UploadDocumentoInternoResult {
  message?: string;
  proveedor?: Proveedor;
  documento?: DocumentoProveedor;
  data?: Proveedor | null;
}

export interface GenerarFichaPdfResult {
  message?: string;
  archivoUrl?: string;
  proveedor?: Proveedor;
  data?: Proveedor | null;
}

export interface RecalcularEstadoResult {
  message?: string;
  estado?: EstadoProveedor;

  resumenDocumental?: {
    total: number;
    pendientes: number;
    subidos: number;
    observados: number;
    aprobados: number;
  };

  proveedor?: Proveedor;
  data?: Proveedor | null;
}

/*
|--------------------------------------------------------------------------
| Tipos AML / Gesintel
|--------------------------------------------------------------------------
*/

export interface ProveedorAmlCheck {
  id: string;
  proveedorId?: string | null;
  endpoint?: string;
  rut?: string;
  nombre?: string;
  amlStatus?: string | null;
  percent?: number | null;
  pepsanColor?: string | null;
  kycameColor?: string | null;
  ubocomColor?: string | null;
  pdfLink?: string | null;
  request?: unknown;
  response?: unknown;
  createdByUserId?: string | null;
  createdAt?: string;
  updatedAt?: string;
}

export interface ProveedorAmlRecord {
  id: string;
  proveedorId?: string | null;
  rut?: string;
  nombre?: string;
  category?: string | null;
  status?: string | null;
  extId?: string | null;
  request?: unknown;
  response?: unknown;
  createdByUserId?: string | null;
  createdAt?: string;
  updatedAt?: string;
}

/*
|--------------------------------------------------------------------------
| Tipos DICOM
|--------------------------------------------------------------------------
*/

export interface ProveedorDicomCheck {
  id: string;
  proveedorId?: string | null;
  endpoint?: string;
  segmento?: string | null;
  rut?: string;
  fechaConsulta?: string | null;
  requestUrl?: string | null;
  response?: unknown;
  httpCode?: number | null;
  createdByUserId?: string | null;
  createdAt?: string;
  updatedAt?: string;
}

/*
|--------------------------------------------------------------------------
| Helpers generales
|--------------------------------------------------------------------------
*/

const unwrapResponse = (
  data: RawApiData
): RawApiData => {
  return (
    data?.data ??
    data?.items ??
    data
  );
};

const normalizeDocumento = (
  raw: RawApiData
): DocumentoProveedor => {
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

    responsableCarga:
      raw?.responsableCarga ??
      raw?.responsable_carga ??
      undefined,

    areaResponsable:
      raw?.areaResponsable ??
      raw?.area_responsable ??
      undefined,

    esPrerrequisito:
      raw?.esPrerrequisito ??
      raw?.es_prerrequisito ??
      false,

    bloqueaCargaProveedor:
      raw?.bloqueaCargaProveedor ??
      raw?.bloquea_carga_proveedor ??
      false,

    visibleParaProveedor:
      raw?.visibleParaProveedor ??
      raw?.visible_para_proveedor ??
      true,

    visibleParaInternos:
      raw?.visibleParaInternos ??
      raw?.visible_para_internos ??
      true,

    plantillaUrl:
      raw?.plantillaUrl ??
      raw?.plantilla_url ??
      null,

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

    uploadedByUserId:
      raw?.uploadedByUserId ??
      raw?.uploaded_by_user_id ??
      undefined,

    reviewedByUserId:
      raw?.reviewedByUserId ??
      raw?.reviewed_by_user_id ??
      undefined,

    reviewedAt:
      raw?.reviewedAt ??
      raw?.reviewed_at ??
      undefined,

    createdAt:
      raw?.createdAt ??
      raw?.created_at ??
      undefined,

    updatedAt:
      raw?.updatedAt ??
      raw?.updated_at ??
      undefined,
  } as DocumentoProveedor;
};

const normalizeProveedorFromPayload = (
  payload: RawApiData
): Proveedor | null => {
  if (!payload) {
    return null;
  }

  if (payload.proveedor) {
    return normalizeProveedor(
      payload.proveedor
    );
  }

  if (payload.data?.proveedor) {
    return normalizeProveedor(
      payload.data.proveedor
    );
  }

  if (
    payload.data?.id ||
    payload.data?.razonSocial ||
    payload.data?.razon_social
  ) {
    return normalizeProveedor(
      payload.data
    );
  }

  if (
    payload.id ||
    payload.razonSocial ||
    payload.razon_social
  ) {
    return normalizeProveedor(
      payload
    );
  }

  return null;
};

const normalizeDocumentoFromPayload = (
  payload: RawApiData
): DocumentoProveedor | undefined => {
  if (!payload) {
    return undefined;
  }

  if (payload.documento) {
    return normalizeDocumento(
      payload.documento
    );
  }

  if (payload.data?.documento) {
    return normalizeDocumento(
      payload.data.documento
    );
  }

  if (
    payload.id ||
    payload.nombre
  ) {
    return normalizeDocumento(
      payload
    );
  }

  return undefined;
};

const normalizeUploadResult = (
  responseData: RawApiData
): UploadDocumentoInternoResult => {
  const payload =
    unwrapResponse(responseData);

  const proveedor =
    normalizeProveedorFromPayload(
      payload
    ) ??
    normalizeProveedorFromPayload(
      responseData
    ) ??
    undefined;

  const documento =
    normalizeDocumentoFromPayload(
      payload
    ) ??
    normalizeDocumentoFromPayload(
      responseData
    );

  return {
    message:
      responseData?.message ??
      payload?.message ??
      undefined,

    proveedor,

    documento,

    data:
      proveedor ?? null,
  };
};

const normalizeGenerarFichaPdfResult = (
  responseData: RawApiData
): GenerarFichaPdfResult => {
  const payload =
    unwrapResponse(responseData);

  const proveedor =
    normalizeProveedorFromPayload(
      payload
    ) ??
    normalizeProveedorFromPayload(
      responseData
    ) ??
    null;

  return {
    message:
      responseData?.message ??
      payload?.message ??
      undefined,

    archivoUrl:
      responseData?.archivoUrl ??
      payload?.archivoUrl ??
      undefined,

    proveedor:
      proveedor ?? undefined,

    data:
      proveedor,
  };
};

const normalizeRecalcularEstadoResult = (
  responseData: RawApiData
): RecalcularEstadoResult => {
  const payload =
    unwrapResponse(responseData);

  const proveedor =
    normalizeProveedorFromPayload(
      payload
    ) ??
    normalizeProveedorFromPayload(
      responseData
    ) ??
    null;

  return {
    message:
      responseData?.message ??
      payload?.message ??
      undefined,

    estado:
      responseData?.estado ??
      payload?.estado ??
      undefined,

    resumenDocumental:
      responseData?.resumenDocumental ??
      payload?.resumenDocumental ??
      undefined,

    proveedor:
      proveedor ?? undefined,

    data:
      proveedor,
  };
};

/*
|--------------------------------------------------------------------------
| Helpers AML
|--------------------------------------------------------------------------
*/

const normalizeAmlCheck = (
  raw: RawApiData
): ProveedorAmlCheck => {
  return {
    id:
      String(raw?.id ?? ''),

    proveedorId:
      raw?.proveedorId ??
      raw?.proveedor_id ??
      null,

    endpoint:
      raw?.endpoint ??
      undefined,

    rut:
      raw?.rut ??
      undefined,

    nombre:
      raw?.nombre ??
      undefined,

    amlStatus:
      raw?.amlStatus ??
      raw?.aml_status ??
      null,

    percent:
      raw?.percent !== undefined &&
      raw?.percent !== null &&
      !Number.isNaN(Number(raw.percent))
        ? Number(raw.percent)
        : null,

    pepsanColor:
      raw?.pepsanColor ??
      raw?.pepsan_color ??
      null,

    kycameColor:
      raw?.kycameColor ??
      raw?.kycame_color ??
      null,

    ubocomColor:
      raw?.ubocomColor ??
      raw?.ubocom_color ??
      null,

    pdfLink:
      raw?.pdfLink ??
      raw?.pdf_link ??
      null,

    request:
      raw?.request ??
      null,

    response:
      raw?.response ??
      null,

    createdByUserId:
      raw?.createdByUserId ??
      raw?.created_by_user_id ??
      null,

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

const normalizeAmlRecord = (
  raw: RawApiData
): ProveedorAmlRecord => {
  return {
    id:
      String(raw?.id ?? ''),

    proveedorId:
      raw?.proveedorId ??
      raw?.proveedor_id ??
      null,

    rut:
      raw?.rut ??
      undefined,

    nombre:
      raw?.nombre ??
      undefined,

    category:
      raw?.category ??
      null,

    status:
      raw?.status ??
      null,

    extId:
      raw?.extId ??
      raw?.ext_id ??
      null,

    request:
      raw?.request ??
      null,

    response:
      raw?.response ??
      null,

    createdByUserId:
      raw?.createdByUserId ??
      raw?.created_by_user_id ??
      null,

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

const normalizeAmlCheckFromResponse = (
  responseData: RawApiData
): ProveedorAmlCheck | null => {
  const payload =
    unwrapResponse(responseData);

  if (!payload) {
    return null;
  }

  if (
    payload.id ||
    payload.endpoint ||
    payload.amlStatus ||
    payload.aml_status
  ) {
    return normalizeAmlCheck(
      payload
    );
  }

  if (payload.data) {
    return normalizeAmlCheck(
      payload.data
    );
  }

  return null;
};

const normalizeAmlRecordFromResponse = (
  responseData: RawApiData
): ProveedorAmlRecord | null => {
  const payload =
    unwrapResponse(responseData);

  if (!payload) {
    return null;
  }

  if (
    payload.id ||
    payload.rut ||
    payload.category ||
    payload.extId ||
    payload.ext_id
  ) {
    return normalizeAmlRecord(
      payload
    );
  }

  if (payload.data) {
    return normalizeAmlRecord(
      payload.data
    );
  }

  return null;
};

/*
|--------------------------------------------------------------------------
| Helpers DICOM
|--------------------------------------------------------------------------
*/

const normalizeDicomCheck = (
  raw: RawApiData
): ProveedorDicomCheck => {
  return {
    id:
      String(raw?.id ?? ''),

    proveedorId:
      raw?.proveedorId ??
      raw?.proveedor_id ??
      null,

    endpoint:
      raw?.endpoint ??
      undefined,

    segmento:
      raw?.segmento ??
      null,

    rut:
      raw?.rut ??
      undefined,

    fechaConsulta:
      raw?.fechaConsulta ??
      raw?.fecha_consulta ??
      null,

    requestUrl:
      raw?.requestUrl ??
      raw?.request_url ??
      null,

    response:
      raw?.response ??
      null,

    httpCode:
      raw?.httpCode ??
      raw?.http_code ??
      null,

    createdByUserId:
      raw?.createdByUserId ??
      raw?.created_by_user_id ??
      null,

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

const normalizeDicomCheckFromResponse = (
  responseData: RawApiData
): ProveedorDicomCheck | null => {
  const payload =
    unwrapResponse(responseData);

  if (!payload) {
    return null;
  }

  if (
    payload.id ||
    payload.endpoint ||
    payload.rut ||
    payload.response
  ) {
    return normalizeDicomCheck(
      payload
    );
  }

  if (payload.data) {
    return normalizeDicomCheck(
      payload.data
    );
  }

  return null;
};

/*
|--------------------------------------------------------------------------
| Servicio
|--------------------------------------------------------------------------
*/

export class InternoProveedoresService {
  /*
  |--------------------------------------------------------------------------
  | Proveedores
  |--------------------------------------------------------------------------
  */

  static async getProveedores(): Promise<
    Proveedor[]
  > {
    const response =
      await apiClient.get(
        '/interno/proveedores'
      );

    const payload =
      unwrapResponse(
        response.data
      );

    if (!Array.isArray(payload)) {
      return [];
    }

    return payload.map(item =>
      normalizeProveedor(item)
    );
  }

  static async getProveedorById(
    proveedorId: string
  ): Promise<Proveedor | null> {
    const proveedores =
      await this.getProveedores();

    return (
      proveedores.find(
        proveedor =>
          String(proveedor.id) ===
          String(proveedorId)
      ) ?? null
    );
  }

  static async crearSolicitudPreliminar(
    payload: CrearSolicitudPreliminarPayload
  ): Promise<Proveedor | null> {
    const response =
      await apiClient.post(
        '/interno/proveedores/solicitud',
        payload
      );

    const data =
      unwrapResponse(
        response.data
      );

    if (!data) {
      return null;
    }

    return normalizeProveedor(
      data
    );
  }

  static async cambiarEstadoProveedor(
    proveedorId: string,
    estado: EstadoProveedor,
    comments?: string
  ): Promise<Proveedor | null> {
    const response =
      await apiClient.put(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/estado`,
        {
          estado,
          comments,
        } satisfies CambiarEstadoProveedorPayload
      );

    const payload =
      unwrapResponse(
        response.data
      );

    return normalizeProveedorFromPayload(
      payload
    );
  }

  static async generarFichaPdf(
    proveedorId: string
  ): Promise<GenerarFichaPdfResult> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/ficha-pdf`
      );

    return normalizeGenerarFichaPdfResult(
      response.data
    );
  }

  static async recalcularEstado(
    proveedorId: string
  ): Promise<RecalcularEstadoResult> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/recalcular-estado`
      );

    return normalizeRecalcularEstadoResult(
      response.data
    );
  }

  /*
  |--------------------------------------------------------------------------
  | Documentos
  |--------------------------------------------------------------------------
  */

  static async revisarDocumento(
    documentoId: string,
    estado: EstadoDocumento,
    comentario?: string
  ): Promise<Proveedor | null> {
    const response =
      await apiClient.put(
        `/interno/documentos/${encodeURIComponent(
          documentoId
        )}/estado`,
        {
          estado,
          comentario,
        } satisfies RevisarDocumentoPayload
      );

    const payload =
      unwrapResponse(
        response.data
      );

    return normalizeProveedorFromPayload(
      payload
    );
  }

  static async uploadDocumento(
    documentoId: string,
    file: File
  ): Promise<UploadDocumentoInternoResult> {
    const formData =
      new FormData();

    formData.append(
      'file',
      file
    );

    /*
     * No se establece manualmente Content-Type.
     * El navegador agrega automáticamente el boundary
     * correcto para multipart/form-data.
     */
    const response =
      await apiClient.post(
        `/documentos/${encodeURIComponent(
          documentoId
        )}/upload`,
        formData,
        {
          headers: {
            'Content-Type':
              'multipart/form-data',
          },
        }
      );

    return normalizeUploadResult(
      response.data
    );
  }

  /*
  |--------------------------------------------------------------------------
  | Workflow y validaciones
  |--------------------------------------------------------------------------
  */

  static async actualizarValidacion(
    proveedorId: string,
    area: WorkflowArea,
    status: WorkflowStatus,
    comments?: string
  ): Promise<void> {
    /*
     * PUT /validaciones/proveedores/{id}/areas/{area}
     * El backend responde con la validación actualizada (no con el
     * proveedor completo), por lo que quien llama debe recargar el
     * proveedor para ver el nuevo estado general.
     */
    await apiClient.put(
      `/validaciones/proveedores/${encodeURIComponent(
        proveedorId
      )}/areas/${encodeURIComponent(area)}`,
      {
        status,
        comments,
      } satisfies ActualizarValidacionPayload
    );
  }

  /**
   * Solo admin global. PUT /validaciones/proveedores/{id}/areas/{area}/reabrir
   */
  static async reabrirValidacion(
    proveedorId: string,
    area: WorkflowArea,
    comments?: string
  ): Promise<void> {
    await apiClient.put(
      `/validaciones/proveedores/${encodeURIComponent(
        proveedorId
      )}/areas/${encodeURIComponent(area)}/reabrir`,
      { comments }
    );
  }

  static async actualizarWorkflow(
    proveedorId: string,
    stepIdOrArea: string,
    status: WorkflowStatus,
    comments?: string
  ): Promise<Proveedor | null> {
    const response =
      await apiClient.put(
        `/workflow/proveedores/${encodeURIComponent(
          proveedorId
        )}/steps/${encodeURIComponent(
          stepIdOrArea
        )}`,
        {
          status,
          comments,
        } satisfies ActualizarWorkflowPayload
      );

    const payload =
      unwrapResponse(
        response.data
      );

    return normalizeProveedorFromPayload(
      payload
    );
  }

  /*
  |--------------------------------------------------------------------------
  | AML / Gesintel
  |--------------------------------------------------------------------------
  */

  static async consultarAmlRisk(
    proveedorId: string
  ): Promise<ProveedorAmlCheck | null> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/aml/risk`
      );

    return normalizeAmlCheckFromResponse(
      response.data
    );
  }

  static async consultarAmlResult(
    proveedorId: string
  ): Promise<ProveedorAmlCheck | null> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/aml/result`
      );

    return normalizeAmlCheckFromResponse(
      response.data
    );
  }

  static async consultarAmlFlags(
    proveedorId: string
  ): Promise<ProveedorAmlCheck | null> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/aml/flags`
      );

    return normalizeAmlCheckFromResponse(
      response.data
    );
  }

  /**
   * Genera el reporte en Gesintel y almacena el pdfLink
   * recibido en el backend.
   */
  static async generarAmlReport(
    proveedorId: string
  ): Promise<ProveedorAmlCheck | null> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/aml/report`
      );

    return normalizeAmlCheckFromResponse(
      response.data
    );
  }

  /**
   * Descarga el último PDF protegido del proveedor.
   *
   * Frontend -> Backend:
   * Authorization: Bearer JWT_PORTAL
   *
   * Backend -> Gesintel:
   * Authorization: TOKEN_GESINTEL
   *
   * El token de Gesintel nunca se expone en el frontend.
   */
  static async descargarAmlReportPdf(
    proveedorId: string
  ): Promise<Blob> {
    const response =
      await apiClient.get<Blob>(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/aml/report-pdf`,
        {
          responseType: 'blob',

          headers: {
            Accept:
              'application/pdf',
          },
        }
      );

    if (
      !(response.data instanceof Blob)
    ) {
      throw new Error(
        'El backend no devolvió un archivo válido.'
      );
    }

    return response.data;
  }

  static async registrarAmlProveedor(
    proveedorId: string
  ): Promise<ProveedorAmlRecord | null> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/aml/register`
      );

    return normalizeAmlRecordFromResponse(
      response.data
    );
  }

  static async consultarAmlRecord(
    proveedorId: string
  ): Promise<ProveedorAmlRecord | null> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/aml/get-record`
      );

    return normalizeAmlRecordFromResponse(
      response.data
    );
  }

  static async obtenerAmlPdfRecord(
    proveedorId: string
  ): Promise<ProveedorAmlRecord | null> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/aml/get-pdf-record`
      );

    return normalizeAmlRecordFromResponse(
      response.data
    );
  }

  static async getAmlChecks(
    proveedorId: string
  ): Promise<ProveedorAmlCheck[]> {
    const response =
      await apiClient.get(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/aml/checks`
      );

    const payload =
      unwrapResponse(
        response.data
      );

    if (!Array.isArray(payload)) {
      return [];
    }

    return payload.map(item =>
      normalizeAmlCheck(item)
    );
  }

  static async getAmlRecords(
    proveedorId: string
  ): Promise<ProveedorAmlRecord[]> {
    const response =
      await apiClient.get(
        `/interno/proveedores/${encodeURIComponent(
          proveedorId
        )}/aml/records`
      );

    const payload =
      unwrapResponse(
        response.data
      );

    if (!Array.isArray(payload)) {
      return [];
    }

    return payload.map(item =>
      normalizeAmlRecord(item)
    );
  }

  /*
  |--------------------------------------------------------------------------
  | DICOM / Cobranza
  |--------------------------------------------------------------------------
  */

  static async consultarDicomEmpresa(
    rut: string
  ): Promise<ProveedorDicomCheck | null> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          rut
        )}/dicom/empresa`
      );

    return normalizeDicomCheckFromResponse(
      response.data
    );
  }

  static async consultarDicomPersona(
    rut: string
  ): Promise<ProveedorDicomCheck | null> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          rut
        )}/dicom/persona`
      );

    return normalizeDicomCheckFromResponse(
      response.data
    );
  }

  static async consultarDicomHistorial(
    rut: string
  ): Promise<ProveedorDicomCheck | null> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          rut
        )}/dicom/historial`
      );

    return normalizeDicomCheckFromResponse(
      response.data
    );
  }

  static async consultarDicomInformacion(
    rut: string
  ): Promise<ProveedorDicomCheck | null> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          rut
        )}/dicom/informacion`
      );

    return normalizeDicomCheckFromResponse(
      response.data
    );
  }

  static async consultarDicomLegado(
    rut: string,
    fecha: string
  ): Promise<ProveedorDicomCheck | null> {
    const response =
      await apiClient.post(
        `/interno/proveedores/${encodeURIComponent(
          rut
        )}/dicom/legado/${encodeURIComponent(
          fecha
        )}`
      );

    return normalizeDicomCheckFromResponse(
      response.data
    );
  }

  static async getDicomChecks(
    rut: string
  ): Promise<ProveedorDicomCheck[]> {
    const response =
      await apiClient.get(
        `/interno/proveedores/${encodeURIComponent(
          rut
        )}/dicom/checks`
      );

    const payload =
      unwrapResponse(
        response.data
      );

    if (!Array.isArray(payload)) {
      return [];
    }

    return payload.map(item =>
      normalizeDicomCheck(item)
    );
  }
}

export default InternoProveedoresService;
