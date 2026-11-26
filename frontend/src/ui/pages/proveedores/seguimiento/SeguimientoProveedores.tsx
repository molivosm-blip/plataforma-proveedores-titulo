import React, {
  useEffect,
  useMemo,
  useState,
} from 'react';

import {
  Navigate,
  useNavigate,
} from 'react-router-dom';

import DataTable, {
  type TableColumn,
} from 'react-data-table-component';

import { useAuth } from '../../../../config/useAuth';

import {
  InternoProveedoresService,
  type ProveedorAmlCheck,
  type ProveedorDicomCheck,
} from '../../../../infrastructure/auth/api/internoProveedoresService';

import {
  type DocumentoProveedor,
  type EstadoDocumento,
  type EstadoProveedor,
  type Proveedor,
  type WorkflowArea,
  type WorkflowStatus,
} from '../../../../utils/proveedorHelpers';

import { descargarArchivoDocumento } from '../../../../infrastructure/auth/api/documentosArchivoService';
import { useArchivoDocumento } from '../../../../hooks/useArchivoDocumento';

import { ui } from '../../../theme/commonStyles';

import {
  colors,
  radius,
  spacing,
} from '../../../theme/tokens';

import PageHero from '../../../components/common/PageHero';
import ValidacionesPanel from './ValidacionesPanel';
import EvaluacionCrediticiaPanel from '../../../components/proceso/EvaluacionCrediticiaPanel';
import SapMockPanel from '../../../components/proceso/SapMockPanel';
import PlazosPanel from '../../../components/proceso/PlazosPanel';
import type { AxiosError } from 'axios';
import type {
  ApiError,
  ApiErrorBody,
} from '../../../../utils/apiTypes';

/*
|--------------------------------------------------------------------------
| Tipos
|--------------------------------------------------------------------------
*/

type InternalRole =
  | 'pm_admin'
  | 'pm_user'
  | 'admin_portal'
  | 'admin_area'
  | 'usuario_area';

type ProveedorModalType =
  | 'detalle'
  | 'validaciones'
  | 'documentos'
  | 'apis'
  | 'historial'
  | 'evaluacion'
  | 'sap'
  | 'plazos'
  | null;

type AmlAction =
  | 'risk'
  | 'result'
  | 'flags'
  | 'report';

type DicomAction =
  | 'empresa'
  | 'persona'
  | 'historial'
  | 'informacion';

const INTERNAL_ROLES: InternalRole[] = [
  'pm_admin',
  'pm_user',
  'admin_portal',
  'admin_area',
  'usuario_area',
];

const ESTADOS_PROVEEDOR: Array<
  'Todos' | EstadoProveedor
> = [
  'Todos',
  'En revisión',
  'Observado',
  'Rechazado',
  'Aprobado',
];

const API_ALLOWED_AREAS = [
  'contraloria',
  'legal_crediticia',
] as const;

const API_BASE_URL =
  import.meta.env.VITE_PROVEEDORES_API_URL || '';

const API_ORIGIN =
  API_BASE_URL.replace(/\/v1\/?$/, '');

/*
|--------------------------------------------------------------------------
| Helpers de roles y permisos
|--------------------------------------------------------------------------
*/

const isAdminGlobalRole = (
  role?: string
): boolean =>
  role === 'pm_admin' ||
  role === 'admin_portal';

const isAreaRole = (
  role?: string
): boolean =>
  role === 'admin_area' ||
  role === 'usuario_area';

const isInternalRole = (
  role?: string
): role is InternalRole =>
  Boolean(
    role &&
      INTERNAL_ROLES.includes(
        role as InternalRole
      )
  );

const canCreateProveedor = (
  role?: string,
  area?: string | null
): boolean => {
  if (isAdminGlobalRole(role)) {
    return true;
  }

  if (role === 'pm_user') {
    return true;
  }

  return Boolean(
    isAreaRole(role) &&
      area === 'pm'
  );
};

const canUpdateEstadoGeneral = (
  role?: string
): boolean =>
  role === 'pm_admin' ||
  role === 'admin_portal' ||
  role === 'pm_user';

const canManageDocumentoInterno = (
  documento: DocumentoProveedor,
  role?: string,
  userArea?: string | null
): boolean => {
  if (isAdminGlobalRole(role)) {
    return true;
  }

  const responsable =
    documento.responsableCarga ??
    documento.responsable_carga;

  const area =
    documento.areaResponsable ??
    documento.area_responsable;

  if (role === 'pm_user') {
    return (
      responsable === 'pm' ||
      area === 'pm'
    );
  }

  if (isAreaRole(role)) {
    return Boolean(
      userArea &&
        area === userArea
    );
  }

  return false;
};

const isFichaAutomatica = (
  documento: DocumentoProveedor
): boolean =>
  documento.nombre ===
  'Ficha de incorporación proveedor';

const canUploadDocumentoInterno = (
  documento: DocumentoProveedor,
  role?: string,
  userArea?: string | null
): boolean => {
  if (isFichaAutomatica(documento)) {
    return false;
  }

  return canManageDocumentoInterno(
    documento,
    role,
    userArea
  );
};

const canReviewProveedorDocuments = (
  proveedor: Proveedor,
  role?: string,
  userArea?: string | null
): boolean => {
  if (isAdminGlobalRole(role)) {
    return true;
  }

  return proveedor.documentos.some(
    documento =>
      canManageDocumentoInterno(
        documento,
        role,
        userArea
      )
  );
};

/**
 * DICOM y Gesintel solamente se muestran para:
 * - admin_area de Contraloría o Legal Crediticia.
 * - usuario_area de Contraloría o Legal Crediticia.
 */
const canUseProviderApis = (
  role?: string,
  userArea?: string | null
): boolean => {
  if (!isAreaRole(role) || !userArea) {
    return false;
  }

  return API_ALLOWED_AREAS.includes(
    userArea as
      (typeof API_ALLOWED_AREAS)[number]
  );
};

const canViewProveedor = (
  proveedor: Proveedor,
  role?: string,
  userEmail?: string,
  userArea?: string | null
): boolean => {
  if (isAdminGlobalRole(role) || role === 'auditor') {
    return true;
  }

  const normalizedEmail =
    userEmail?.trim().toLowerCase();

  if (role === 'pm_user') {
    return (
      proveedor.createdByEmail
        ?.trim()
        .toLowerCase() ===
      normalizedEmail
    );
  }

  if (isAreaRole(role)) {
    if (!userArea) {
      return false;
    }

    return proveedor.documentos.some(
      documento => {
        const area =
          documento.areaResponsable ??
          documento
            .area_responsable;

        return area === userArea;
      }
    );
  }

  return false;
};

/*
|--------------------------------------------------------------------------
| Helpers visuales
|--------------------------------------------------------------------------
*/

const getEstadoBadgeStyle = (
  estado: EstadoProveedor
): React.CSSProperties => {
  const base: React.CSSProperties = {
    padding: '7px 12px',
    borderRadius: radius.pill,
    fontSize: '12px',
    fontWeight: 700,
    display: 'inline-flex',
    alignItems: 'center',
  };

  switch (estado) {
    case 'Aprobado':
      return {
        ...base,
        background: colors.successSoft,
        color: '#166534',
      };

    case 'Rechazado':
      return {
        ...base,
        background: colors.dangerSoft,
        color: '#991b1b',
      };

    case 'Observado':
      return {
        ...base,
        background: colors.warningSoft,
        color: '#92400e',
      };

    default:
      return {
        ...base,
        background: colors.infoSoft,
        color: '#1d4ed8',
      };
  }
};

const getDocumentoBadgeStyle = (
  estado: EstadoDocumento
): React.CSSProperties => {
  const base: React.CSSProperties = {
    padding: '5px 10px',
    borderRadius: radius.pill,
    fontSize: '11px',
    fontWeight: 700,
    display: 'inline-flex',
  };

  switch (estado) {
    case 'Aprobado':
      return {
        ...base,
        background: colors.successSoft,
        color: '#166534',
      };

    case 'Observado':
      return {
        ...base,
        background: colors.warningSoft,
        color: '#92400e',
      };

    case 'Subido':
      return {
        ...base,
        background: colors.infoSoft,
        color: '#1d4ed8',
      };

    default:
      return {
        ...base,
        background: '#e5e7eb',
        color: '#374151',
      };
  }
};

const getResponsableLabel = (
  documento: DocumentoProveedor
): string => {
  const responsable =
    documento.responsableCarga ??
    documento.responsable_carga;

  if (responsable === 'pm') {
    return 'Solicitante / PM';
  }

  if (responsable === 'interno') {
    return 'Sistema / Interno';
  }

  if (responsable === 'proveedor') {
    return 'Proveedor';
  }

  return 'No definido';
};

const getAreaResponsableLabel = (
  documento: DocumentoProveedor
): string => {
  const area =
    documento.areaResponsable ??
    documento.area_responsable;

  switch (area) {
    case 'pm':
      return 'PM';
    case 'contraloria':
      return 'Contraloría';
    case 'legal_crediticia':
      return 'Legal Crediticia';
    case 'legal_judicial':
      return 'Legal Judicial';
    case 'datos_maestros':
      return 'Datos Maestros';
    default:
      return area
        ? String(area)
        : 'Sin área asignada';
  }
};

/*
|--------------------------------------------------------------------------
| Helpers de archivos
|--------------------------------------------------------------------------
*/

const validateDocumentoFile = (
  file?: File
): string | null => {
  if (!file) {
    return 'Debes seleccionar un archivo.';
  }

  const allowedTypes = [
    'application/pdf',
    'image/png',
    'image/jpeg',
  ];

  const allowedExtensions = [
    '.pdf',
    '.png',
    '.jpg',
    '.jpeg',
  ];

  const fileName =
    file.name.toLowerCase();

  const allowedExtension =
    allowedExtensions.some(extension =>
      fileName.endsWith(extension)
    );

  if (
    !allowedTypes.includes(file.type) &&
    !allowedExtension
  ) {
    return (
      'Formato no permitido. ' +
      'Solo se aceptan PDF, PNG, JPG o JPEG.'
    );
  }

  if (file.size > 5 * 1024 * 1024) {
    return (
      'El archivo es demasiado grande. ' +
      'El máximo permitido es 5 MB.'
    );
  }

  return null;
};

const getDocumentoNombre = (
  documento: DocumentoProveedor
): string =>
  documento.archivoNombre ||
  documento.archivo ||
  `${documento.nombre}.pdf`;

const getDocumentoUrl = (
  documento: DocumentoProveedor
): string | null =>
  documento.archivoDataUrl ||
  documento.archivoUrl ||
  null;

const isImageDocumento = (
  documento: DocumentoProveedor
): boolean => {
  const mime =
    documento.archivoMimeType ||
    documento.archivoMimeTypeLegacy ||
    '';

  return mime.startsWith('image/');
};

const buildPublicUrl = (
  url?: string | null
): string | null => {
  if (!url) {
    return null;
  }

  const cleaned = url.trim();

  if (!cleaned) {
    return null;
  }

  if (
    cleaned.startsWith('http://') ||
    cleaned.startsWith('https://') ||
    cleaned.startsWith('blob:') ||
    cleaned.startsWith('data:')
  ) {
    return cleaned;
  }

  if (cleaned.startsWith('/uploads')) {
    return `${API_ORIGIN}${cleaned}`;
  }

  if (cleaned.startsWith('/')) {
    return `${API_ORIGIN}${cleaned}`;
  }

  return `${API_ORIGIN}/${cleaned}`;
};

const getPlantillaUrl = (
  documento: DocumentoProveedor
): string | null =>
  buildPublicUrl(
    documento.plantillaUrl ??
      documento.plantilla_url ??
      null
  );

const buildFallbackText = (
  proveedor: Proveedor,
  documento: DocumentoProveedor
): string =>
  [
    `Proveedor: ${proveedor.razonSocial}`,
    `Empresa: ${proveedor.empresaNombre}`,
    `Documento: ${documento.nombre}`,
    `Archivo: ${getDocumentoNombre(documento)}`,
    `Estado: ${documento.estado}`,
    `Actualizado: ${
      documento.updatedAt
        ? new Date(
            documento.updatedAt
          ).toLocaleString('es-CL')
        : '—'
    }`,
  ].join('\n');

const getPreviewUrl = (
  proveedor: Proveedor,
  documento: DocumentoProveedor
): string => {
  const url = buildPublicUrl(
    getDocumentoUrl(documento)
  );

  if (url) {
    return url;
  }

  return (
    'data:text/plain;charset=utf-8,' +
    encodeURIComponent(
      buildFallbackText(
        proveedor,
        documento
      )
    )
  );
};

const downloadDocumento = (
  proveedor: Proveedor,
  documento: DocumentoProveedor,
  onError?: (mensaje: string) => void
): void => {
  const url = buildPublicUrl(
    getDocumentoUrl(documento)
  );

  const fileName =
    getDocumentoNombre(documento);

  /*
   * Los archivos del servidor no tienen URL pública: se piden con la sesión.
   * Solo las vistas locales (data:/blob:) se descargan directo.
   */
  if (url && !documento.archivoDataUrl) {
    descargarArchivoDocumento(
      documento.id,
      fileName
    ).catch((caught: unknown) => {
      onError?.(
        caught instanceof Error
          ? caught.message
          : 'No se pudo descargar el documento.'
      );
    });

    return;
  }

  if (url) {
    const link =
      document.createElement('a');

    link.href = url;
    link.download = fileName;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';

    document.body.appendChild(link);
    link.click();
    link.remove();

    return;
  }

  const blob = new Blob(
    [
      buildFallbackText(
        proveedor,
        documento
      ),
    ],
    {
      type: 'text/plain;charset=utf-8',
    }
  );

  const objectUrl =
    URL.createObjectURL(blob);

  const link =
    document.createElement('a');

  link.href = objectUrl;
  link.download = fileName;

  document.body.appendChild(link);
  link.click();
  link.remove();

  URL.revokeObjectURL(objectUrl);
};

/*
|--------------------------------------------------------------------------
| Estilos de DataTable
|--------------------------------------------------------------------------
*/

const tableCustomStyles = {
  table: {
    style: {
      backgroundColor: colors.bgCard,
    },
  },

  headRow: {
    style: {
      minHeight: '56px',
      backgroundColor: colors.bgSoft,
      borderBottom:
        `1px solid ${colors.border}`,
    },
  },

  headCells: {
    style: {
      fontSize: '13px',
      fontWeight: 700,
      color: '#374151',
    },
  },

  rows: {
    style: {
      minHeight: '72px',
      borderBottom:
        '1px solid #f3f4f6',
      color: colors.textMain,
      fontSize: '14px',
    },

    stripedStyle: {
      backgroundColor: '#fcfcfd',
    },
  },

  pagination: {
    style: {
      borderTop:
        '1px solid #f3f4f6',
      fontSize: '13px',
      color: colors.textSoft,
      minHeight: '60px',
    },
  },
};

/*
|--------------------------------------------------------------------------
| Componente principal
|--------------------------------------------------------------------------
*/

const SeguimientoProveedores: React.FC =
  () => {
    const navigate = useNavigate();
    const { user } = useAuth();

    const [proveedores, setProveedores] =
      useState<Proveedor[]>([]);

    const [
      proveedorSeleccionado,
      setProveedorSeleccionado,
    ] = useState<Proveedor | null>(null);

    const [
      modalAbierto,
      setModalAbierto,
    ] = useState<ProveedorModalType>(null);

    const [
      filtroEstado,
      setFiltroEstado,
    ] = useState<string>('Todos');

    const [
      filtroEmpresa,
      setFiltroEmpresa,
    ] = useState<string>('Todas');

    const [loading, setLoading] =
      useState(false);

    const [error, setError] =
      useState<string | null>(null);

    const [success, setSuccess] =
      useState<string | null>(null);

    const role =
      user?.role as string | undefined;

    const userArea =
      user?.area ?? null;

    const userEmail =
      user?.email;

    const isProveedor =
      role === 'proveedor';

    const isAdminGlobal =
      isAdminGlobalRole(role);

    const abrirModal = (
      proveedor: Proveedor,
      tipo: Exclude<
        ProveedorModalType,
        null
      >
    ): void => {
      setError(null);
      setSuccess(null);
      setProveedorSeleccionado(proveedor);
      setModalAbierto(tipo);
    };

    const cerrarModal = (): void => {
      setModalAbierto(null);
      setProveedorSeleccionado(null);
    };

    const refreshProveedores =
      async (): Promise<Proveedor[]> => {
        setLoading(true);
        setError(null);

        try {
          const data =
            await InternoProveedoresService
              .getProveedores();

          const resultado =
            Array.isArray(data)
              ? data
              : [];

          setProveedores(resultado);

          return resultado;
        } catch (caught: unknown) {
          const err = caught as ApiError;
          console.error(
            'Error cargando proveedores:',
            err
          );

          const status =
            err?.response?.status;

          if (
            status === 404 ||
            status === 204
          ) {
            setProveedores([]);
            return [];
          }

          const message =
            err?.response?.data?.message ||
            err?.response?.data?.error ||
            'No se pudieron cargar los proveedores.';

          setProveedores([]);
          setError(message);

          return [];
        } finally {
          setLoading(false);
        }
      };

    const refreshSeleccionado =
      async (
        proveedorId: string,
        fallback?: Proveedor | null
      ): Promise<Proveedor | null> => {
        const data =
          await refreshProveedores();

        const actualizado =
          data.find(
            proveedor =>
              String(proveedor.id) ===
              String(proveedorId)
          ) ??
          fallback ??
          null;

        setProveedorSeleccionado(
          actualizado
        );

        return actualizado;
      };

    useEffect(() => {
      if (isInternalRole(role)) {
        void refreshProveedores();
      }
    }, [role]);

    const proveedoresVisibles =
      useMemo(() => {
        let visibles =
          proveedores.filter(proveedor =>
            canViewProveedor(
              proveedor,
              role,
              userEmail,
              userArea
            )
          );

        if (
          filtroEstado !== 'Todos'
        ) {
          visibles = visibles.filter(
            proveedor =>
              proveedor.estado ===
              filtroEstado
          );
        }

        if (
          filtroEmpresa !== 'Todas'
        ) {
          visibles = visibles.filter(
            proveedor =>
              proveedor.empresaNombre ===
              filtroEmpresa
          );
        }

        return visibles;
      }, [
        proveedores,
        role,
        userEmail,
        userArea,
        filtroEstado,
        filtroEmpresa,
      ]);

    const empresasDisponibles =
      useMemo(() => {
        const empresas = new Set(
          proveedores
            .map(
              proveedor =>
                proveedor.empresaNombre
            )
            .filter(Boolean)
        );

        return [
          'Todas',
          ...Array.from(
            empresas
          ).sort(),
        ];
      }, [proveedores]);

    const handleActualizarValidacion =
      async (
        proveedor: Proveedor,
        area: WorkflowArea,
        status: WorkflowStatus,
        comments?: string
      ): Promise<void> => {
        await InternoProveedoresService.actualizarValidacion(
          proveedor.id,
          area,
          status,
          comments
        );
        await refreshSeleccionado(
          proveedor.id,
          proveedor
        );
      };
    const handleReabrirValidacion =
      async (
        proveedor: Proveedor,
        area: WorkflowArea,
        comments?: string
      ): Promise<void> => {
        await InternoProveedoresService.reabrirValidacion(
          proveedor.id,
          area,
          comments
        );
        await refreshSeleccionado(
          proveedor.id,
          proveedor
        );
      };
    const regenerarFichaPdf =
      async (
        proveedor: Proveedor
      ): Promise<void> => {
        try {
          setError(null);
          setSuccess(null);

          const resultado =
            await InternoProveedoresService
              .generarFichaPdf(
                proveedor.id
              );

          const actualizado =
            resultado.proveedor ??
            resultado.data ??
            proveedor;

          await refreshSeleccionado(
            proveedor.id,
            actualizado
          );

          setSuccess(
            'Ficha PDF regenerada correctamente.'
          );
        } catch (caught: unknown) {
          const err = caught as ApiError;
          setError(
            err?.response?.data?.message ||
              err?.response?.data?.error ||
              'No se pudo regenerar la ficha.'
          );
        }
      };

    const recalcularEstado =
      async (
        proveedor: Proveedor
      ): Promise<void> => {
        try {
          setError(null);
          setSuccess(null);

          const resultado =
            await InternoProveedoresService
              .recalcularEstado(
                proveedor.id
              );

          const actualizado =
            resultado.proveedor ??
            resultado.data ??
            proveedor;

          await refreshSeleccionado(
            proveedor.id,
            actualizado
          );

          setSuccess(
            'Estado documental recalculado.'
          );
        } catch (caught: unknown) {
          const err = caught as ApiError;
          setError(
            err?.response?.data?.message ||
              err?.response?.data?.error ||
              'No se pudo recalcular el estado.'
          );
        }
      };

    const handleSubirDocumento =
      async (
        proveedor: Proveedor,
        documentoId: string,
        file?: File
      ): Promise<void> => {
        const validationError =
          validateDocumentoFile(file);

        if (
          validationError ||
          !file
        ) {
          setError(
            validationError ??
              'Debes seleccionar un archivo.'
          );
          return;
        }

        const documento =
          proveedor.documentos.find(
            item =>
              item.id === documentoId
          );

        if (!documento) {
          setError(
            'No se encontró el documento.'
          );
          return;
        }

        if (
          !canUploadDocumentoInterno(
            documento,
            role,
            userArea
          )
        ) {
          setError(
            'No tienes permisos para subir este documento.'
          );
          return;
        }

        try {
          setError(null);
          setSuccess(null);

          const resultado =
            await InternoProveedoresService
              .uploadDocumento(
                documentoId,
                file
              );

          await refreshSeleccionado(
            proveedor.id,
            resultado.proveedor ??
              proveedor
          );

          setSuccess(
            `Documento "${documento.nombre}" subido correctamente.`
          );
        } catch (caught: unknown) {
          const err = caught as ApiError;
          setError(
            err?.response?.data?.message ||
              err?.response?.data?.error ||
              'No se pudo subir el documento.'
          );
        }
      };

    const handleExportarCsv =
      async (): Promise<void> => {
        try {
          setError(null);
          await InternoProveedoresService
            .exportarProveedoresCsv(
              filtroEstado !== 'Todos'
                ? filtroEstado
                : undefined
            );
          setSuccess(
            'Listado exportado. Ábrelo con Excel.'
          );
        } catch (caught: unknown) {
          const err = caught as ApiError;
          setError(
            err?.response?.data?.message ||
              'No se pudo exportar el listado.'
          );
        }
      };

    const handleRevisarDocumento =
      async (
        proveedor: Proveedor,
        documentoId: string,
        estado: EstadoDocumento,
        comentario?: string,
        fechaVencimiento?: string
      ): Promise<void> => {
        const documento =
          proveedor.documentos.find(
            item =>
              item.id === documentoId
          );

        if (!documento) {
          setError(
            'No se encontró el documento.'
          );
          return;
        }

        if (
          !canManageDocumentoInterno(
            documento,
            role,
            userArea
          )
        ) {
          setError(
            'No tienes permisos para revisar este documento.'
          );
          return;
        }

        try {
          setError(null);
          setSuccess(null);

          const actualizado =
            await InternoProveedoresService
              .revisarDocumento(
                documentoId,
                estado,
                comentario,
                fechaVencimiento
              );

          await refreshSeleccionado(
            proveedor.id,
            actualizado ??
              proveedor
          );

          setSuccess(
            `Documento "${documento.nombre}" marcado como ${estado}.`
          );
        } catch (caught: unknown) {
          const err = caught as ApiError;
          setError(
            err?.response?.data?.message ||
              err?.response?.data?.error ||
              'No se pudo revisar el documento.'
          );
        }
      };

    const columns: TableColumn<Proveedor>[] =
      [
        {
          name: 'Razón Social',
          selector: row =>
            row.razonSocial,
          sortable: true,
          grow: 1.5,
        },
        {
          name: 'RUT',
          selector: row => row.rut,
          sortable: true,
        },
        {
          name: 'Empresa',
          selector: row =>
            row.empresaNombre,
          sortable: true,
        },
        ...(isAdminGlobal
          ? [
              {
                name: 'Creado por',
                selector: (
                  row: Proveedor
                ) =>
                  row.createdByName ||
                  row.createdByEmail ||
                  '—',
                sortable: true,
              } as TableColumn<Proveedor>,
            ]
          : []),
        {
          name: 'Estado',
          cell: row => (
            <span
              style={getEstadoBadgeStyle(
                row.estado
              )}
            >
              {row.estado}
            </span>
          ),
          sortable: true,
        },
        {
          name: 'Acciones',
          cell: row => {
            const puedeRevisar =
              canReviewProveedorDocuments(
                row,
                role,
                userArea
              );

            const puedeUsarApis =
              canUseProviderApis(
                role,
                userArea
              );

            return (
              <div
                style={
                  styles.tableActions
                }
              >
                <button
                  type="button"
                  style={{
                    ...styles.tableActionButton,
                    ...styles.detailActionButton,
                  }}
                  onClick={() =>
                    abrirModal(
                      row,
                      'detalle'
                    )
                  }
                >
                  Ver detalle
                </button>

                <button
                  type="button"
                  style={{
                    ...styles.tableActionButton,
                    ...styles.validationsActionButton,
                  }}
                  onClick={() =>
                    abrirModal(
                      row,
                      'validaciones'
                    )
                  }
                >
                  Validaciones
                </button>
                {puedeRevisar && (
                  <button
                    type="button"
                    style={{
                      ...styles.tableActionButton,
                      ...styles.documentsActionButton,
                    }}
                    onClick={() =>
                      abrirModal(
                        row,
                        'documentos'
                      )
                    }
                  >
                    Revisar documentos
                  </button>
                )}

                {puedeUsarApis && (
                  <button
                    type="button"
                    style={{
                      ...styles.tableActionButton,
                      ...styles.apiActionButton,
                    }}
                    onClick={() =>
                      abrirModal(
                        row,
                        'apis'
                      )
                    }
                  >
                    Usar APIs
                  </button>
                )}
                {(isAdminGlobal ||
                  userArea === 'legal_crediticia') && (
                  <button
                    type="button"
                    style={{
                      ...styles.tableActionButton,
                      ...styles.apiActionButton,
                    }}
                    onClick={() =>
                      abrirModal(row, 'evaluacion')
                    }
                  >
                    Evaluación crediticia
                  </button>
                )}

                {(isAdminGlobal ||
                  userArea === 'datos_maestros') && (
                  <button
                    type="button"
                    style={{
                      ...styles.tableActionButton,
                      ...styles.validationsActionButton,
                    }}
                    onClick={() =>
                      abrirModal(row, 'sap')
                    }
                  >
                    SAP (simulado)
                  </button>
                )}

                <button
                  type="button"
                  style={{
                    ...styles.tableActionButton,
                    ...styles.historyActionButton,
                  }}
                  onClick={() =>
                    abrirModal(row, 'plazos')
                  }
                >
                  Plazos
                </button>

                <button
  type="button"
  style={{
    ...styles.tableActionButton,
    ...styles.historyActionButton,
  }}
  onClick={() =>
    abrirModal(row, 'historial')
  }
>
  Ver historial
</button>
              </div>
            );
          },
          minWidth: '720px',
          grow: 2.6,
          ignoreRowClick: true,
          allowOverflow: true,
        },
      ];

    if (isProveedor) {
      return (
        <Navigate
          to="/proveedores/mis-solicitudes"
          replace
        />
      );
    }

    if (!isInternalRole(role)) {
      return (
        <Navigate
          to="/login"
          replace
        />
      );
    }

    return (
      <div style={ui.page}>
        <PageHero
          title="Listado de proveedores"
          subtitle="Consulta proveedores, revisa documentos y ejecuta evaluaciones externas según tu área."
        />

        {error && (
          <div
            style={{
              ...ui.errorBox,
              marginBottom: spacing.md,
            }}
          >
            {error}
          </div>
        )}

        {success && (
          <div
            style={{
              ...ui.successBox,
              marginBottom: spacing.md,
            }}
          >
            {success}
          </div>
        )}

        <div style={styles.toolbarCard}>
          <div style={styles.filterBlock}>
            <p style={styles.filterLabel}>
              Filtrar por estado
            </p>

            <div style={styles.filterGroup}>
              {ESTADOS_PROVEEDOR.map(
                estado => (
                  <button
                    key={estado}
                    type="button"
                    style={
                      filtroEstado === estado
                        ? styles.filterButtonActive
                        : styles.filterButton
                    }
                    onClick={() =>
                      setFiltroEstado(
                        estado
                      )
                    }
                  >
                    {estado}
                  </button>
                )
              )}
            </div>
          </div>

          <div
            style={
              styles.createProveedorMiddle
            }
          >
            {canCreateProveedor(
              role,
              userArea
            ) && (
              <button
                type="button"
                style={ui.primaryButton}
                onClick={() =>
                  navigate(
                    '/proveedores/crear'
                  )
                }
              >
                + Crear proveedor
              </button>
            )}

            <button
              type="button"
              style={styles.filterButton}
              onClick={handleExportarCsv}
            >
              Exportar a Excel (CSV)
            </button>
          </div>

          <div style={styles.selectWrap}>
            <label
              style={styles.selectLabel}
            >
              Filtrar por empresa
            </label>

            <select
              style={styles.select}
              value={filtroEmpresa}
              onChange={event =>
                setFiltroEmpresa(
                  event.target.value
                )
              }
            >
              {empresasDisponibles.map(
                empresa => (
                  <option
                    key={empresa}
                    value={empresa}
                  >
                    {empresa}
                  </option>
                )
              )}
            </select>
          </div>
        </div>

        <div style={styles.tableCard}>
          <div style={styles.sectionHeader}>
            <p
              style={
                styles.sectionSubtitle
              }
            >
              Cada acción abre una vista
              específica para evitar mostrar
              información innecesaria.
            </p>
          </div>

          <DataTable
            columns={columns}
            data={proveedoresVisibles}
            pagination
            highlightOnHover
            striped
            progressPending={loading}
            customStyles={
              tableCustomStyles
            }
            noDataComponent={
              <div
                style={
                  styles.noDataState
                }
              >
                <div
                  style={
                    styles.noDataIcon
                  }
                >
                  📭
                </div>

                <p
                  style={
                    styles.noDataTitle
                  }
                >
                  No hay proveedores
                </p>

                <p
                  style={
                    styles.noDataText
                  }
                >
                  Ajusta los filtros o
                  crea un proveedor.
                </p>
              </div>
            }
          />
        </div>

{proveedorSeleccionado &&
  modalAbierto === 'detalle' && (
    <ModalDetalleProveedor
      proveedor={proveedorSeleccionado}
      onCerrar={cerrarModal}
    />
  )}

{proveedorSeleccionado &&
  modalAbierto === 'validaciones' && (
    <ModalShell
      eyebrow="Workflow de validaciones"
      title={proveedorSeleccionado.razonSocial}
      subtitle={`${proveedorSeleccionado.rut} · Estado general: ${proveedorSeleccionado.estado}`}
      onCerrar={cerrarModal}
      maxWidth="760px"
    >
      <ValidacionesPanel
        proveedor={proveedorSeleccionado}
        role={role}
        userArea={userArea}
        userId={user?.id}
        onActualizar={(area, status, comments) =>
          handleActualizarValidacion(
            proveedorSeleccionado,
            area,
            status,
            comments
          )
        }
        onReabrir={(area, comments) =>
          handleReabrirValidacion(
            proveedorSeleccionado,
            area,
            comments
          )
        }
      />
    </ModalShell>
  )}
{proveedorSeleccionado &&
  modalAbierto === 'documentos' && (
    <ModalDocumentosProveedor
      proveedor={proveedorSeleccionado}
      role={role}
      userArea={userArea}
      onCerrar={cerrarModal}
      onSubirDocumento={handleSubirDocumento}
      onRevisarDocumento={handleRevisarDocumento}
      onRegenerarFichaPdf={regenerarFichaPdf}
      onRecalcularEstado={recalcularEstado}
    />
  )}

{proveedorSeleccionado &&
  modalAbierto === 'apis' && (
    <ModalApisProveedor
      proveedor={proveedorSeleccionado}
      role={role}
      userArea={userArea}
      onCerrar={cerrarModal}
    />
  )}

{proveedorSeleccionado &&
  modalAbierto === 'historial' && (
    <ModalHistorialProveedor
      proveedor={proveedorSeleccionado}
      onCerrar={cerrarModal}
    />
  )}

{proveedorSeleccionado &&
  modalAbierto === 'evaluacion' && (
    <ModalShell
      eyebrow="Legal Crediticia · Evaluación crediticia"
      title={proveedorSeleccionado.razonSocial}
      subtitle={proveedorSeleccionado.rut}
      onCerrar={cerrarModal}
      maxWidth="900px"
    >
      <EvaluacionCrediticiaPanel
        proveedorId={proveedorSeleccionado.id}
      />
    </ModalShell>
  )}

{proveedorSeleccionado &&
  modalAbierto === 'sap' && (
    <ModalShell
      eyebrow="Datos Maestros · Integración SAP (simulada)"
      title={proveedorSeleccionado.razonSocial}
      subtitle={`${proveedorSeleccionado.rut} · Estado general: ${proveedorSeleccionado.estado}`}
      onCerrar={cerrarModal}
      maxWidth="900px"
    >
      <SapMockPanel
        proveedorId={proveedorSeleccionado.id}
      />
    </ModalShell>
  )}

{proveedorSeleccionado &&
  modalAbierto === 'plazos' && (
    <ModalShell
      eyebrow="Plazos por etapa"
      title={proveedorSeleccionado.razonSocial}
      subtitle={proveedorSeleccionado.rut}
      onCerrar={cerrarModal}
      maxWidth="860px"
    >
      <PlazosPanel
        proveedorId={proveedorSeleccionado.id}
      />
    </ModalShell>
  )}
      </div>
    );
  };

/*
|--------------------------------------------------------------------------
| Modal detalle
|--------------------------------------------------------------------------
*/

interface ModalDetalleProveedorProps {
  proveedor: Proveedor;
  onCerrar: () => void;
}

const ModalDetalleProveedor: React.FC<
  ModalDetalleProveedorProps
> = ({
  proveedor,
  onCerrar,
}) =>(
  <ModalShell
    eyebrow="Detalle del proveedor"
    title={proveedor.razonSocial}
    subtitle={proveedor.empresaNombre}
    onCerrar={onCerrar}
  >
    <div style={styles.detailGrid}>
      <InfoItem
        label="RUT"
        value={proveedor.rut}
      />

      <InfoItem
        label="Tipo"
        value={proveedor.tipo}
      />

      <InfoItem
        label="Giro"
        value={proveedor.giro || '—'}
      />

      <div style={styles.infoCard}>
        <p style={styles.infoLabel}>
          Estado
        </p>

        <div style={{ marginTop: '8px' }}>
          <span
            style={getEstadoBadgeStyle(
              proveedor.estado
            )}
          >
            {proveedor.estado}
          </span>
        </div>
      </div>

      <InfoItem
        label="Creado por"
        value={
          proveedor.createdByName ||
          proveedor.createdByEmail ||
          '—'
        }
      />

      <InfoItem
        label="Cuenta proveedor"
        value={
          proveedor.proveedorNombre ||
          proveedor.proveedorEmail ||
          '—'
        }
      />
    </div>

    {proveedor.detalle && (
      <>
        <h3 style={styles.sectionTitle}>
          Datos de incorporación
        </h3>

        <div style={styles.detailGrid}>
          <InfoItem
            label="Dirección"
            value={
              proveedor.detalle.direccion ||
              '—'
            }
          />

          <InfoItem
            label="Comuna"
            value={
              proveedor.detalle.comuna ||
              '—'
            }
          />

          <InfoItem
            label="Región"
            value={
              proveedor.detalle.region ||
              '—'
            }
          />

          <InfoItem
            label="País"
            value={
              proveedor.detalle.pais ||
              '—'
            }
          />

          <InfoItem
            label="Moneda"
            value={
              proveedor.detalle.moneda ||
              '—'
            }
          />

          <InfoItem
            label="Plazo de pago"
            value={
              proveedor.detalle.plazoPago ||
              '—'
            }
          />

          <InfoItem
            label="Forma de pago"
            value={
              proveedor.detalle.formaPago ||
              '—'
            }
          />

          <InfoItem
            label="Banco"
            value={
              proveedor.detalle.bancoPago ||
              '—'
            }
          />

          <InfoItem
            label="Cuenta corriente"
            value={
              proveedor.detalle
                .cuentaCorriente ||
              '—'
            }
          />

          <InfoItem
            label="Tipo proveedor"
            value={
              proveedor.detalle
                .tipoProveedorDetalle ||
              '—'
            }
          />
        </div>
      </>
    )}

    {proveedor.sociedadesHabilitadas
      .length > 0 && (
      <>
        <h3 style={styles.sectionTitle}>
          Sociedades habilitadas
        </h3>

        <div style={styles.chipsWrap}>
          {proveedor.sociedadesHabilitadas.map(
            sociedad => (
              <span
                key={sociedad}
                style={styles.sociedadChip}
              >
                {sociedad}
              </span>
            )
          )}
        </div>
      </>
    )}
  </ModalShell>
);

/*
|--------------------------------------------------------------------------
| Modal documentos
|--------------------------------------------------------------------------
*/
interface ModalHistorialProveedorProps {
  proveedor: Proveedor;
  onCerrar: () => void;
}

const ModalHistorialProveedor: React.FC<
  ModalHistorialProveedorProps
> = ({
  proveedor,
  onCerrar,
}) => {
  const historialOrdenado = useMemo(() => {
    return [...proveedor.historial].sort((a, b) => {
      const fechaA = new Date(
        a.fecha || a.createdAt || 0
      ).getTime();

      const fechaB = new Date(
        b.fecha || b.createdAt || 0
      ).getTime();

      return fechaB - fechaA;
    });
  }, [proveedor.historial]);

  return (
    <ModalShell
      eyebrow="Trazabilidad del proceso"
      title={`Historial de ${proveedor.razonSocial}`}
      subtitle={`${historialOrdenado.length} ${
        historialOrdenado.length === 1
          ? 'evento registrado'
          : 'eventos registrados'
      }`}
      onCerrar={onCerrar}
      maxWidth="860px"
    >
      <div style={styles.historySummary}>
        <InfoItem
          label="Proveedor"
          value={proveedor.razonSocial || '—'}
        />

        <InfoItem
          label="RUT"
          value={proveedor.rut || '—'}
        />

        <InfoItem
          label="Empresa"
          value={proveedor.empresaNombre || '—'}
        />

        <div style={styles.infoCard}>
          <p style={styles.infoLabel}>
            Estado actual
          </p>

          <div style={{ marginTop: '8px' }}>
            <span
              style={getEstadoBadgeStyle(proveedor.estado)}
            >
              {proveedor.estado}
            </span>
          </div>
        </div>
      </div>

      {historialOrdenado.length === 0 ? (
        <div style={styles.historyEmptyState}>
          <div style={styles.historyEmptyIcon}>
            🕘
          </div>

          <h3 style={styles.historyEmptyTitle}>
            Sin historial disponible
          </h3>

          <p style={styles.historyEmptyText}>
            Todavía no existen acciones registradas para este
            proveedor.
          </p>
        </div>
      ) : (
        <div style={styles.historyTimeline}>
          {historialOrdenado.map((historial, index) => {
            const fecha =
              historial.fecha ||
              historial.createdAt;

            const fechaValida =
              fecha &&
              !Number.isNaN(
                new Date(fecha).getTime()
              );

            const fechaFormateada = fechaValida
              ? new Date(fecha).toLocaleString(
                  'es-CL',
                  {
                    timeZone: 'America/Santiago',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                  }
                )
              : 'Fecha no disponible';

            return (
              <div
                key={
                  historial.id ||
                  `${fecha || 'sin-fecha'}-${index}`
                }
                style={styles.historyTimelineItem}
              >
                <div
                  style={
                    styles.historyTimelineMarkerColumn
                  }
                >
                  <div
                    style={styles.historyTimelineMarker}
                  />

                  {index <
                    historialOrdenado.length - 1 && (
                    <div
                      style={styles.historyTimelineLine}
                    />
                  )}
                </div>

                <div
                  style={styles.historyTimelineContent}
                >
                  <div
                    style={styles.historyTimelineHeader}
                  >
                    <p
                      style={styles.historyTimelineAction}
                    >
                      {historial.accion ||
                        'Acción sin detalle'}
                    </p>

                    <span
                      style={styles.historyTimelineDate}
                    >
                      {fechaFormateada}
                    </span>
                  </div>

                  <p
                    style={styles.historyTimelineActor}
                  >
                    Realizado por:{' '}
                    <strong>
                      {historial.realizadoPor ||
                        'Sistema'}
                    </strong>
                  </p>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </ModalShell>
  );
};
interface ModalDocumentosProveedorProps {
  proveedor: Proveedor;
  role?: string;
  userArea?: string | null;
  onCerrar: () => void;

  onSubirDocumento: (
    proveedor: Proveedor,
    documentoId: string,
    file?: File
  ) => void | Promise<void>;

  onRevisarDocumento: (
    proveedor: Proveedor,
    documentoId: string,
    estado: EstadoDocumento,
    comentario?: string,
    fechaVencimiento?: string
  ) => void | Promise<void>;

  onRegenerarFichaPdf: (
    proveedor: Proveedor
  ) => void | Promise<void>;

  onRecalcularEstado: (
    proveedor: Proveedor
  ) => void | Promise<void>;
}

const ModalDocumentosProveedor: React.FC<
  ModalDocumentosProveedorProps
> = ({
  proveedor,
  role,
  userArea,
  onCerrar,
  onSubirDocumento,
  onRevisarDocumento,
  onRegenerarFichaPdf,
  onRecalcularEstado,
}) => {
  const [
    documentoComments,
    setDocumentoComments,
  ] = useState<
    Record<string, string>
  >({});

  const [
    documentoVencimientos,
    setDocumentoVencimientos,
  ] = useState<
    Record<string, string>
  >({});

  const [previewDoc, setPreviewDoc] =
    useState<DocumentoProveedor | null>(
      null
    );

  const [descargaError, setDescargaError] =
    useState<string | null>(null);

  // Los archivos del servidor se piden con la sesión; no existe una URL pública.
  const previewRemoto = Boolean(
    previewDoc &&
      !previewDoc.archivoDataUrl &&
      previewDoc.archivoUrl
  );

  const {
    url: previewBlobUrl,
    error: previewError,
    cargando: previewCargando,
  } = useArchivoDocumento(
    previewRemoto && previewDoc
      ? previewDoc.id
      : null
  );

  const documentosGestionables =
    proveedor.documentos.filter(
      documento =>
        canManageDocumentoInterno(
          documento,
          role,
          userArea
        )
    );

  const documentosTotales =
    proveedor.documentos.length;

  const documentosPendientes =
    proveedor.documentos.filter(
      documento =>
        documento.estado === 'Pendiente'
    ).length;

  const documentosSubidos =
    proveedor.documentos.filter(
      documento =>
        documento.estado === 'Subido'
    ).length;

  const documentosObservados =
    proveedor.documentos.filter(
      documento =>
        documento.estado === 'Observado'
    ).length;

  const documentosAprobados =
    proveedor.documentos.filter(
      documento =>
        documento.estado === 'Aprobado'
    ).length;

  return (
    <>
      <ModalShell
        eyebrow="Revisión documental"
        title={proveedor.razonSocial}
        subtitle={`${documentosGestionables.length} documentos disponibles para tu rol o área`}
        onCerrar={onCerrar}
      >
        <div
          style={
            styles.processSummaryGrid
          }
        >
          <InfoItem
            label="Total"
            value={String(
              documentosTotales
            )}
          />

          <InfoItem
            label="Pendientes"
            value={String(
              documentosPendientes
            )}
          />

          <InfoItem
            label="Subidos"
            value={String(
              documentosSubidos
            )}
          />

          <InfoItem
            label="Observados"
            value={String(
              documentosObservados
            )}
          />

          <InfoItem
            label="Aprobados"
            value={String(
              documentosAprobados
            )}
          />
        </div>
        {canUpdateEstadoGeneral(role) && (
  <div style={styles.actionPanel}>
    <div style={styles.actionPanelHeader}>
      <div>
        <p style={styles.panelLabel}>
          Acciones del proceso documental
        </p>

        <p style={styles.actionPanelDescription}>
          El estado general se calcula solo, a partir de las validaciones
          por área y de los documentos observados. Puedes regenerar la ficha
          de incorporación o volver a calcular el estado.
        </p>
      </div>

      <span style={getEstadoBadgeStyle(proveedor.estado)}>
        {proveedor.estado}
      </span>
    </div>

    <div style={styles.actionRow}>
      <button
        type="button"
        style={styles.infoButton}
        onClick={() =>
          onRegenerarFichaPdf(proveedor)
        }
      >
        Regenerar ficha PDF
      </button>

      <button
        type="button"
        style={styles.infoButton}
        onClick={() =>
          onRecalcularEstado(proveedor)
        }
      >
        Recalcular estado
      </button>
    </div>
  </div>
)}

        {descargaError && (
          <div
            role="alert"
            style={{
              background:
                colors.dangerSoft,
              color: '#991b1b',
              borderRadius: radius.lg,
              padding: '10px 14px',
              marginBottom: '12px',
              fontSize: '13px',
            }}
          >
            {descargaError}
          </div>
        )}

        {documentosGestionables.length ===
        0 ? (
          <div
            style={styles.noDataState}
          >
            <p
              style={styles.noDataTitle}
            >
              No tienes documentos asignados
            </p>

            <p
              style={styles.noDataText}
            >
              No existen documentos que
              correspondan a tu rol o área.
            </p>
          </div>
        ) : (
          <div style={styles.docsList}>
            {documentosGestionables.map(
              documento => {
                const hasFile = Boolean(
                  getDocumentoUrl(
                    documento
                  )
                );

                const plantillaUrl =
                  getPlantillaUrl(
                    documento
                  );

                const canUpload =
                  canUploadDocumentoInterno(
                    documento,
                    role,
                    userArea
                  );

                const comentario =
                  documentoComments[
                    documento.id
                  ] ??
                  documento.comentario ??
                  '';

                return (
                  <div
                    key={documento.id}
                    style={styles.docCard}
                  >
                    <div
                      style={
                        styles.docHeader
                      }
                    >
                      <div>
                        <p
                          style={
                            styles.docTitle
                          }
                        >
                          {documento.nombre}
                        </p>

                        <p
                          style={
                            styles.docFileName
                          }
                        >
                          {hasFile
                            ? `Archivo actual: ${getDocumentoNombre(
                                documento
                              )}`
                            : isFichaAutomatica(
                                  documento
                                )
                              ? 'Documento generado automáticamente'
                              : 'Sin archivo cargado'}
                        </p>

                        <p
                          style={
                            styles.docMeta
                          }
                        >
                          Responsable:{' '}
                          {getResponsableLabel(
                            documento
                          )}
                        </p>

                        <p
                          style={
                            styles.docMeta
                          }
                        >
                          Área:{' '}
                          {getAreaResponsableLabel(
                            documento
                          )}
                        </p>

                        {plantillaUrl && (
                          <a
                            href={plantillaUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            style={styles.templateButton}
                          >
                            Descargar plantilla
                          </a>
                        )}
                      </div>

                      <span
                        style={getDocumentoBadgeStyle(
                          documento.estado
                        )}
                      >
                        {documento.estado}
                      </span>
                    </div>

                    {documento.comentario && (
                      <p
                        style={
                          styles.docComment
                        }
                      >
                        <strong>
                          Comentario actual:
                        </strong>{' '}
                        {
                          documento.comentario
                        }
                      </p>
                    )}

                    {hasFile && (
                      <div
                        style={
                          styles.docActions
                        }
                      >
                        <button
                          type="button"
                          style={
                            styles.infoButtonSmall
                          }
                          onClick={() =>
                            setPreviewDoc(
                              documento
                            )
                          }
                        >
                          Vista previa
                        </button>

                        <button
                          type="button"
                          style={
                            styles.successButtonSmall
                          }
                          onClick={() =>
                            downloadDocumento(
                              proveedor,
                              documento,
                              setDescargaError
                            )
                          }
                        >
                          Descargar
                        </button>
                      </div>
                    )}

                    <textarea
                      value={comentario}
                      onChange={event =>
                        setDocumentoComments(
                          previous => ({
                            ...previous,
                            [documento.id]:
                              event.target
                                .value,
                          })
                        )
                      }
                      placeholder="Escribe un comentario"
                      style={
                        styles.commentTextarea
                      }
                    />

                    {canUpload && (
                      <div
                        style={{
                          marginTop: '12px',
                        }}
                      >
                        <input
                          type="file"
                          accept=".pdf,.png,.jpg,.jpeg"
                          onChange={event =>
                            onSubirDocumento(
                              proveedor,
                              documento.id,
                              event.target
                                .files?.[0]
                            )
                          }
                        />
                      </div>
                    )}

                    {isFichaAutomatica(
                      documento
                    ) && (
                      <p
                        style={
                          styles.docMeta
                        }
                      >
                        La ficha se genera
                        automáticamente y no
                        permite carga manual.
                      </p>
                    )}

                    <div
                      style={
                        styles.docActions
                      }
                    >
                      <button
                        type="button"
                        style={
                          styles.infoButtonSmall
                        }
                        onClick={() =>
                          onRevisarDocumento(
                            proveedor,
                            documento.id,
                            'Pendiente',
                            comentario
                          )
                        }
                      >
                        Pendiente
                      </button>

                      <button
                        type="button"
                        style={
                          styles.warningButtonSmall
                        }
                        onClick={() =>
                          onRevisarDocumento(
                            proveedor,
                            documento.id,
                            'Observado',
                            comentario
                          )
                        }
                      >
                        Observar
                      </button>

                      <label
                        style={styles.docMeta}
                      >
                        Vence:{' '}
                        <input
                          type="date"
                          value={
                            documentoVencimientos[
                              documento.id
                            ] ??
                            documento.fechaVencimiento ??
                            ''
                          }
                          onChange={event =>
                            setDocumentoVencimientos(
                              previous => ({
                                ...previous,
                                [documento.id]:
                                  event.target
                                    .value,
                              })
                            )
                          }
                        />
                      </label>

                      <button
                        type="button"
                        style={
                          styles.successButtonSmall
                        }
                        onClick={() =>
                          onRevisarDocumento(
                            proveedor,
                            documento.id,
                            'Aprobado',
                            comentario,
                            documentoVencimientos[
                              documento.id
                            ] ??
                              documento.fechaVencimiento
                          )
                        }
                      >
                        Aprobar
                      </button>
                    </div>
                  </div>
                );
              }
            )}
          </div>
        )}
      </ModalShell>

      {previewDoc && (
        <ModalShell
          eyebrow="Vista previa"
          title={previewDoc.nombre}
          subtitle={getDocumentoNombre(
            previewDoc
          )}
          onCerrar={() =>
            setPreviewDoc(null)
          }
          maxWidth="960px"
          zIndex={1100}
        >
          {previewRemoto &&
            previewCargando && (
              <p>Cargando documento…</p>
            )}
          {previewRemoto &&
            previewError && (
              <p>{previewError}</p>
            )}
          {previewRemoto &&
          !previewBlobUrl ? null : isImageDocumento(
            previewDoc
          ) ? (
            <img
              src={
                (previewRemoto
                  ? previewBlobUrl
                  : getPreviewUrl(
                      proveedor,
                      previewDoc
                    )) || undefined
              }
              alt={previewDoc.nombre}
              style={{
                maxWidth: '100%',
                borderRadius: radius.lg,
              }}
            />
          ) : (
            <iframe
              title={previewDoc.nombre}
              src={
                (previewRemoto
                  ? previewBlobUrl
                  : getPreviewUrl(
                      proveedor,
                      previewDoc
                    )) || undefined
              }
              style={{
                width: '100%',
                height: '70vh',
                border:
                  `1px solid ${colors.border}`,
                borderRadius: radius.lg,
              }}
            />
          )}
        </ModalShell>
      )}
    </>
  );
};

/*
|--------------------------------------------------------------------------
| Modal APIs
|--------------------------------------------------------------------------
*/

interface ModalApisProveedorProps {
  proveedor: Proveedor;
  role?: string;
  userArea?: string | null;
  onCerrar: () => void;
}
const getAmlResponseStatus = (
  response: unknown
): string => {
  if (
    !response ||
    typeof response !== 'object'
  ) {
    return '—';
  }

  const data =
    response as Record<string, unknown>;

  const status =
    data.status;

  if (
    typeof status === 'string' ||
    typeof status === 'number'
  ) {
    return String(status);
  }

  return '—';
};
const ModalApisProveedor: React.FC<
  ModalApisProveedorProps
> = ({
  proveedor,
  role,
  userArea,
  onCerrar,
}) => {
  const [amlLoading, setAmlLoading] =
    useState<AmlAction | null>(null);

  const [amlError, setAmlError] =
    useState<string | null>(null);

  const [
    amlPdfLoading,
    setAmlPdfLoading,
  ] = useState(false);

  const [
    ultimoAmlCheck,
    setUltimoAmlCheck,
  ] = useState<ProveedorAmlCheck | null>(
    null
  );

  const [
    dicomLoading,
    setDicomLoading,
  ] = useState<DicomAction | null>(null);

  const [dicomError, setDicomError] =
    useState<string | null>(null);

  const [
    ultimoDicomCheck,
    setUltimoDicomCheck,
  ] =
    useState<ProveedorDicomCheck | null>(
      null
    );

  const puedeUsarApis =
    canUseProviderApis(role, userArea);

  const handleAmlAction = async (
    action: AmlAction
  ): Promise<void> => {
    if (!puedeUsarApis) {
      setAmlError(
        'No tienes permisos para ejecutar Gesintel.'
      );
      return;
    }

    setAmlError(null);
    setAmlLoading(action);

    try {
      let resultado:
        | ProveedorAmlCheck
        | null = null;

      if (action === 'risk') {
        resultado =
          await InternoProveedoresService
            .consultarAmlRisk(
              proveedor.id
            );
      }

      if (action === 'result') {
        resultado =
          await InternoProveedoresService
            .consultarAmlResult(
              proveedor.id
            );
      }

      if (action === 'flags') {
        resultado =
          await InternoProveedoresService
            .consultarAmlFlags(
              proveedor.id
            );
      }

      if (action === 'report') {
        resultado =
          await InternoProveedoresService
            .generarAmlReport(
              proveedor.id
            );
      }

      if (!resultado) {
        setAmlError(
          'No se recibió una respuesta AML válida.'
        );
        return;
      }

      setUltimoAmlCheck(resultado);
    } catch (caught: unknown) {
      const err = caught as ApiError;
      setAmlError(
        err?.response?.data?.message ||
          err?.response?.data?.error ||
          'No se pudo ejecutar Gesintel.'
      );
    } finally {
      setAmlLoading(null);
    }
  };
const getAmlPdfErrorMessage = async (
  caught: unknown
): Promise<string> => {
  /*
   * La descarga del PDF usa responseType blob, por lo que el
   * cuerpo del error también llega como Blob.
   */
  const error =
    caught as AxiosError<Blob | ApiErrorBody>;

  const responseData =
    error?.response?.data;

  if (responseData instanceof Blob) {
    try {
      const responseText =
        await responseData.text();

      const parsed =
        JSON.parse(responseText);

      return (
        parsed?.message ||
        'No se pudo abrir el reporte Gesintel.'
      );
    } catch {
      return (
        'No se pudo abrir el reporte Gesintel.'
      );
    }
  }

  return (
    responseData?.message ||
    responseData?.error ||
    error?.message ||
    'No se pudo abrir el reporte Gesintel.'
  );
};

const abrirAmlReportPdf =
  async (): Promise<void> => {
    if (!puedeUsarApis) {
      setAmlError(
        'No tienes permisos para abrir el reporte Gesintel.'
      );

      return;
    }

    /*
     * La pestaña debe abrirse directamente durante el clic.
     * No se pasa noopener aquí porque necesitamos conservar
     * la referencia para cambiar posteriormente la ubicación.
     */
    const reportWindow =
      window.open(
        'about:blank',
        '_blank'
      );

    if (!reportWindow) {
      setAmlError(
        'El navegador bloqueó la apertura del reporte. Habilita las ventanas emergentes para este sitio.'
      );

      return;
    }

    /*
     * Se elimina manualmente la referencia a la ventana original.
     */
    reportWindow.opener = null;

    reportWindow.document.title =
      'Cargando reporte Gesintel';

    reportWindow.document.body.innerHTML = `
      <div
        style="
          min-height: 100vh;
          display: flex;
          align-items: center;
          justify-content: center;
          margin: 0;
          background: #f8fafc;
          font-family: Arial, sans-serif;
          color: #334155;
        "
      >
        <div style="text-align: center;">
          <p
            style="
              margin: 0;
              font-size: 16px;
              font-weight: 700;
            "
          >
            Cargando reporte Gesintel...
          </p>

          <p
            style="
              margin: 8px 0 0;
              font-size: 13px;
              color: #64748b;
            "
          >
            Espera mientras se obtiene el documento.
          </p>
        </div>
      </div>
    `;

    setAmlError(null);
    setAmlPdfLoading(true);

    try {
      const blob =
        await InternoProveedoresService
          .descargarAmlReportPdf(
            proveedor.id
          );

      if (
        !(blob instanceof Blob) ||
        blob.size === 0
      ) {
        throw new Error(
          'El backend devolvió un archivo vacío.'
        );
      }

      const pdfBlob = new Blob(
        [blob],
        {
          type: 'application/pdf',
        }
      );

      const objectUrl =
        URL.createObjectURL(
          pdfBlob
        );

      /*
       * Carga el PDF en la pestaña que se abrió
       * durante el clic del usuario.
       */
      reportWindow.location.replace(
        objectUrl
      );

      /*
       * No liberar inmediatamente la URL.
       * El navegador necesita tiempo para cargar
       * el documento completo.
       */
      window.setTimeout(() => {
        URL.revokeObjectURL(
          objectUrl
        );
      }, 5 * 60 * 1000);
    } catch (caught: unknown) {
      const error = caught as ApiError;
      reportWindow.close();

      const message =
        await getAmlPdfErrorMessage(
          error
        );

      setAmlError(message);
    } finally {
      setAmlPdfLoading(false);
    }
  };
  const handleDicomAction = async (
    action: DicomAction
  ): Promise<void> => {
    if (!puedeUsarApis) {
      setDicomError(
        'No tienes permisos para ejecutar DICOM.'
      );
      return;
    }

    setDicomError(null);
    setDicomLoading(action);

    try {
      let resultado:
        | ProveedorDicomCheck
        | null = null;

      if (action === 'empresa') {
        resultado =
          await InternoProveedoresService
            .consultarDicomEmpresa(
              proveedor.rut
            );
      }

      if (action === 'persona') {
        resultado =
          await InternoProveedoresService
            .consultarDicomPersona(
              proveedor.rut
            );
      }

      if (action === 'historial') {
        resultado =
          await InternoProveedoresService
            .consultarDicomHistorial(
              proveedor.rut
            );
      }

      if (action === 'informacion') {
        resultado =
          await InternoProveedoresService
            .consultarDicomInformacion(
              proveedor.rut
            );
      }

      if (!resultado) {
        setDicomError(
          'No se recibió una respuesta DICOM válida.'
        );
        return;
      }

      setUltimoDicomCheck(resultado);
    } catch (caught: unknown) {
      const err = caught as ApiError;
      setDicomError(
        err?.response?.data?.message ||
          err?.response?.data?.error ||
          'No se pudo ejecutar DICOM.'
      );
    } finally {
      setDicomLoading(null);
    }
  };

  if (!puedeUsarApis) {
    return null;
  }

  return (
    <ModalShell
      eyebrow="Evaluaciones externas"
      title={proveedor.razonSocial}
      subtitle={`RUT consultado: ${proveedor.rut}`}
      onCerrar={onCerrar}
      maxWidth="1100px"
    >
      <div style={styles.apiGrid}>
        <section style={styles.apiCard}>
          <h3 style={styles.sectionTitle}>
            Gesintel / AML
          </h3>

          <p style={styles.apiDescription}>
            Consulta riesgo, detalle,
            banderas y reporte AML.
          </p>

          {amlError && (
            <div
              style={{
                ...ui.errorBox,
                marginBottom: spacing.md,
              }}
            >
              {amlError}
            </div>
          )}

          <div style={styles.actionRow}>
            <ApiButton
              loading={
                amlLoading === 'risk'
              }
              disabled={Boolean(
                amlLoading
              )}
              label="Consultar riesgo"
              onClick={() =>
                handleAmlAction('risk')
              }
            />

            <ApiButton
              loading={
                amlLoading === 'result'
              }
              disabled={Boolean(
                amlLoading
              )}
              label="Consultar detalle"
              onClick={() =>
                handleAmlAction('result')
              }
            />

            <ApiButton
              loading={
                amlLoading === 'flags'
              }
              disabled={Boolean(
                amlLoading
              )}
              label="Consultar flags"
              onClick={() =>
                handleAmlAction('flags')
              }
            />

            <ApiButton
              loading={
                amlLoading === 'report'
              }
              disabled={Boolean(
                amlLoading
              )}
              label="Generar reporte"
              onClick={() =>
                handleAmlAction('report')
              }
            />
          </div>

          {ultimoAmlCheck && (
            <div
              style={
                styles.apiResultBox
              }
            >
              <h4
                style={
                  styles.apiResultTitle
                }
              >
                Último resultado Gesintel
              </h4>

              <div
                style={
                  styles.amlResultGrid
                }
              >
                <InfoItem
                  label="Endpoint"
                  value={
                    ultimoAmlCheck
                      .endpoint || '—'
                  }
                />

                <InfoItem
                  label="Estado"
                  value={
                    ultimoAmlCheck.amlStatus ??
                    getAmlResponseStatus(
                      ultimoAmlCheck.response
                    )
                  }
                />

                <InfoItem
                  label="Coincidencia"
                  value={
                    ultimoAmlCheck
                      .percent !== null &&
                    ultimoAmlCheck
                      .percent !==
                      undefined
                      ? `${ultimoAmlCheck.percent}%`
                      : '—'
                  }
                />

                <InfoItem
                  label="PEPSAN"
                  value={
                    ultimoAmlCheck
                      .pepsanColor ||
                    '—'
                  }
                />

                <InfoItem
                  label="KYCAME"
                  value={
                    ultimoAmlCheck
                      .kycameColor ||
                    '—'
                  }
                />

                <InfoItem
                  label="UBOCOM"
                  value={
                    ultimoAmlCheck
                      .ubocomColor ||
                    '—'
                  }
                />
              </div>

{ultimoAmlCheck.pdfLink && (
  <button
    type="button"
    style={{
      ...styles.reportButton,
      opacity:
        amlPdfLoading
          ? 0.65
          : 1,

      cursor:
        amlPdfLoading
          ? 'not-allowed'
          : 'pointer',
    }}
    disabled={amlPdfLoading}
    onClick={() =>
      void abrirAmlReportPdf()
    }
  >
    {amlPdfLoading
      ? 'Abriendo reporte...'
      : 'Abrir reporte Gesintel'}
  </button>
)}
            </div>
          )}
        </section>

        <section style={styles.apiCard}>
          <h3 style={styles.sectionTitle}>
            DICOM
          </h3>

          <p style={styles.apiDescription}>
            Consulta información comercial
            asociada a la empresa o persona.
          </p>

          {dicomError && (
            <div
              style={{
                ...ui.errorBox,
                marginBottom: spacing.md,
              }}
            >
              {dicomError}
            </div>
          )}

          <div style={styles.actionRow}>
            <ApiButton
              loading={
                dicomLoading === 'empresa'
              }
              disabled={Boolean(
                dicomLoading
              )}
              label="Consultar empresa"
              onClick={() =>
                handleDicomAction(
                  'empresa'
                )
              }
            />

            <ApiButton
              loading={
                dicomLoading === 'persona'
              }
              disabled={Boolean(
                dicomLoading
              )}
              label="Consultar persona"
              onClick={() =>
                handleDicomAction(
                  'persona'
                )
              }
            />

            <ApiButton
              loading={
                dicomLoading ===
                'historial'
              }
              disabled={Boolean(
                dicomLoading
              )}
              label="Consultar historial"
              onClick={() =>
                handleDicomAction(
                  'historial'
                )
              }
            />

            <ApiButton
              loading={
                dicomLoading ===
                'informacion'
              }
              disabled={Boolean(
                dicomLoading
              )}
              label="Consultar información"
              onClick={() =>
                handleDicomAction(
                  'informacion'
                )
              }
            />
          </div>

          {ultimoDicomCheck && (
            <div
              style={
                styles.apiResultBox
              }
            >
              <h4
                style={
                  styles.apiResultTitle
                }
              >
                Último resultado DICOM
              </h4>

              <div
                style={
                  styles.amlResultGrid
                }
              >
                <InfoItem
                  label="Endpoint"
                  value={
                    ultimoDicomCheck
                      .endpoint || '—'
                  }
                />

                <InfoItem
                  label="RUT"
                  value={
                    ultimoDicomCheck.rut ||
                    proveedor.rut
                  }
                />

                <InfoItem
                  label="Segmento"
                  value={
                    ultimoDicomCheck
                      .segmento || '—'
                  }
                />

                <InfoItem
                  label="Fecha"
                  value={
                    ultimoDicomCheck
                      .fechaConsulta ||
                    ultimoDicomCheck
                      .createdAt ||
                    '—'
                  }
                />

                <InfoItem
                  label="Código HTTP"
                  value={
                    ultimoDicomCheck
                      .httpCode !== null &&
                    ultimoDicomCheck
                      .httpCode !==
                      undefined
                      ? String(
                          ultimoDicomCheck
                            .httpCode
                        )
                      : '—'
                  }
                />
              </div>
            </div>
          )}
        </section>
      </div>
    </ModalShell>
  );
};

/*
|--------------------------------------------------------------------------
| Subcomponentes
|--------------------------------------------------------------------------
*/

interface ModalShellProps {
  eyebrow: string;
  title: string;
  subtitle?: string;
  onCerrar: () => void;
  children: React.ReactNode;
  maxWidth?: string;
  zIndex?: number;
}

const ModalShell: React.FC<
  ModalShellProps
> = ({
  eyebrow,
  title,
  subtitle,
  onCerrar,
  children,
  maxWidth = '960px',
  zIndex = 1000,
}) => (
  <div
    style={{
      ...styles.detailOverlay,
      zIndex,
    }}
    onClick={onCerrar}
  >
    <div
      role="dialog"
      aria-modal="true"
      style={{
        ...styles.detailModal,
        maxWidth,
      }}
      onClick={event =>
        event.stopPropagation()
      }
    >
      <div style={styles.detailHeader}>
        <div>
          <p style={styles.modalEyebrow}>
            {eyebrow}
          </p>

          <h2
            style={{
              margin: 0,
              color: colors.textMain,
            }}
          >
            {title}
          </h2>

          {subtitle && (
            <p
              style={{
                margin: '6px 0 0',
                color: colors.textSoft,
              }}
            >
              {subtitle}
            </p>
          )}
        </div>

        <button
          type="button"
          style={ui.secondaryButton}
          onClick={onCerrar}
        >
          Cerrar
        </button>
      </div>

      <div style={styles.detailBody}>
        {children}
      </div>
    </div>
  </div>
);

interface InfoItemProps {
  label: string;
  value: string;
}

const InfoItem: React.FC<
  InfoItemProps
> = ({ label, value }) => (
  <div style={styles.infoCard}>
    <p style={styles.infoLabel}>
      {label}
    </p>

    <p style={styles.infoValue}>
      {value}
    </p>
  </div>
);

interface ApiButtonProps {
  label: string;
  loading: boolean;
  disabled: boolean;
  onClick: () => void;
}

const ApiButton: React.FC<
  ApiButtonProps
> = ({
  label,
  loading,
  disabled,
  onClick,
}) => (
  <button
    type="button"
    style={{
      ...styles.infoButton,
      opacity: disabled ? 0.65 : 1,
      cursor: disabled
        ? 'not-allowed'
        : 'pointer',
    }}
    disabled={disabled}
    onClick={onClick}
  >
    {loading
      ? 'Consultando...'
      : label}
  </button>
);

/*
|--------------------------------------------------------------------------
| Estilos
|--------------------------------------------------------------------------
*/

const styles: Record<
  string,
  React.CSSProperties
> = {
  toolbarCard: {
    ...ui.card,
    borderRadius: radius.xl,
    padding: '18px',
    display: 'grid',
    gridTemplateColumns:
      'minmax(520px, 1fr) auto minmax(220px, 280px)',
    alignItems: 'end',
    gap: spacing.lg,
    marginBottom: spacing.md,
  },

  filterBlock: {
    display: 'flex',
    flexDirection: 'column',
    gap: '10px',
  },

  filterLabel: {
    margin: 0,
    fontSize: '13px',
    fontWeight: 700,
    color: colors.textSoft,
  },

  filterGroup: {
    display: 'flex',
    gap: '8px',
    flexWrap: 'wrap',
  },

  filterButton: {
    border:
      `1px solid ${colors.borderStrong}`,
    background: colors.bgCard,
    padding: '9px 14px',
    borderRadius: radius.pill,
    cursor: 'pointer',
    fontWeight: 600,
    color: '#4b5563',
  },

  filterButtonActive: {
    background:
      `linear-gradient(135deg, ${colors.primary} 0%, ${colors.primaryDark} 100%)`,
    color: '#ffffff',
    border:
      `1px solid ${colors.primaryDark}`,
    padding: '9px 14px',
    borderRadius: radius.pill,
    cursor: 'pointer',
    fontWeight: 700,
  },

  createProveedorMiddle: {
    display: 'flex',
    justifyContent: 'center',
  },

  selectWrap: {
    display: 'flex',
    flexDirection: 'column',
    gap: '6px',
  },

  selectLabel: {
    fontSize: '13px',
    fontWeight: 700,
    color: colors.textSoft,
  },

  select: {
    minWidth: '220px',
    padding: '11px 12px',
    borderRadius: radius.md,
    border:
      `1px solid ${colors.borderStrong}`,
    background: colors.bgCard,
  },

  tableCard: {
    background: colors.bgCard,
    borderRadius: '22px',
    border:
      `1px solid ${colors.border}`,
    overflow: 'hidden',
  },

  sectionHeader: {
    padding: '22px 22px 8px',
  },

  sectionSubtitle: {
    margin: 0,
    color: colors.textSoft,
    fontSize: '14px',
  },

  noDataState: {
    padding: '38px 24px',
    textAlign: 'center',
  },

  noDataIcon: {
    fontSize: '34px',
  },

  noDataTitle: {
    margin: '8px 0 0',
    fontWeight: 800,
    color: colors.textMain,
  },

  noDataText: {
    margin: '8px 0 0',
    color: colors.textSoft,
    fontSize: '14px',
  },

  tableActions: {
    display: 'flex',
    gap: '7px',
    flexWrap: 'wrap',
    padding: '6px 0',
  },

  tableActionButton: {
    border: 'none',
    padding: '9px 11px',
    borderRadius: radius.md,
    cursor: 'pointer',
    fontSize: '12px',
    fontWeight: 700,
    whiteSpace: 'nowrap',
  },

  detailActionButton: {
    background: colors.primarySoft,
    color: '#4338ca',
  },

  validationsActionButton: {
    background: colors.warningSoft,
    color: '#92400e',
  },

  documentsActionButton: {
    background: colors.successSoft,
    color: '#166534',
  },

  apiActionButton: {
    background: '#e0f2fe',
    color: '#0369a1',
  },

  detailOverlay: {
    position: 'fixed',
    inset: 0,
    background:
      'rgba(15, 23, 42, 0.48)',
    display: 'flex',
    justifyContent: 'center',
    alignItems: 'center',
    padding: '20px',
  },

  detailModal: {
    width: '100%',
    background: colors.bgCard,
    borderRadius: radius.xxl,
    overflow: 'hidden',
    boxShadow:
      '0 25px 60px rgba(15, 23, 42, 0.24)',
  },

  detailHeader: {
    padding: '22px 24px',
    borderBottom:
      `1px solid ${colors.border}`,
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.md,
    background:
      'linear-gradient(135deg, #ffffff 0%, #f8fafc 100%)',
  },

  modalEyebrow: {
    margin: '0 0 8px',
    fontSize: '12px',
    fontWeight: 700,
    color: colors.primary,
    textTransform: 'uppercase',
    letterSpacing: '0.08em',
  },

  detailBody: {
    padding: spacing.xl,
    maxHeight: '78vh',
    overflowY: 'auto',
  },

  detailGrid: {
    display: 'grid',
    gridTemplateColumns:
      'repeat(auto-fit, minmax(210px, 1fr))',
    gap: '14px',
    marginBottom: spacing.xl,
  },

  infoCard: {
    background: colors.bgSoft,
    border:
      `1px solid ${colors.border}`,
    borderRadius: radius.lg,
    padding: '14px',
    minWidth: 0,
  },

  infoLabel: {
    margin: 0,
    fontSize: '11px',
    fontWeight: 700,
    color: colors.textSoft,
    textTransform: 'uppercase',
  },

  infoValue: {
    margin: '8px 0 0',
    fontSize: '14px',
    fontWeight: 700,
    color: colors.textMain,
    overflowWrap: 'anywhere',
  },

  sectionTitle: {
    margin: '0 0 16px',
    fontSize: '20px',
    fontWeight: 800,
    color: colors.textMain,
  },

  chipsWrap: {
    display: 'flex',
    gap: '8px',
    flexWrap: 'wrap',
    marginBottom: spacing.xl,
  },

  sociedadChip: {
    padding: '7px 10px',
    borderRadius: radius.pill,
    background: colors.bgSoft,
    border:
      `1px solid ${colors.border}`,
    fontSize: '12px',
    fontWeight: 700,
  },

  actionPanel: {
    background: colors.bgSoft,
    border:
      `1px solid ${colors.border}`,
    borderRadius: radius.xl,
    padding: spacing.md,
    marginBottom: spacing.xl,
  },

  panelLabel: {
    margin: '0 0 12px',
    fontWeight: 700,
    color: colors.textSoft,
  },

  actionRow: {
    display: 'flex',
    gap: spacing.sm,
    flexWrap: 'wrap',
  },

  infoButton: {
    background: colors.info,
    color: '#ffffff',
    border: 'none',
    padding: '10px 14px',
    borderRadius: radius.md,
    cursor: 'pointer',
    fontWeight: 700,
  },

  warningButton: {
    background: '#f59e0b',
    color: '#ffffff',
    border: 'none',
    padding: '10px 14px',
    borderRadius: radius.md,
    cursor: 'pointer',
    fontWeight: 700,
  },

  successButton: {
    background: colors.success,
    color: '#ffffff',
    border: 'none',
    padding: '10px 14px',
    borderRadius: radius.md,
    cursor: 'pointer',
    fontWeight: 700,
  },

  dangerButton: {
    background: colors.danger,
    color: '#ffffff',
    border: 'none',
    padding: '10px 14px',
    borderRadius: radius.md,
    cursor: 'pointer',
    fontWeight: 700,
  },

  historyList: {
    paddingLeft: '20px',
    margin: 0,
  },

  historyItem: {
    marginBottom: '12px',
    lineHeight: 1.5,
  },

  processSummaryGrid: {
    display: 'grid',
    gridTemplateColumns:
      'repeat(auto-fit, minmax(140px, 1fr))',
    gap: '12px',
    marginBottom: spacing.xl,
  },

  docsList: {
    display: 'grid',
    gap: spacing.md,
  },

  docCard: {
    border:
      `1px solid ${colors.border}`,
    borderRadius: radius.xl,
    padding: spacing.md,
    background: '#fafafa',
  },

  docHeader: {
    display: 'flex',
    justifyContent: 'space-between',
    gap: spacing.md,
    flexWrap: 'wrap',
  },

  docTitle: {
    margin: 0,
    fontWeight: 700,
    color: colors.textMain,
  },

  docFileName: {
    margin: '5px 0 0',
    color: colors.textSoft,
    fontSize: '13px',
  },

  docMeta: {
    margin: '6px 0 0',
    color: colors.textSoft,
    fontSize: '12px',
    fontWeight: 600,
  },

  docComment: {
    marginTop: '12px',
    fontSize: '13px',
  },

  docActions: {
    display: 'flex',
    gap: '8px',
    flexWrap: 'wrap',
    marginTop: '12px',
  },

  infoButtonSmall: {
    background: colors.infoSoft,
    color: '#1d4ed8',
    border: 'none',
    padding: '8px 10px',
    borderRadius: radius.sm,
    cursor: 'pointer',
    fontSize: '12px',
    fontWeight: 700,
  },

  warningButtonSmall: {
    background: colors.warningSoft,
    color: '#92400e',
    border: 'none',
    padding: '8px 10px',
    borderRadius: radius.sm,
    cursor: 'pointer',
    fontSize: '12px',
    fontWeight: 700,
  },

  successButtonSmall: {
    background: colors.successSoft,
    color: '#166534',
    border: 'none',
    padding: '8px 10px',
    borderRadius: radius.sm,
    cursor: 'pointer',
    fontSize: '12px',
    fontWeight: 700,
  },

  commentTextarea: {
    width: '100%',
    minHeight: '78px',
    marginTop: '12px',
    border:
      `1px solid ${colors.borderStrong}`,
    borderRadius: radius.md,
    padding: '10px 12px',
    resize: 'vertical',
    boxSizing: 'border-box',
  },

  templateButton: {
    display: 'inline-flex',
    marginTop: '8px',
    color: colors.primary,
    fontWeight: 700,
    fontSize: '13px',
  },

  apiGrid: {
    display: 'grid',
    gridTemplateColumns:
      'repeat(auto-fit, minmax(420px, 1fr))',
    gap: spacing.lg,
  },

  apiCard: {
    border:
      `1px solid ${colors.border}`,
    borderRadius: radius.xl,
    padding: spacing.md,
    background:
      'linear-gradient(135deg, #f8fafc 0%, #ffffff 100%)',
  },

  apiDescription: {
    margin: '0 0 16px',
    color: colors.textSoft,
    fontSize: '13px',
    lineHeight: 1.5,
  },

  apiResultBox: {
    marginTop: spacing.md,
    padding: spacing.md,
    background: '#ffffff',
    border:
      `1px solid ${colors.border}`,
    borderRadius: radius.lg,
  },

  apiResultTitle: {
    margin: 0,
    color: colors.textMain,
    fontSize: '15px',
  },

  amlResultGrid: {
    display: 'grid',
    gridTemplateColumns:
      'repeat(auto-fit, minmax(150px, 1fr))',
    gap: spacing.sm,
    marginTop: spacing.sm,
  },

  reportButton: {
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: spacing.md,
    padding: '10px 14px',
    border: 'none',
    borderRadius: radius.md,
    background: colors.primary,
    color: '#ffffff',
    fontSize: '13px',
    fontWeight: 700,
    cursor: 'pointer',
  },

  jsonDetails: {
    marginTop: spacing.md,
    border:
      `1px solid ${colors.border}`,
    borderRadius: radius.md,
    overflow: 'hidden',
  },

  jsonSummary: {
    padding: '10px 12px',
    cursor: 'pointer',
    fontWeight: 700,
    color: colors.textMain,
    background: colors.bgSoft,
  },

  jsonPre: {
    margin: 0,
    padding: '12px',
    maxHeight: '320px',
    overflow: 'auto',
    background: '#0f172a',
    color: '#e2e8f0',
    fontSize: '11px',
    whiteSpace: 'pre-wrap',
    overflowWrap: 'anywhere',
  },
};

export default SeguimientoProveedores;
