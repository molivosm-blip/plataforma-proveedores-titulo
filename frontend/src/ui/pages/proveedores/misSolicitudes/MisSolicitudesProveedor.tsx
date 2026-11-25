import React, { useEffect, useMemo, useState } from 'react';

import { useAuth } from '../../../../config/useAuth';

import { ProveedorSolicitudesService } from '../../../../infrastructure/auth/api/proveedorSolicitudesService';
import { descargarArchivoDocumento } from '../../../../infrastructure/auth/api/documentosArchivoService';
import { useArchivoDocumento } from '../../../../hooks/useArchivoDocumento';

import {
  type DocumentoProveedor,
  type EstadoDocumento,
  type EstadoProveedor,
  type Proveedor,
  type ProveedorPmPrerequisitePending,
} from '../../../../utils/proveedorHelpers';

import { ui } from '../../../theme/commonStyles';
import { colors, radius, spacing } from '../../../theme/tokens';

import type { ApiError } from '../../../../utils/apiTypes';
import DeclaracionModal from '../../../components/proceso/DeclaracionModal';

/*
 * Esta declaración se completa y firma en el portal, no se sube como archivo.
 */
const DOCUMENTO_PEP = 'Declaración Relacionados y PEP';

/* ================= HELPERS UI ================= */

const getEstadoBadgeStyle = (estado: EstadoProveedor): React.CSSProperties => {
  const base: React.CSSProperties = {
    padding: '7px 12px',
    borderRadius: radius.pill,
    fontSize: '12px',
    fontWeight: 700,
    display: 'inline-flex',
    alignItems: 'center',
    gap: '6px',
    letterSpacing: '0.01em',
  };

  switch (estado) {
    case 'Aprobado':
      return { ...base, background: colors.successSoft, color: '#166534' };
    case 'Rechazado':
      return { ...base, background: colors.dangerSoft, color: '#991b1b' };
    case 'Observado':
      return { ...base, background: colors.warningSoft, color: '#92400e' };
    default:
      return { ...base, background: colors.infoSoft, color: '#1d4ed8' };
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
    alignItems: 'center',
    gap: '6px',
  };

  switch (estado) {
    case 'Aprobado':
      return { ...base, background: colors.successSoft, color: '#166534' };
    case 'Observado':
      return { ...base, background: colors.warningSoft, color: '#92400e' };
    case 'Subido':
      return { ...base, background: colors.infoSoft, color: '#1d4ed8' };
    default:
      return { ...base, background: '#e5e7eb', color: '#374151' };
  }
};

const validateDocumentoFile = (file?: File): string | null => {
  if (!file) return 'Debes seleccionar un archivo.';

  const allowedTypes = ['application/pdf', 'image/png', 'image/jpeg'];
  const allowedExtensions = ['.pdf', '.png', '.jpg', '.jpeg'];
  const fileName = file.name.toLowerCase();

  const hasAllowedExtension = allowedExtensions.some(ext =>
    fileName.endsWith(ext)
  );

  if (!allowedTypes.includes(file.type) && !hasAllowedExtension) {
    return 'Formato no permitido. Solo se aceptan PDF, PNG, JPG o JPEG.';
  }

  const maxBytes = 5 * 1024 * 1024;

  if (file.size > maxBytes) {
    return 'El archivo es demasiado grande (máx 5MB).';
  }

  return null;
};

const getDocumentoNombreArchivo = (doc: DocumentoProveedor): string => {
  return doc.archivoNombre || doc.archivo || `${doc.nombre}.pdf`;
};

const getDocumentoUrl = (doc: DocumentoProveedor): string | null => {
  return doc.archivoDataUrl || doc.archivoUrl || null;
};

const isImageDocumento = (doc: DocumentoProveedor): boolean => {
  const mime = doc.archivoMimeType || doc.archivoMimeTypeLegacy || '';
  return mime.startsWith('image/');
};

const getCanUploadDocuments = (proveedor: Proveedor | null): boolean => {
  if (!proveedor) return false;

  const value = proveedor.canUploadDocuments;

  /*
   * Compatibilidad:
   * si el backend todavía no envía canUploadDocuments,
   * no bloqueamos para no romper flujo antiguo.
   */
  if (typeof value !== 'boolean') return true;

  return value;
};

const getPmPrerequisitesPending = (
  proveedor: Proveedor | null
): ProveedorPmPrerequisitePending[] => {
  if (!proveedor) return [];

  const pending = proveedor.pmPrerequisitesPending;

  return Array.isArray(pending) ? pending : [];
};

const isDocumentoProveedor = (doc: DocumentoProveedor): boolean => {
  const responsable = doc.responsableCarga;

  /*
   * Compatibilidad:
   * si backend todavía no envía responsableCarga,
   * asumimos que lo sube proveedor.
   */
  if (!responsable) return true;

  return responsable === 'proveedor';
};

const getPlantillaUrl = (doc: DocumentoProveedor): string | null => {
  return doc.plantillaUrl || null;
};

const getResponsableLabel = (doc: DocumentoProveedor): string => {
  const responsable = doc.responsableCarga;

  if (responsable === 'pm') return 'Solicitante / PM';
  if (responsable === 'interno') return 'Usuario interno';
  if (responsable === 'proveedor') return 'Proveedor';

  return 'Proveedor';
};

/* ================= COMPONENTE ================= */

const MisSolicitudesProveedor: React.FC = () => {
  const { user, isProveedor } = useAuth();

  const [proveedores, setProveedores] = useState<Proveedor[]>([]);
  const [seleccionado, setSeleccionado] = useState<Proveedor | null>(null);
  const [previewDoc, setPreviewDoc] = useState<DocumentoProveedor | null>(null);

  // Los archivos del servidor se piden con la sesión; no existe una URL pública.
  const previewRemoto = Boolean(
    previewDoc && !previewDoc.archivoDataUrl && previewDoc.archivoUrl
  );
  const {
    url: previewBlobUrl,
    error: previewError,
    cargando: previewCargando,
  } = useArchivoDocumento(previewRemoto && previewDoc ? previewDoc.id : null);
  const [declaracionPepAbierta, setDeclaracionPepAbierta] = useState(false);

  const [loading, setLoading] = useState(false);
  const [uploadingDocId, setUploadingDocId] = useState<string | null>(null);

  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const refreshProveedores = async (): Promise<Proveedor[]> => {
    setLoading(true);
    setError(null);

    try {
      const data = await ProveedorSolicitudesService.getMisSolicitudes();
      setProveedores(data);
      return data;
    } catch (caught: unknown) {
      const err = caught as ApiError;
      console.error('Error cargando solicitudes del proveedor:', err);

      const message =
        err?.response?.data?.message ||
        err?.response?.data?.error ||
        'No se pudieron cargar tus solicitudes.';

      setError(message);
      setProveedores([]);

      return [];
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (isProveedor) {
      refreshProveedores();
    }
  }, [isProveedor]);

  const misRegistros = useMemo(() => {
    if (!user?.email) return [];

    const email = user.email.trim().toLowerCase();

    return proveedores.filter(
      proveedor =>
        proveedor.proveedorEmail?.trim().toLowerCase() === email ||
        proveedor.proveedorEmail === undefined ||
        proveedor.proveedorEmail === null
    );
  }, [proveedores, user?.email]);

  const stats = useMemo(() => {
    const total = misRegistros.length;

    const enRevision = misRegistros.filter(
      proveedor => proveedor.estado === 'En revisión'
    ).length;

    const observados = misRegistros.filter(
      proveedor => proveedor.estado === 'Observado'
    ).length;

    const aprobados = misRegistros.filter(
      proveedor => proveedor.estado === 'Aprobado'
    ).length;

    const rechazados = misRegistros.filter(
      proveedor => proveedor.estado === 'Rechazado'
    ).length;

    return { total, enRevision, observados, aprobados, rechazados };
  }, [misRegistros]);

  const getFallbackText = (doc: DocumentoProveedor) => {
    const lines = [
      `Proveedor: ${seleccionado?.razonSocial || '—'}`,
      `Empresa: ${seleccionado?.empresaNombre || '—'}`,
      `Documento: ${doc.nombre}`,
      `Archivo: ${getDocumentoNombreArchivo(doc) || 'Sin nombre'}`,
      `Estado: ${doc.estado}`,
      `Actualizado: ${
        doc.updatedAt ? new Date(doc.updatedAt).toLocaleString() : '—'
      }`,
    ];

    return lines.join('\n');
  };

  const getPreviewUrl = (doc: DocumentoProveedor) => {
    const url = getDocumentoUrl(doc);

    if (url) {
      return url;
    }

    if (!doc.archivoNombre && !doc.archivo) {
      return null;
    }

    return `data:text/plain;charset=utf-8,${encodeURIComponent(
      getFallbackText(doc)
    )}`;
  };

  const handleDownload = (doc: DocumentoProveedor) => {
    const url = getDocumentoUrl(doc);
    const fileName = getDocumentoNombreArchivo(doc);

    if (url && !doc.archivoDataUrl) {
      descargarArchivoDocumento(doc.id, fileName).catch((caught: unknown) => {
        setError(
          caught instanceof Error ? caught.message : 'No se pudo descargar el documento.'
        );
      });
      return;
    }

    if (url) {
      const link = document.createElement('a');
      link.href = url;
      link.download = fileName;
      document.body.appendChild(link);
      link.click();
      link.remove();
      return;
    }

    const blob = new Blob([getFallbackText(doc)], {
      type: 'text/plain;charset=utf-8',
    });

    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = objectUrl;
    link.download = fileName;

    document.body.appendChild(link);
    link.click();
    link.remove();

    URL.revokeObjectURL(objectUrl);
  };

  const observedItems = useMemo(() => {
    if (!seleccionado) return [];

    return seleccionado.documentos
      .filter(doc => doc.estado === 'Observado')
      .map(doc => ({
        key: `documento-${doc.id}`,
        title: `Documento: ${doc.nombre}`,
        comment: doc.comentario || 'Sin comentario',
      }));
  }, [seleccionado]);

  const handleSubirDocumento = async (
    proveedorId: string,
    documentoId: string,
    file?: File
  ) => {
    setError(null);
    setSuccess(null);

    if (!isProveedor) {
      setError('Solo el proveedor puede subir documentos.');
      return;
    }

    const validationError = validateDocumentoFile(file);

    if (validationError || !file) {
      setError(validationError || 'Debes seleccionar un archivo.');
      return;
    }

    const proveedorActual = proveedores.find(
      proveedor => String(proveedor.id) === String(proveedorId)
    );

    const documentoActual = proveedorActual?.documentos.find(
      documento => String(documento.id) === String(documentoId)
    );

    if (!proveedorActual || !documentoActual) {
      setError('No se encontró el documento seleccionado.');
      return;
    }

    if (!getCanUploadDocuments(proveedorActual)) {
      setError(
        'La carga documental está bloqueada. El PM/Solicitante debe cargar primero los documentos requeridos.'
      );
      return;
    }

    if (!isDocumentoProveedor(documentoActual)) {
      setError(
        'Este documento debe ser cargado por el PM/Solicitante o por un usuario interno.'
      );
      return;
    }

    setUploadingDocId(documentoId);

    try {
      const result = await ProveedorSolicitudesService.uploadDocumento(
        documentoId,
        file
      );

      const actualizados = await refreshProveedores();

      const proveedorActualizado =
        result.proveedor ||
        actualizados.find(
          proveedor => String(proveedor.id) === String(proveedorId)
        ) ||
        proveedorActual;

      setSeleccionado(proveedorActualizado);

      setSuccess(`Documento "${documentoActual.nombre}" subido correctamente.`);
    } catch (caught: unknown) {
      const err = caught as ApiError;
      console.error('Error subiendo documento:', err);

      const message =
        err?.response?.data?.message ||
        err?.response?.data?.error ||
        'No se pudo subir el documento.';

      setError(message);
    } finally {
      setUploadingDocId(null);
    }
  };

  if (!isProveedor) {
    return (
      <div style={ui.page}>
        <div style={{ ...ui.card, maxWidth: '720px' }}>
          <h1 style={ui.title}>Acceso no permitido</h1>
          <p style={ui.subtitle}>
            Esta vista está disponible solo para proveedores.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div style={ui.page}>
      <div style={ui.bannerCard}>
        <div>
          <p style={styles.bannerEyebrow}>Portal documental</p>
          <h1 style={ui.title}>Documentación Requerida</h1>
          <p style={ui.subtitle}>
            Revisa el estado de tu proceso documental y sube los archivos
            solicitados según la empresa a la que fuiste asociado.
          </p>
        </div>
      </div>

      {error && (
        <div style={{ ...ui.errorBox, marginBottom: spacing.md }}>
          {error}
        </div>
      )}

      {success && (
        <div style={{ ...ui.successBox, marginBottom: spacing.md }}>
          {success}
        </div>
      )}

      <div style={styles.kpiGrid}>
        <KpiCard title="Procesos asignados" value={stats.total} icon="📁" />
        <KpiCard title="En revisión" value={stats.enRevision} icon="🕒" />
        <KpiCard title="Observados" value={stats.observados} icon="⚠️" />
        <KpiCard title="Aprobados" value={stats.aprobados} icon="✅" />
      </div>

      <div style={styles.listWrap}>
        {loading ? (
          <div style={styles.emptyState}>
            <div style={styles.emptyIcon}>⏳</div>
            <h3 style={styles.emptyTitle}>Cargando solicitudes</h3>
            <p style={styles.emptyText}>
              Estamos consultando tus procesos documentales.
            </p>
          </div>
        ) : misRegistros.length === 0 ? (
          <div style={styles.emptyState}>
            <div style={styles.emptyIcon}>📭</div>
            <h3 style={styles.emptyTitle}>
              No tienes procesos documentales asociados
            </h3>
            <p style={styles.emptyText}>
              Cuando un PM te asigne un proveedor, aquí podrás ver los
              documentos requeridos y subir tu información.
            </p>
          </div>
        ) : (
          misRegistros.map(item => (
            <div key={item.id} style={styles.requestCard}>
              <div style={styles.requestTop}>
                <div>
                  <h3 style={styles.requestTitle}>{item.razonSocial}</h3>
                  <p style={styles.requestSubtitle}>
                    Empresa: <strong>{item.empresaNombre}</strong>
                  </p>
                </div>

                <span style={getEstadoBadgeStyle(item.estado)}>
                  {item.estado}
                </span>
              </div>

              <div style={styles.requestMeta}>
                <InfoPill label="RUT" value={item.rut} />
                <InfoPill label="Tipo" value={item.tipo} />
                <InfoPill label="Giro" value={item.giro || '—'} />
                <InfoPill
                  label="Creado por"
                  value={item.createdByName || item.createdByEmail || '—'}
                />
              </div>

              {item.sociedadesHabilitadas.length > 0 && (
                <>
                  <h4 style={styles.subsectionTitle}>
                    Sociedades habilitadas
                  </h4>
                  <div style={styles.chipsWrap}>
                    {item.sociedadesHabilitadas.map(sociedad => (
                      <span key={sociedad} style={styles.sociedadChip}>
                        {sociedad}
                      </span>
                    ))}
                  </div>
                </>
              )}

              {!getCanUploadDocuments(item) && (
                <div style={styles.blockedPanel}>
                  <strong>Carga documental bloqueada</strong>
                  <p style={styles.blockedText}>
                    El PM/Solicitante debe cargar primero los documentos
                    requeridos.
                  </p>

                  {getPmPrerequisitesPending(item).length > 0 && (
                    <ul style={styles.prereqList}>
                      {getPmPrerequisitesPending(item).map(
                        (pending, index) => (
                          <li key={pending.id || index}>
                            {pending.nombre} — {pending.estado}
                          </li>
                        )
                      )}
                    </ul>
                  )}
                </div>
              )}

              <h4 style={styles.subsectionTitle}>Documentos requeridos</h4>

              <div style={styles.docsPreview}>
                {item.documentos.map(doc => (
                  <div key={doc.id} style={styles.docPreviewRow}>
                    <span style={styles.docPreviewName}>{doc.nombre}</span>
                    <span style={getDocumentoBadgeStyle(doc.estado)}>
                      {doc.estado}
                    </span>
                  </div>
                ))}
              </div>

              <button
                style={ui.primaryButton}
                onClick={() => {
                  setError(null);
                  setSuccess(null);
                  setSeleccionado(item);
                }}
              >
                Ver detalle y subir documentos
              </button>
            </div>
          ))
        )}
      </div>

      {seleccionado && (
        <div style={styles.overlay}>
          <div style={styles.modal}>
            <div style={styles.modalHeader}>
              <div>
                <p style={styles.modalEyebrow}>Detalle del proceso</p>
                <h2 style={{ margin: 0, color: colors.textMain }}>
                  {seleccionado.razonSocial}
                </h2>
                <p style={{ margin: '6px 0 0 0', color: colors.textSoft }}>
                  Empresa asociada: {seleccionado.empresaNombre}
                </p>
              </div>

              <button
                style={ui.secondaryButton}
                onClick={() => setSeleccionado(null)}
              >
                Cerrar
              </button>
            </div>

            <div style={styles.modalBody}>
              <div style={styles.detailGrid}>
                <InfoCard label="RUT" value={seleccionado.rut} />
                <InfoCard label="Tipo" value={seleccionado.tipo} />
                <InfoCard label="Giro" value={seleccionado.giro || '—'} />
                <div style={styles.infoCard}>
                  <p style={styles.infoLabel}>Estado general</p>
                  <div style={{ marginTop: '8px' }}>
                    <span style={getEstadoBadgeStyle(seleccionado.estado)}>
                      {seleccionado.estado}
                    </span>
                  </div>
                </div>
              </div>

              {seleccionado.detalle && (
                <>
                  <h3 style={styles.sectionTitle}>Datos de incorporación</h3>
                  <div style={styles.detailGrid}>
                    <InfoCard
                      label="Dirección"
                      value={seleccionado.detalle.direccion || '—'}
                    />
                    <InfoCard
                      label="Comuna"
                      value={seleccionado.detalle.comuna || '—'}
                    />
                    <InfoCard
                      label="Región"
                      value={seleccionado.detalle.region || '—'}
                    />
                    <InfoCard
                      label="Moneda"
                      value={seleccionado.detalle.moneda || '—'}
                    />
                    <InfoCard
                      label="País"
                      value={seleccionado.detalle.pais || '—'}
                    />
                    <InfoCard
                      label="Plazo de pago"
                      value={seleccionado.detalle.plazoPago || '—'}
                    />
                    <InfoCard
                      label="Forma de pago"
                      value={seleccionado.detalle.formaPago || '—'}
                    />
                    <InfoCard
                      label="Banco"
                      value={seleccionado.detalle.bancoPago || '—'}
                    />
                    <InfoCard
                      label="Cuenta corriente"
                      value={seleccionado.detalle.cuentaCorriente || '—'}
                    />
                    <InfoCard
                      label="Tipo proveedor"
                      value={seleccionado.detalle.tipoProveedorDetalle || '—'}
                    />
                  </div>
                </>
              )}

              {seleccionado.sociedadesHabilitadas.length > 0 && (
                <>
                  <h3 style={styles.sectionTitle}>Sociedades habilitadas</h3>
                  <div style={styles.chipsWrap}>
                    {seleccionado.sociedadesHabilitadas.map(sociedad => (
                      <span key={sociedad} style={styles.sociedadChip}>
                        {sociedad}
                      </span>
                    ))}
                  </div>
                </>
              )}

              {observedItems.length > 0 && (
                <>
                  <h3 style={styles.sectionTitle}>Observaciones pendientes</h3>
                  <div style={styles.observationsList}>
                    {observedItems.map(item => (
                      <div key={item.key} style={styles.observationCard}>
                        <p style={styles.observationTitle}>{item.title}</p>
                        <p style={styles.observationText}>{item.comment}</p>
                      </div>
                    ))}
                  </div>
                </>
              )}

              {!getCanUploadDocuments(seleccionado) && (
                <div style={styles.blockedPanel}>
                  <strong>Carga documental bloqueada</strong>
                  <p style={styles.blockedText}>
                    El PM/Solicitante debe cargar primero los documentos
                    prerrequisito antes de que puedas subir tus documentos.
                  </p>

                  {getPmPrerequisitesPending(seleccionado).length > 0 && (
                    <ul style={styles.prereqList}>
                      {getPmPrerequisitesPending(seleccionado).map(
                        (pending, index) => (
                          <li key={pending.id || index}>
                            {pending.nombre} — {pending.estado}
                          </li>
                        )
                      )}
                    </ul>
                  )}
                </div>
              )}

              <h3 style={styles.sectionTitle}>Documentos requeridos</h3>

              <div style={styles.docsGrid}>
                {seleccionado.documentos.map(doc => {
                  const archivoNombre = getDocumentoNombreArchivo(doc);
                  const hasFile = Boolean(getDocumentoUrl(doc));
                  const isUploading = uploadingDocId === doc.id;
                  const canUploadThisDoc =
                    getCanUploadDocuments(seleccionado) &&
                    isDocumentoProveedor(doc);
                  const plantillaUrl = getPlantillaUrl(doc);

                  return (
                    <div key={doc.id} style={styles.docCard}>
                      <div style={styles.docTop}>
                        <div>
                          <p style={styles.docTitle}>{doc.nombre}</p>
                          <p style={styles.docFileName}>
                            {hasFile
                              ? `Archivo actual: ${archivoNombre}`
                              : 'Aún no has subido este documento'}
                          </p>

                          <p style={styles.docMeta}>
                            Responsable de carga: {getResponsableLabel(doc)}
                          </p>

                          {plantillaUrl && (
                            <a
                              href={plantillaUrl}
                              target="_blank"
                              rel="noreferrer"
                              style={styles.templateButton}
                            >
                              Descargar plantilla
                            </a>
                          )}
                        </div>

                        <span style={getDocumentoBadgeStyle(doc.estado)}>
                          {doc.estado}
                        </span>
                      </div>

                      {doc.comentario && (
                        <p style={styles.comment}>
                          <strong>Comentario del revisor:</strong>{' '}
                          {doc.comentario}
                        </p>
                      )}

                      {hasFile && (
                        <div style={styles.docActionsInline}>
                          <button
                            style={ui.secondaryButton}
                            onClick={() => setPreviewDoc(doc)}
                            type="button"
                          >
                            Vista previa
                          </button>
                          <button
                            style={ui.primaryButton}
                            onClick={() => handleDownload(doc)}
                            type="button"
                          >
                            Descargar
                          </button>
                        </div>
                      )}

                      <div style={{ marginTop: '12px' }}>
                        {doc.nombre === DOCUMENTO_PEP ? (
                          doc.estado !== 'Aprobado' && (
                            <button
                              type="button"
                              style={{
                                ...ui.primaryButton,
                                opacity: canUploadThisDoc ? 1 : 0.6,
                              }}
                              disabled={!canUploadThisDoc}
                              onClick={() => setDeclaracionPepAbierta(true)}
                            >
                              {hasFile ? 'Volver a firmar' : 'Completar y firmar'}
                            </button>
                          )
                        ) : (
                          <input
                            type="file"
                            accept=".pdf,.png,.jpg,.jpeg"
                            disabled={isUploading || !canUploadThisDoc}
                            onChange={event =>
                              handleSubirDocumento(
                                seleccionado.id,
                                doc.id,
                                event.target.files?.[0]
                              )
                            }
                          />
                        )}

                        <p style={styles.uploadHint}>
                          {doc.nombre === DOCUMENTO_PEP && canUploadThisDoc
                            ? 'La firma el representante legal en el formulario digital del portal.'
                            : isUploading
                            ? 'Subiendo documento...'
                            : !getCanUploadDocuments(seleccionado)
                              ? 'Carga bloqueada hasta que PM/Solicitante cargue los documentos previos.'
                              : !isDocumentoProveedor(doc)
                                ? 'Este documento debe ser cargado por PM/Solicitante o usuario interno.'
                                : 'Formatos permitidos: PDF, PNG, JPG/JPEG. Máximo 5MB.'}
                        </p>
                      </div>
                    </div>
                  );
                })}
              </div>

              {declaracionPepAbierta && (
                <DeclaracionModal
                  tipo="relacionados_pep"
                  proveedorId={seleccionado.id}
                  razonSocial={seleccionado.razonSocial}
                  rut={seleccionado.rut}
                  declaranteNombre={user?.displayName || user?.email || ''}
                  onCerrar={() => setDeclaracionPepAbierta(false)}
                  onFirmada={async () => {
                    setDeclaracionPepAbierta(false);
                    const actualizados = await refreshProveedores();
                    setSeleccionado(
                      actualizados.find(p => String(p.id) === String(seleccionado.id)) ||
                        seleccionado
                    );
                    setSuccess('Declaración de relacionados y PEP firmada correctamente.');
                  }}
                />
              )}

              {previewDoc && (
                <div
                  style={styles.overlay}
                  onClick={() => setPreviewDoc(null)}
                >
                  <div
                    style={{ ...styles.modal, maxWidth: '960px', width: '92%' }}
                    onClick={event => event.stopPropagation()}
                  >
                    <div style={styles.modalHeader}>
                      <div>
                        <p style={styles.modalEyebrow}>Vista previa</p>
                        <h2 style={{ margin: 0, color: colors.textMain }}>
                          {previewDoc.nombre}
                        </h2>
                        <p
                          style={{
                            margin: '6px 0 0 0',
                            color: colors.textSoft,
                          }}
                        >
                          {getDocumentoNombreArchivo(previewDoc)}
                        </p>
                      </div>

                      <button
                        style={ui.secondaryButton}
                        onClick={() => setPreviewDoc(null)}
                        type="button"
                      >
                        Cerrar
                      </button>
                    </div>

                    <div style={styles.modalBody}>
                      {previewRemoto && previewCargando && <p>Cargando documento…</p>}
                      {previewRemoto && previewError && (
                        <p style={{ color: colors.textSoft }}>{previewError}</p>
                      )}
                      {previewRemoto && !previewBlobUrl ? null : isImageDocumento(previewDoc) ? (
                        <img
                          src={(previewRemoto ? previewBlobUrl : getPreviewUrl(previewDoc)) || undefined}
                          alt={previewDoc.nombre}
                          style={{
                            maxWidth: '100%',
                            borderRadius: radius.lg,
                          }}
                        />
                      ) : (
                        <iframe
                          title={previewDoc.nombre}
                          src={(previewRemoto ? previewBlobUrl : getPreviewUrl(previewDoc)) || undefined}
                          style={{
                            width: '100%',
                            height: '70vh',
                            border: `1px solid ${colors.border}`,
                            borderRadius: radius.lg,
                          }}
                        />
                      )}
                    </div>
                  </div>
                </div>
              )}

              <h3 style={styles.sectionTitle}>Historial del proceso</h3>

              {seleccionado.historial.length === 0 ? (
                <p style={styles.emptyText}>Sin historial disponible.</p>
              ) : (
                <ul style={styles.historyList}>
                  {seleccionado.historial.map((historial, index) => (
                    <li key={historial.id || index} style={styles.historyItem}>
                      <strong>
                        {new Date(historial.fecha).toLocaleString()}
                      </strong>{' '}
                      — {historial.accion}
                      <div style={{ fontSize: '12px', color: colors.textSoft }}>
                        por {historial.realizadoPor}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

/* ================= SUBCOMPONENTES ================= */

interface KpiCardProps {
  title: string;
  value: number | string;
  icon: string;
}

const KpiCard: React.FC<KpiCardProps> = ({ title, value, icon }) => (
  <div style={styles.kpiCard}>
    <div>
      <p style={styles.kpiTitle}>{title}</p>
      <h2 style={styles.kpiValue}>{value}</h2>
    </div>
    <div style={styles.kpiIconWrap}>{icon}</div>
  </div>
);

interface InfoCardProps {
  label: string;
  value: string;
}

const InfoCard: React.FC<InfoCardProps> = ({ label, value }) => (
  <div style={styles.infoCard}>
    <p style={styles.infoLabel}>{label}</p>
    <p style={styles.infoValue}>{value}</p>
  </div>
);

interface InfoPillProps {
  label: string;
  value: string;
}

const InfoPill: React.FC<InfoPillProps> = ({ label, value }) => (
  <div style={styles.metaCard}>
    <p style={styles.metaLabel}>{label}</p>
    <p style={styles.metaValue}>{value}</p>
  </div>
);

/* ================= ESTILOS ================= */

const styles: Record<string, React.CSSProperties> = {
  bannerEyebrow: {
    margin: '0 0 8px 0',
    fontSize: '12px',
    fontWeight: 700,
    color: colors.primary,
    textTransform: 'uppercase',
    letterSpacing: '0.08em',
  },

  kpiGrid: {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))',
    gap: spacing.md,
    marginBottom: spacing.lg,
  },

  kpiCard: {
    background: colors.bgCard,
    border: `1px solid ${colors.border}`,
    borderRadius: radius.xl,
    padding: '18px',
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
  },

  kpiTitle: {
    margin: 0,
    color: colors.textSoft,
    fontSize: '14px',
    fontWeight: 600,
  },

  kpiValue: {
    margin: '8px 0 0 0',
    fontSize: '32px',
    fontWeight: 800,
    color: colors.textMain,
    letterSpacing: '-0.03em',
  },

  kpiIconWrap: {
    width: '48px',
    height: '48px',
    borderRadius: radius.lg,
    background: colors.primarySoft,
    color: colors.primaryDark,
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    fontSize: '22px',
    flexShrink: 0,
  },

  listWrap: {
    display: 'grid',
    gap: spacing.md,
  },

  emptyState: {
    background: colors.bgCard,
    borderRadius: radius.xl,
    border: `1px solid ${colors.border}`,
    padding: '28px',
    color: colors.textSoft,
    textAlign: 'center',
  },

  emptyIcon: {
    fontSize: '36px',
    marginBottom: spacing.sm,
  },

  emptyTitle: {
    margin: 0,
    color: colors.textMain,
    fontWeight: 800,
    fontSize: '20px',
  },

  emptyText: {
    margin: '10px 0 0 0',
    color: colors.textSoft,
    fontSize: '14px',
    lineHeight: 1.6,
  },

  requestCard: {
    background: colors.bgCard,
    borderRadius: radius.xl,
    border: `1px solid ${colors.border}`,
    padding: spacing.lg,
  },

  requestTop: {
    display: 'flex',
    justifyContent: 'space-between',
    gap: spacing.sm,
    alignItems: 'flex-start',
    marginBottom: '14px',
    flexWrap: 'wrap',
  },

  requestTitle: {
    margin: 0,
    fontSize: '22px',
    fontWeight: 800,
    color: colors.textMain,
    letterSpacing: '-0.02em',
  },

  requestSubtitle: {
    margin: '6px 0 0 0',
    color: colors.textSoft,
  },

  requestMeta: {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
    gap: spacing.sm,
    marginBottom: '18px',
  },

  metaCard: {
    background: colors.bgSoft,
    border: `1px solid ${colors.border}`,
    borderRadius: radius.lg,
    padding: '12px 14px',
  },

  metaLabel: {
    margin: 0,
    fontSize: '11px',
    fontWeight: 700,
    color: colors.textSoft,
    textTransform: 'uppercase',
    letterSpacing: '0.04em',
  },

  metaValue: {
    margin: '8px 0 0 0',
    fontSize: '14px',
    fontWeight: 700,
    color: colors.textMain,
  },

  subsectionTitle: {
    margin: '0 0 12px 0',
    fontSize: '16px',
    fontWeight: 800,
    color: colors.textMain,
  },

  chipsWrap: {
    display: 'flex',
    flexWrap: 'wrap',
    gap: '8px',
    marginBottom: spacing.md,
  },

  sociedadChip: {
    display: 'inline-flex',
    alignItems: 'center',
    padding: '7px 10px',
    borderRadius: radius.pill,
    background: colors.bgSoft,
    border: `1px solid ${colors.border}`,
    color: colors.textMain,
    fontSize: '12px',
    fontWeight: 700,
  },

  docsPreview: {
    display: 'grid',
    gap: spacing.xs,
    marginBottom: spacing.md,
  },

  docPreviewRow: {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.sm,
    padding: '12px 14px',
    borderRadius: radius.md,
    background: colors.bgSoft,
    border: '1px solid #eef2f7',
  },

  docPreviewName: {
    color: colors.textMain,
    fontWeight: 600,
  },

  overlay: {
    position: 'fixed',
    inset: 0,
    background: 'rgba(15, 23, 42, 0.42)',
    display: 'flex',
    justifyContent: 'center',
    alignItems: 'center',
    padding: '20px',
    zIndex: 1000,
  },

  modal: {
    width: '100%',
    maxWidth: '960px',
    background: colors.bgCard,
    borderRadius: radius.xxl,
    overflow: 'hidden',
    boxShadow: '0 25px 60px rgba(15, 23, 42, 0.24)',
  },

  modalEyebrow: {
    margin: '0 0 8px 0',
    fontSize: '12px',
    fontWeight: 700,
    color: colors.primary,
    textTransform: 'uppercase',
    letterSpacing: '0.08em',
  },

  modalHeader: {
    padding: '22px 24px',
    borderBottom: `1px solid ${colors.border}`,
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.sm,
    background: 'linear-gradient(135deg, #ffffff 0%, #f8fafc 100%)',
  },

  modalBody: {
    padding: spacing.xl,
    maxHeight: '78vh',
    overflowY: 'auto',
  },

  detailGrid: {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
    gap: '14px',
    marginBottom: spacing.xl,
  },

  infoCard: {
    background: colors.bgSoft,
    border: `1px solid ${colors.border}`,
    borderRadius: radius.lg,
    padding: '14px',
  },

  infoLabel: {
    margin: 0,
    fontSize: '12px',
    fontWeight: 700,
    color: colors.textSoft,
    textTransform: 'uppercase',
    letterSpacing: '0.04em',
  },

  infoValue: {
    margin: '8px 0 0 0',
    fontSize: '15px',
    fontWeight: 700,
    color: colors.textMain,
  },

  sectionTitle: {
    margin: '0 0 16px 0',
    fontSize: '20px',
    fontWeight: 800,
    color: colors.textMain,
    letterSpacing: '-0.02em',
  },

  observationsList: {
    display: 'grid',
    gap: spacing.sm,
    marginBottom: spacing.xl,
  },

  observationCard: {
    background: colors.warningSoft,
    border: '1px solid #fcd34d',
    borderRadius: radius.lg,
    padding: '14px',
  },

  observationTitle: {
    margin: 0,
    fontSize: '14px',
    fontWeight: 800,
    color: '#92400e',
  },

  observationText: {
    margin: '6px 0 0 0',
    fontSize: '13px',
    color: '#78350f',
    lineHeight: 1.5,
  },

  docsGrid: {
    display: 'grid',
    gap: spacing.md,
    marginBottom: '28px',
  },

  docCard: {
    border: `1px solid ${colors.border}`,
    borderRadius: radius.xl,
    padding: spacing.md,
    background: '#fafafa',
  },

  docTop: {
    display: 'flex',
    justifyContent: 'space-between',
    gap: spacing.sm,
    alignItems: 'flex-start',
    flexWrap: 'wrap',
  },

  docTitle: {
    margin: 0,
    fontWeight: 700,
    color: colors.textMain,
    fontSize: '15px',
  },

  docFileName: {
    margin: '4px 0 0 0',
    color: colors.textSoft,
    fontSize: '13px',
  },

  docMeta: {
    margin: '6px 0 0 0',
    color: colors.textSoft,
    fontSize: '12px',
    fontWeight: 600,
  },

  templateButton: {
    display: 'inline-flex',
    marginTop: '8px',
    color: colors.primary,
    fontWeight: 700,
    fontSize: '13px',
    textDecoration: 'none',
  },

  docActionsInline: {
    display: 'flex',
    gap: '10px',
    marginTop: '12px',
    flexWrap: 'wrap',
  },

  uploadHint: {
    margin: '8px 0 0 0',
    color: colors.textSoft,
    fontSize: '12px',
  },

  comment: {
    marginTop: '10px',
    fontSize: '13px',
    color: '#374151',
  },

  blockedPanel: {
    background: colors.warningSoft,
    border: '1px solid #fcd34d',
    borderRadius: radius.lg,
    padding: '14px',
    marginBottom: spacing.md,
    color: '#92400e',
  },

  blockedText: {
    margin: '6px 0 0 0',
    fontSize: '13px',
    lineHeight: 1.5,
    color: '#78350f',
  },

  prereqList: {
    margin: '10px 0 0 18px',
    padding: 0,
    fontSize: '13px',
    color: '#78350f',
    lineHeight: 1.6,
  },

  historyList: {
    paddingLeft: '20px',
    margin: 0,
  },

  historyItem: {
    marginBottom: '12px',
    color: colors.textMain,
    lineHeight: 1.5,
  },
};

export default MisSolicitudesProveedor;