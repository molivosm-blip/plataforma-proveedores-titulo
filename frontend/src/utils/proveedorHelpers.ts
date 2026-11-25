import type { RawApiData } from './apiTypes';

export type EstadoProveedor =
  | 'En revisión'
  | 'Observado'
  | 'Rechazado'
  | 'Aprobado';

export type EstadoDocumento =
  | 'Pendiente'
  | 'Subido'
  | 'Observado'
  | 'Aprobado';

export type WorkflowStatus =
  | 'Pendiente'
  | 'En revisión'
  | 'Observado'
  | 'Rechazado'
  | 'Aprobado';

export type WorkflowArea =
  | 'pm'
  | 'contraloria'
  | 'legal_crediticia'
  | 'legal_judicial'
  | 'datos_maestros';

export type TipoProveedorDetalle =
  | 'Proveedor de servicios'
  | 'Proveedor de productos nacional'
  | 'Proveedor de productos internacional';

export type TipoProveedorSap = 'Nacional' | 'Internacional';

export type ResponsableCargaDocumento = 'pm' | 'proveedor' | 'interno';

export interface ProveedorDetalle {
  id?: string;

  direccion?: string;
  comuna?: string;
  region?: string;
  codigoPostal?: string;
  telefono?: string;
  moneda?: string;
  pais?: string;

  representanteLegal?: string;
  representanteCi?: string;
  representanteDireccion?: string;
  representanteEmail?: string;
  representanteCargo?: string;

  personaContacto?: string;
  personaContactoCargo?: string;
  personaContactoTelefono?: string;
  personaContactoEmail?: string;

  fechaEscrituraPublica?: string;
  notaria?: string;

  plazoPago?: string;
  formaPago?: string;
  diasDespacho?: string;
  bancoPago?: string;
  cuentaCorriente?: string;

  dimensionNegocioTexto?: string;
  tipoProveedorDetalle?: TipoProveedorDetalle | '';

  createdAt?: string;
  updatedAt?: string;
}

export interface WorkflowStep {
  id?: string;
  proveedorId?: string;

  stepKey?: string;
  stepName?: string;

  area: WorkflowArea;
  status: WorkflowStatus;

  sortOrder?: number;
  assignedUserId?: string | null;
  validatedByUserId?: string | null;
  validatedAt?: string | null;

  comments?: string;
  createdAt?: string;
  updatedAt?: string;

  updatedBy?: string;
}

export interface ValidacionStep {
  id?: string;
  proveedorId?: string;

  area: WorkflowArea;
  areaName?: string;
  status: WorkflowStatus;

  validatedByUserId?: string | null;
  validatedAt?: string | null;

  comments?: string;
  createdAt?: string;
  updatedAt?: string;

  updatedBy?: string;
}

export interface DocumentoProveedor {
  id: string;
  proveedorId?: string;

  nombre: string;
  estado: EstadoDocumento;

  responsableCarga?: ResponsableCargaDocumento;
  areaResponsable?: WorkflowArea | string | null;

  esPrerrequisito?: boolean;
  bloqueaCargaProveedor?: boolean;

  visibleParaProveedor?: boolean;
  visibleParaInternos?: boolean;

  plantillaUrl?: string | null;

  archivoNombre?: string | null;
  archivoUrl?: string | null;
  archivoMimeType?: string | null;

  uploadedByUserId?: string | null;

  reviewedByUserId?: string | null;
  reviewedAt?: string | null;

  /**
   * Campos locales/mock mientras no se conecta backend.
   */
  archivo?: string;
  archivoDataUrl?: string;
  archivoMimeTypeLegacy?: string | null;

  /**
   * Alias snake_case que algunos endpoints devuelven sin normalizar.
   */
  responsable_carga?: ResponsableCargaDocumento | string;
  area_responsable?: WorkflowArea | string | null;
  es_prerrequisito?: boolean;
  plantilla_url?: string | null;

  comentario?: string;
  createdAt?: string;
  updatedAt?: string;
}

export interface HistorialProveedor {
  id?: string;
  proveedorId?: string;
  accion: string;
  fecha: string;
  createdAt?: string;
  realizadoPor: string;
}

export interface ProveedorPmPrerequisitePending {
  id: string;
  nombre: string;
  estado: EstadoDocumento;
  responsableCarga?: ResponsableCargaDocumento | string;
  areaResponsable?: WorkflowArea | string | null;
}

export interface Proveedor {
  id: string;

  razonSocial: string;
  rut: string;
  giro: string;
  tipo: TipoProveedorSap;

  empresaId?: string;
  empresaNombre: string;

  estado: EstadoProveedor;

  canUploadDocuments?: boolean;
  pmPrerequisitesPending?: ProveedorPmPrerequisitePending[];

  createdById?: string | null;
  createdByEmail?: string | null;
  createdByName?: string | null;

  proveedorUserId?: string | null;
  proveedorEmail?: string | null;
  proveedorNombre?: string | null;

  detalle?: ProveedorDetalle | null;

  /**
   * Lista múltiple.
   * Una sociedad seleccionada implica habilitación como sociedad y compra.
   */
  sociedadesHabilitadas: string[];

  documentos: DocumentoProveedor[];
  historial: HistorialProveedor[];

  workflow: WorkflowStep[];
  workflowSteps?: WorkflowStep[];

  validaciones: ValidacionStep[];

  createdAt: string;
  updatedAt?: string;
}

export const DOCUMENTOS_REQUERIDOS = [
  'Correo de aprobación de Gerencia',
  'Declaración Conflicto de Interés Solicitante',
  'Referencia comercial',
  'Ficha de incorporación proveedor',
] as const;

export const WORKFLOW_AREAS: WorkflowArea[] = [
  'pm',
  'contraloria',
  'legal_crediticia',
  'legal_judicial',
  'datos_maestros',
];

export const WORKFLOW_AREA_LABELS: Record<WorkflowArea, string> = {
  pm: 'PM',
  contraloria: 'Contraloría',
  legal_crediticia: 'Legal Crediticia',
  legal_judicial: 'Legal Judicial',
  datos_maestros: 'Datos Maestros',
};

export const WORKFLOW_SORT_ORDER: Record<WorkflowArea, number> = {
  pm: 1,
  contraloria: 2,
  legal_crediticia: 3,
  legal_judicial: 4,
  datos_maestros: 5,
};

export const buildDefaultWorkflow = (): WorkflowStep[] =>
  WORKFLOW_AREAS.map(area => ({
    stepKey: area,
    stepName: WORKFLOW_AREA_LABELS[area],
    area,
    status: area === 'pm' ? 'En revisión' : 'Pendiente',
    sortOrder: WORKFLOW_SORT_ORDER[area],
    comments: '',
  }));

export const buildDefaultValidaciones = (): ValidacionStep[] =>
  WORKFLOW_AREAS.map(area => ({
    area,
    areaName: WORKFLOW_AREA_LABELS[area],
    status: area === 'pm' ? 'En revisión' : 'Pendiente',
    comments: '',
  }));

export const isWorkflowArea = (value: unknown): value is WorkflowArea =>
  typeof value === 'string' && WORKFLOW_AREAS.includes(value as WorkflowArea);

export const isWorkflowStatus = (value: unknown): value is WorkflowStatus =>
  value === 'Pendiente' ||
  value === 'En revisión' ||
  value === 'Observado' ||
  value === 'Rechazado' ||
  value === 'Aprobado';

export const isEstadoDocumento = (value: unknown): value is EstadoDocumento =>
  value === 'Pendiente' ||
  value === 'Subido' ||
  value === 'Observado' ||
  value === 'Aprobado';

export const isEstadoProveedor = (value: unknown): value is EstadoProveedor =>
  value === 'En revisión' ||
  value === 'Observado' ||
  value === 'Rechazado' ||
  value === 'Aprobado';

export const isTipoProveedorDetalle = (
  value: unknown
): value is TipoProveedorDetalle =>
  value === 'Proveedor de servicios' ||
  value === 'Proveedor de productos nacional' ||
  value === 'Proveedor de productos internacional';

export const isResponsableCargaDocumento = (
  value: unknown
): value is ResponsableCargaDocumento =>
  value === 'pm' || value === 'proveedor' || value === 'interno';

export const deriveEstadoProveedor = (
  proveedor: Pick<Proveedor, 'documentos' | 'workflow' | 'validaciones'>
): EstadoProveedor => {
  const allStates = [
    ...proveedor.documentos.map(documento => documento.estado),
    ...proveedor.workflow.map(step => step.status),
    ...proveedor.validaciones.map(validacion => validacion.status),
  ];

  if (allStates.some(status => status === 'Rechazado')) {
    return 'Rechazado';
  }

  if (allStates.some(status => status === 'Observado')) {
    return 'Observado';
  }

  const workflowAprobado =
    proveedor.workflow.length > 0 &&
    proveedor.workflow.every(step => step.status === 'Aprobado');

  const validacionesAprobadas =
    proveedor.validaciones.length > 0 &&
    proveedor.validaciones.every(validacion => validacion.status === 'Aprobado');

  const documentosAprobados =
    proveedor.documentos.length > 0 &&
    proveedor.documentos.every(documento => documento.estado === 'Aprobado');

  if (workflowAprobado && validacionesAprobadas && documentosAprobados) {
    return 'Aprobado';
  }

  return 'En revisión';
};

const toStringOrNull = (value: unknown): string | null => {
  if (typeof value === 'string') {
    const trimmed = value.trim();
    return trimmed.length > 0 ? trimmed : null;
  }

  if (typeof value === 'number') {
    return String(value);
  }

  return null;
};

const toBooleanOrDefault = (
  value: unknown,
  defaultValue: boolean
): boolean => {
  if (typeof value === 'boolean') return value;

  if (typeof value === 'number') {
    return value === 1;
  }

  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase();

    if (['1', 'true', 'yes', 'si', 'sí'].includes(normalized)) {
      return true;
    }

    if (['0', 'false', 'no'].includes(normalized)) {
      return false;
    }
  }

  return defaultValue;
};

const normalizeSociedades = (raw: RawApiData): string[] => {
  const source: RawApiData[] = Array.isArray(raw?.sociedadesHabilitadas)
    ? raw.sociedadesHabilitadas
    : Array.isArray(raw?.sociedades)
      ? raw.sociedades
      : [];

  const sociedades: string[] = [];

  source.forEach((item: RawApiData) => {
    let nombre: string | null = null;

    if (typeof item === 'string') {
      nombre = item;
    } else if (item && typeof item === 'object') {
      nombre =
        toStringOrNull(item.sociedadNombre) ??
        toStringOrNull(item.nombre) ??
        toStringOrNull(item.sociedad_nombre);
    }

    if (nombre) {
      sociedades.push(nombre);
    }
  });

  return Array.from(new Set(sociedades));
};

const normalizeDetalle = (raw: RawApiData): ProveedorDetalle | null => {
  const detalleRaw = raw?.detalle ?? raw?.datosIncorporacion ?? null;

  if (!detalleRaw || typeof detalleRaw !== 'object') {
    return null;
  }

  const tipoProveedorDetalleRaw =
    detalleRaw?.tipoProveedorDetalle ?? detalleRaw?.tipo_proveedor_detalle;

  const detalle: ProveedorDetalle = {
    direccion: detalleRaw?.direccion ?? '',
    comuna: detalleRaw?.comuna ?? '',
    region: detalleRaw?.region ?? '',
    codigoPostal: detalleRaw?.codigoPostal ?? detalleRaw?.codigo_postal ?? '',
    telefono: detalleRaw?.telefono ?? '',
    moneda: detalleRaw?.moneda ?? 'CLP',
    pais: detalleRaw?.pais ?? 'Chile',

    representanteLegal:
      detalleRaw?.representanteLegal ?? detalleRaw?.representante_legal ?? '',
    representanteCi:
      detalleRaw?.representanteCi ?? detalleRaw?.representante_ci ?? '',
    representanteDireccion:
      detalleRaw?.representanteDireccion ??
      detalleRaw?.representante_direccion ??
      '',
    representanteEmail:
      detalleRaw?.representanteEmail ?? detalleRaw?.representante_email ?? '',
    representanteCargo:
      detalleRaw?.representanteCargo ?? detalleRaw?.representante_cargo ?? '',

    personaContacto:
      detalleRaw?.personaContacto ?? detalleRaw?.persona_contacto ?? '',
    personaContactoCargo:
      detalleRaw?.personaContactoCargo ??
      detalleRaw?.persona_contacto_cargo ??
      '',
    personaContactoTelefono:
      detalleRaw?.personaContactoTelefono ??
      detalleRaw?.persona_contacto_telefono ??
      '',
    personaContactoEmail:
      detalleRaw?.personaContactoEmail ??
      detalleRaw?.persona_contacto_email ??
      '',

    fechaEscrituraPublica:
      detalleRaw?.fechaEscrituraPublica ??
      detalleRaw?.fecha_escritura_publica ??
      '',
    notaria: detalleRaw?.notaria ?? '',

    plazoPago: detalleRaw?.plazoPago ?? detalleRaw?.plazo_pago ?? '',
    formaPago: detalleRaw?.formaPago ?? detalleRaw?.forma_pago ?? '',
    diasDespacho: detalleRaw?.diasDespacho ?? detalleRaw?.dias_despacho ?? '',
    bancoPago: detalleRaw?.bancoPago ?? detalleRaw?.banco_pago ?? '',
    cuentaCorriente:
      detalleRaw?.cuentaCorriente ?? detalleRaw?.cuenta_corriente ?? '',

    dimensionNegocioTexto:
      detalleRaw?.dimensionNegocioTexto ??
      detalleRaw?.dimension_negocio_texto ??
      detalleRaw?.dimensionNegocio ??
      '',

    tipoProveedorDetalle: isTipoProveedorDetalle(tipoProveedorDetalleRaw)
      ? tipoProveedorDetalleRaw
      : '',
  };

  const id = toStringOrNull(detalleRaw?.id);
  if (id) {
    detalle.id = id;
  }

  const createdAt = toStringOrNull(
    detalleRaw?.createdAt ?? detalleRaw?.created_at
  );
  if (createdAt) {
    detalle.createdAt = createdAt;
  }

  const updatedAt = toStringOrNull(
    detalleRaw?.updatedAt ?? detalleRaw?.updated_at
  );
  if (updatedAt) {
    detalle.updatedAt = updatedAt;
  }

  return detalle;
};

const normalizeDocumento = (doc: RawApiData, index: number): DocumentoProveedor => {
  const archivoNombre =
    toStringOrNull(doc?.archivoNombre) ??
    toStringOrNull(doc?.archivo_nombre) ??
    toStringOrNull(doc?.archivo);

  const archivoUrl =
    toStringOrNull(doc?.archivoUrl) ??
    toStringOrNull(doc?.archivo_url) ??
    toStringOrNull(doc?.archivoDataUrl);

  const archivoMimeType =
    toStringOrNull(doc?.archivoMimeType) ??
    toStringOrNull(doc?.archivo_mime_type) ??
    toStringOrNull(doc?.archivoMimeTypeLegacy);

  const estado: EstadoDocumento = isEstadoDocumento(doc?.estado)
    ? doc.estado
    : archivoUrl || doc?.archivoDataUrl || doc?.archivo
      ? 'Subido'
      : 'Pendiente';

  const responsableCargaRaw =
    doc?.responsableCarga ?? doc?.responsable_carga;

  const areaResponsable =
    toStringOrNull(doc?.areaResponsable) ??
    toStringOrNull(doc?.area_responsable);

  const plantillaUrl =
    toStringOrNull(doc?.plantillaUrl) ??
    toStringOrNull(doc?.plantilla_url);

  const documento: DocumentoProveedor = {
    id: String(doc?.id ?? `doc-${index + 1}`),
    nombre:
      doc?.nombre ??
      DOCUMENTOS_REQUERIDOS[index] ??
      `Documento ${index + 1}`,
    estado,
    archivoNombre,
    archivoUrl,
    archivoMimeType,

    esPrerrequisito: toBooleanOrDefault(
      doc?.esPrerrequisito ?? doc?.es_prerrequisito,
      false
    ),
    bloqueaCargaProveedor: toBooleanOrDefault(
      doc?.bloqueaCargaProveedor ?? doc?.bloquea_carga_proveedor,
      false
    ),
    visibleParaProveedor: toBooleanOrDefault(
      doc?.visibleParaProveedor ?? doc?.visible_para_proveedor,
      true
    ),
    visibleParaInternos: toBooleanOrDefault(
      doc?.visibleParaInternos ?? doc?.visible_para_internos,
      true
    ),
    plantillaUrl,
  };

  if (isResponsableCargaDocumento(responsableCargaRaw)) {
    documento.responsableCarga = responsableCargaRaw;
  }

  if (areaResponsable) {
    documento.areaResponsable = areaResponsable;
  }

  const proveedorId =
    toStringOrNull(doc?.proveedorId) ?? toStringOrNull(doc?.proveedor_id);
  if (proveedorId) {
    documento.proveedorId = proveedorId;
  }

  const uploadedByUserId =
    toStringOrNull(doc?.uploadedByUserId) ??
    toStringOrNull(doc?.uploaded_by_user_id);
  if (uploadedByUserId) {
    documento.uploadedByUserId = uploadedByUserId;
  }

  const reviewedByUserId =
    toStringOrNull(doc?.reviewedByUserId) ??
    toStringOrNull(doc?.reviewed_by_user_id);
  if (reviewedByUserId) {
    documento.reviewedByUserId = reviewedByUserId;
  }

  const reviewedAt =
    toStringOrNull(doc?.reviewedAt) ?? toStringOrNull(doc?.reviewed_at);
  if (reviewedAt) {
    documento.reviewedAt = reviewedAt;
  }

  const archivo = toStringOrNull(doc?.archivo);
  if (archivo) {
    documento.archivo = archivo;
  }

  const archivoDataUrl = toStringOrNull(doc?.archivoDataUrl);
  if (archivoDataUrl) {
    documento.archivoDataUrl = archivoDataUrl;
  }

  const archivoMimeTypeLegacy = toStringOrNull(doc?.archivoMimeTypeLegacy);
  if (archivoMimeTypeLegacy) {
    documento.archivoMimeTypeLegacy = archivoMimeTypeLegacy;
  }

  const comentario = toStringOrNull(doc?.comentario);
  if (comentario) {
    documento.comentario = comentario;
  }

  const createdAt = toStringOrNull(doc?.createdAt ?? doc?.created_at);
  if (createdAt) {
    documento.createdAt = createdAt;
  }

  const updatedAt = toStringOrNull(doc?.updatedAt ?? doc?.updated_at);
  if (updatedAt) {
    documento.updatedAt = updatedAt;
  }

  return documento;
};

const normalizeWorkflowStep = (step: RawApiData): WorkflowStep | null => {
  const areaValue: unknown = step?.area;
  const statusValue: unknown = step?.status;

  if (!isWorkflowArea(areaValue) || !isWorkflowStatus(statusValue)) {
    return null;
  }

  const area: WorkflowArea = areaValue;
  const status: WorkflowStatus = statusValue;

  const sortOrder =
    typeof step?.sortOrder === 'number'
      ? step.sortOrder
      : typeof step?.sort_order === 'number'
        ? step.sort_order
        : WORKFLOW_SORT_ORDER[area];

  const workflowStep: WorkflowStep = {
    area,
    status,
    stepKey: step?.stepKey ?? step?.step_key ?? area,
    stepName: step?.stepName ?? step?.step_name ?? WORKFLOW_AREA_LABELS[area],
    sortOrder,
    comments: typeof step?.comments === 'string' ? step.comments : '',
  };

  const id = toStringOrNull(step?.id);
  if (id) {
    workflowStep.id = id;
  }

  const proveedorId =
    toStringOrNull(step?.proveedorId) ?? toStringOrNull(step?.proveedor_id);
  if (proveedorId) {
    workflowStep.proveedorId = proveedorId;
  }

  const assignedUserId =
    toStringOrNull(step?.assignedUserId) ??
    toStringOrNull(step?.assigned_user_id);
  if (assignedUserId) {
    workflowStep.assignedUserId = assignedUserId;
  }

  const validatedByUserId =
    toStringOrNull(step?.validatedByUserId) ??
    toStringOrNull(step?.validated_by_user_id);
  if (validatedByUserId) {
    workflowStep.validatedByUserId = validatedByUserId;
  }

  const validatedAt =
    toStringOrNull(step?.validatedAt) ?? toStringOrNull(step?.validated_at);
  if (validatedAt) {
    workflowStep.validatedAt = validatedAt;
  }

  const createdAt = toStringOrNull(step?.createdAt ?? step?.created_at);
  if (createdAt) {
    workflowStep.createdAt = createdAt;
  }

  const updatedAt = toStringOrNull(step?.updatedAt ?? step?.updated_at);
  if (updatedAt) {
    workflowStep.updatedAt = updatedAt;
  }

  const updatedBy = toStringOrNull(step?.updatedBy);
  if (updatedBy) {
    workflowStep.updatedBy = updatedBy;
  }

  return workflowStep;
};

const normalizeValidacion = (step: RawApiData): ValidacionStep | null => {
  const areaValue: unknown = step?.area;
  const statusValue: unknown = step?.status;

  if (!isWorkflowArea(areaValue) || !isWorkflowStatus(statusValue)) {
    return null;
  }

  const area: WorkflowArea = areaValue;
  const status: WorkflowStatus = statusValue;

  const validacion: ValidacionStep = {
    area,
    status,
    areaName: step?.areaName ?? step?.area_name ?? WORKFLOW_AREA_LABELS[area],
    comments: typeof step?.comments === 'string' ? step.comments : '',
  };

  const id = toStringOrNull(step?.id);
  if (id) {
    validacion.id = id;
  }

  const proveedorId =
    toStringOrNull(step?.proveedorId) ?? toStringOrNull(step?.proveedor_id);
  if (proveedorId) {
    validacion.proveedorId = proveedorId;
  }

  const validatedByUserId =
    toStringOrNull(step?.validatedByUserId) ??
    toStringOrNull(step?.validated_by_user_id);
  if (validatedByUserId) {
    validacion.validatedByUserId = validatedByUserId;
  }

  const validatedAt =
    toStringOrNull(step?.validatedAt) ?? toStringOrNull(step?.validated_at);
  if (validatedAt) {
    validacion.validatedAt = validatedAt;
  }

  const createdAt = toStringOrNull(step?.createdAt ?? step?.created_at);
  if (createdAt) {
    validacion.createdAt = createdAt;
  }

  const updatedAt = toStringOrNull(step?.updatedAt ?? step?.updated_at);
  if (updatedAt) {
    validacion.updatedAt = updatedAt;
  }

  const updatedBy = toStringOrNull(step?.updatedBy);
  if (updatedBy) {
    validacion.updatedBy = updatedBy;
  }

  return validacion;
};

const normalizeHistorial = (item: RawApiData): HistorialProveedor => {
  const fecha =
    toStringOrNull(item?.fecha) ??
    toStringOrNull(item?.createdAt) ??
    toStringOrNull(item?.created_at) ??
    new Date().toISOString();

  const historial: HistorialProveedor = {
    accion: toStringOrNull(item?.accion) ?? 'Sin detalle',
    fecha,
    realizadoPor:
      toStringOrNull(item?.realizadoPor) ??
      toStringOrNull(item?.realizado_por) ??
      'Sistema',
  };

  const id = toStringOrNull(item?.id);
  if (id) {
    historial.id = id;
  }

  const proveedorId =
    toStringOrNull(item?.proveedorId) ?? toStringOrNull(item?.proveedor_id);
  if (proveedorId) {
    historial.proveedorId = proveedorId;
  }

  const createdAt = toStringOrNull(item?.createdAt ?? item?.created_at);
  if (createdAt) {
    historial.createdAt = createdAt;
  }

  return historial;
};

const normalizePmPrerequisitesPending = (
  raw: RawApiData
): ProveedorPmPrerequisitePending[] => {
  const source = Array.isArray(raw?.pmPrerequisitesPending)
    ? raw.pmPrerequisitesPending
    : Array.isArray(raw?.pm_prerequisites_pending)
      ? raw.pm_prerequisites_pending
      : [];

  return source.map((item: RawApiData, index: number) => {
    const estadoRaw = item?.estado;
    const responsableRaw = item?.responsableCarga ?? item?.responsable_carga;

    const pending: ProveedorPmPrerequisitePending = {
      id: String(item?.id ?? `pending-${index + 1}`),
      nombre: toStringOrNull(item?.nombre) ?? `Documento pendiente ${index + 1}`,
      estado: isEstadoDocumento(estadoRaw) ? estadoRaw : 'Pendiente',
      areaResponsable:
        toStringOrNull(item?.areaResponsable) ??
        toStringOrNull(item?.area_responsable),
    };

    if (isResponsableCargaDocumento(responsableRaw)) {
      pending.responsableCarga = responsableRaw;
    }

    return pending;
  });
};

export const normalizeProveedor = (raw: RawApiData): Proveedor => {
  const documentosRaw = Array.isArray(raw?.documentos) ? raw.documentos : [];

  const workflowRaw = Array.isArray(raw?.workflow)
    ? raw.workflow
    : Array.isArray(raw?.workflowSteps)
      ? raw.workflowSteps
      : [];

  const validacionesRaw = Array.isArray(raw?.validaciones)
    ? raw.validaciones
    : [];

  const documentos: DocumentoProveedor[] =
    documentosRaw.length > 0
      ? documentosRaw.map((doc: RawApiData, index: number) =>
          normalizeDocumento(doc, index)
        )
      : DOCUMENTOS_REQUERIDOS.map((nombre, index) => ({
          id: `doc-${index + 1}`,
          nombre,
          estado: 'Pendiente',
          responsableCarga: 'proveedor',
          esPrerrequisito: false,
          bloqueaCargaProveedor: false,
          visibleParaProveedor: true,
          visibleParaInternos: true,
          plantillaUrl: null,
        }));

  const workflow: WorkflowStep[] =
    workflowRaw.length > 0
      ? workflowRaw
          .map((step: RawApiData) => normalizeWorkflowStep(step))
          .filter(
            (item: WorkflowStep | null): item is WorkflowStep => item !== null
          )
      : buildDefaultWorkflow();

  const validaciones: ValidacionStep[] =
    validacionesRaw.length > 0
      ? validacionesRaw
          .map((step: RawApiData) => normalizeValidacion(step))
          .filter(
            (item: ValidacionStep | null): item is ValidacionStep =>
              item !== null
          )
      : buildDefaultValidaciones();

  const historial: HistorialProveedor[] = Array.isArray(raw?.historial)
    ? raw.historial.map((item: RawApiData) => normalizeHistorial(item))
    : [];

  const detalle = normalizeDetalle(raw);
  const sociedadesHabilitadas = normalizeSociedades(raw);

  const proveedorBase: Proveedor = {
    id: String(raw?.id ?? `prov-${Date.now()}`),
    razonSocial: raw?.razonSocial ?? raw?.razon_social ?? '',
    rut: raw?.rut ?? '',
    giro: raw?.giro ?? '',
    tipo: raw?.tipo === 'Internacional' ? 'Internacional' : 'Nacional',

    empresaNombre:
      raw?.empresaNombre ??
      raw?.empresa_nombre ??
      raw?.empresa ??
      raw?.empresaId ??
      raw?.empresa_id ??
      'Sin empresa',

    estado: 'En revisión',

    canUploadDocuments: toBooleanOrDefault(
      raw?.canUploadDocuments ?? raw?.can_upload_documents,
      true
    ),

    pmPrerequisitesPending: normalizePmPrerequisitesPending(raw),

    createdById:
      toStringOrNull(raw?.createdById) ??
      toStringOrNull(raw?.created_by_user_id),

    createdByEmail:
      toStringOrNull(raw?.createdByEmail) ??
      toStringOrNull(raw?.created_by_email) ??
      toStringOrNull(raw?.creador),

    createdByName:
      toStringOrNull(raw?.createdByName) ??
      toStringOrNull(raw?.created_by_name) ??
      toStringOrNull(raw?.creador),

    proveedorUserId:
      toStringOrNull(raw?.proveedorUserId) ??
      toStringOrNull(raw?.proveedor_user_id),

    proveedorEmail:
      toStringOrNull(raw?.proveedorEmail) ??
      toStringOrNull(raw?.proveedor_email),

    proveedorNombre:
      toStringOrNull(raw?.proveedorNombre) ??
      toStringOrNull(raw?.proveedor_nombre),

    detalle,
    sociedadesHabilitadas,

    documentos,
    historial,
    workflow,
    workflowSteps: workflow,
    validaciones,

    createdAt:
      toStringOrNull(raw?.createdAt) ??
      toStringOrNull(raw?.created_at) ??
      new Date().toISOString(),
  };

  const empresaId =
    toStringOrNull(raw?.empresaId) ?? toStringOrNull(raw?.empresa_id);
  if (empresaId) {
    proveedorBase.empresaId = empresaId;
  }

  const updatedAt =
    toStringOrNull(raw?.updatedAt) ?? toStringOrNull(raw?.updated_at);
  if (updatedAt) {
    proveedorBase.updatedAt = updatedAt;
  }

  const estadoRaw = raw?.estado;
  const estado: EstadoProveedor = isEstadoProveedor(estadoRaw)
    ? estadoRaw
    : deriveEstadoProveedor(proveedorBase);

  return {
    ...proveedorBase,
    estado,
  };
};