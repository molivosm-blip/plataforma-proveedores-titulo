import apiClient from './apiClient';

/*
|--------------------------------------------------------------------------
| Servicios del proceso de incorporación
|--------------------------------------------------------------------------
|
| Declaraciones digitales, evaluación crediticia, plazos por etapa,
| servicio simulado de SAP y notificaciones.
|
*/

export type TipoDeclaracion = 'conflicto_interes' | 'relacionados_pep';

export interface Declaracion {
  id: string;
  proveedorId: string;
  documentoId: string | null;
  tipo: TipoDeclaracion;
  respuestas: Record<string, unknown>;
  declaraRelacionados: boolean;
  declaraPep: boolean;
  declaranteNombre: string;
  declaranteRut: string | null;
  declaranteCargo: string | null;
  declaranteEmail: string | null;
  firmadoAt: string;
  firmaHash: string;
  integridadVerificada: boolean;
}

export type ResultadoSemaforo = 'verde' | 'amarillo' | 'rojo';
export type EstadoRegla = 'cumple' | 'advertencia' | 'no_cumple';
export type ResultadoDicom = 'sin_morosidad' | 'con_morosidad' | 'no_consultado';

export interface ReglaCrediticia {
  clave: string;
  nombre: string;
  estado: EstadoRegla;
  detalle: string;
  valor?: string | null;
}

export interface EvaluacionCrediticia {
  id: string;
  proveedorId: string;
  resultado: ResultadoSemaforo;
  reglas: ReglaCrediticia[];
  fechaInicioActividades: string | null;
  dicomResultado: ResultadoDicom;
  observaciones: string | null;
  origen: 'manual' | 'reevaluacion';
  informeDocumentoId: string | null;
  creadoPor: string;
  createdAt: string;
}

export interface NuevaEvaluacion {
  fecha_inicio_actividades: string | null;
  dicom_resultado: ResultadoDicom;
  dicom_representante_resultado: ResultadoDicom;
  observaciones: string;
}

export interface EtapaPlazo {
  stepId: string;
  area: string;
  stepName: string;
  status: string;
  inicio: string | null;
  fin: string | null;
  dias: number | null;
  plazoDias: number;
  enEspera: boolean;
  vencida: boolean;
}

export interface SapEnvio {
  id: string;
  estado: 'exitoso' | 'observado' | 'error';
  numeroProveedorSap: string | null;
  respuesta: {
    codigo?: string;
    mensaje?: string;
    faltantes?: string[];
    fecha?: string;
  } | null;
  createdAt: string;
}

export interface SapEstado {
  payload: Record<string, unknown>;
  motivoBloqueo: string | null;
  envios: SapEnvio[];
}

export interface Notificacion {
  id: string;
  proveedorId: string | null;
  tipo: string;
  titulo: string;
  mensaje: string;
  emailEnviado: boolean;
  leida: boolean;
  createdAt: string;
}

const declaracionesBase = (proveedorId: string, comoProveedor: boolean): string =>
  comoProveedor
    ? `/proveedor/solicitudes/${encodeURIComponent(proveedorId)}/declaraciones`
    : `/interno/proveedores/${encodeURIComponent(proveedorId)}/declaraciones`;

export const ProcesoProveedorService = {
  async getDeclaraciones(proveedorId: string, comoProveedor = false): Promise<Declaracion[]> {
    const { data } = await apiClient.get(declaracionesBase(proveedorId, comoProveedor));
    return data?.data ?? [];
  },

  async firmarConflictoInteres(proveedorId: string, respuestas: Record<string, unknown>): Promise<Declaracion> {
    const { data } = await apiClient.post(
      `${declaracionesBase(proveedorId, false)}/conflicto-interes`,
      respuestas
    );
    return data?.data;
  },

  async firmarRelacionadosPep(proveedorId: string, respuestas: Record<string, unknown>): Promise<Declaracion> {
    const { data } = await apiClient.post(
      `${declaracionesBase(proveedorId, true)}/relacionados-pep`,
      respuestas
    );
    return data?.data;
  },

  async getEvaluaciones(proveedorId: string): Promise<EvaluacionCrediticia[]> {
    const { data } = await apiClient.get(
      `/interno/proveedores/${encodeURIComponent(proveedorId)}/evaluacion-crediticia`
    );
    return data?.data?.evaluaciones ?? [];
  },

  async crearEvaluacion(proveedorId: string, payload: NuevaEvaluacion): Promise<EvaluacionCrediticia> {
    const { data } = await apiClient.post(
      `/interno/proveedores/${encodeURIComponent(proveedorId)}/evaluacion-crediticia`,
      payload
    );
    return data?.data;
  },

  async reevaluar(proveedorId: string): Promise<EvaluacionCrediticia> {
    const { data } = await apiClient.post(
      `/interno/proveedores/${encodeURIComponent(proveedorId)}/evaluacion-crediticia/reevaluar`
    );
    return data?.data;
  },

  async generarInforme(proveedorId: string, evaluacionId: string): Promise<string> {
    const { data } = await apiClient.post(
      `/interno/proveedores/${encodeURIComponent(proveedorId)}/evaluacion-crediticia/${encodeURIComponent(evaluacionId)}/informe`
    );
    // El informe queda registrado como documento del proveedor; se descarga por su id.
    return data?.data?.evaluacion?.informeDocumentoId ?? '';
  },

  async getPlazos(proveedorId: string): Promise<{ plazoDias: number; etapas: EtapaPlazo[] }> {
    const { data } = await apiClient.get(
      `/interno/proveedores/${encodeURIComponent(proveedorId)}/plazos`
    );
    return data?.data ?? { plazoDias: 0, etapas: [] };
  },

  async getSap(proveedorId: string): Promise<SapEstado> {
    const { data } = await apiClient.get(
      `/interno/proveedores/${encodeURIComponent(proveedorId)}/sap`
    );
    return data?.data;
  },

  async enviarSap(proveedorId: string): Promise<SapEnvio> {
    const { data } = await apiClient.post(
      `/interno/proveedores/${encodeURIComponent(proveedorId)}/sap/enviar`
    );
    return data?.data;
  },

  async getNotificaciones(): Promise<{ items: Notificacion[]; noLeidas: number }> {
    const { data } = await apiClient.get('/notificaciones');
    return data?.data ?? { items: [], noLeidas: 0 };
  },

  async marcarLeida(id: string): Promise<void> {
    await apiClient.put(`/notificaciones/${encodeURIComponent(id)}/leer`);
  },

  async marcarTodasLeidas(): Promise<void> {
    await apiClient.put('/notificaciones/leer-todas');
  },
};

export const mensajeError = (caught: unknown, porDefecto: string): string => {
  const err = caught as { response?: { data?: { message?: string } }; message?: string };
  if (err?.response?.data?.message) {
    return err.response.data.message;
  }
  // Errores propios (por ejemplo, al descargar un documento) ya traen un mensaje legible.
  return caught instanceof Error && caught.message ? caught.message : porDefecto;
};
