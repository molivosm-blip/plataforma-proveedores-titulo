import React, {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from 'react';
import { useNavigate } from 'react-router-dom';

import { useAuth } from '../../../../config/useAuth';

import {
  InvitacionesService,
  type ProveedorInvitation,
} from '../../../../infrastructure/auth/api/invitacionesService';

import { InternoProveedoresService } from '../../../../infrastructure/auth/api/internoProveedoresService';

import {
  EmpresasService,
  type EmpresaOption,
} from '../../../../infrastructure/auth/api/empresasService';

import {
  type DocumentoProveedor,
  type Proveedor,
} from '../../../../utils/proveedorHelpers';

import { ui } from '../../../theme/commonStyles';
import { colors, radius, spacing } from '../../../theme/tokens';
import PageHero from '../../../components/common/PageHero';
import DeclaracionModal from '../../../components/proceso/DeclaracionModal';

/*
 * Esta declaración se completa y firma en el portal, no se sube como archivo.
 */
const DOCUMENTO_CONFLICTO_INTERES = 'Declaración Conflicto de Interés Solicitante';

import type { ApiError } from '../../../../utils/apiTypes';

const normalizeEmail = (email?: string | null): string =>
  (email || '').trim().toLowerCase();

const isAdminGlobalRole = (role?: string): boolean =>
  role === 'pm_admin' || role === 'admin_portal';

const canInviteProveedor = (
  role?: string,
  area?: string | null
): boolean => {
  if (isAdminGlobalRole(role)) return true;

  if (role === 'pm_user') return true;

  if (
    (role === 'admin_area' || role === 'usuario_area') &&
    area === 'pm'
  ) {
    return true;
  }

  return false;
};

const getStatusLabel = (
  status: ProveedorInvitation['status']
): string => {
  switch (status) {
    case 'used':
      return 'Usada';
    case 'revoked':
      return 'Revocada';
    default:
      return 'Pendiente';
  }
};

const getInvitationLink = (
  invite: ProveedorInvitation
): string => {
  if (invite.activationLink) {
    return invite.activationLink;
  }

  return `${window.location.origin}/proveedor/activar?token=${invite.token}`;
};

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
  const fileName = file.name.toLowerCase();

  const hasAllowedExtension = allowedExtensions.some(extension =>
    fileName.endsWith(extension)
  );

  if (
    !allowedTypes.includes(file.type) &&
    !hasAllowedExtension
  ) {
    return 'Formato no permitido. Solo se aceptan PDF, PNG, JPG o JPEG.';
  }

  if (file.size > 5 * 1024 * 1024) {
    return 'El archivo es demasiado grande. Máximo permitido: 5MB.';
  }

  return null;
};

const documentoEstaCargado = (
  documento: DocumentoProveedor
): boolean =>
  documento.estado === 'Subido' ||
  documento.estado === 'Aprobado';

const CrearProveedor: React.FC = () => {
  const navigate = useNavigate();
  const { user } = useAuth();

  const role = user?.role as string | undefined;
  const area = (user?.area || null) as string | null;

  const canCreate = canInviteProveedor(role, area);
  const canViewAllInvites = isAdminGlobalRole(role);

  const [email, setEmail] = useState('');
  const [empresaIds, setEmpresaIds] = useState<string[]>([]);
  const [razonSocial, setRazonSocial] = useState('');
  const [rut, setRut] = useState('');
  const [giro, setGiro] = useState('');

  const [tipo, setTipo] = useState<'Nacional' | 'Internacional'>('Nacional');

  const [tipoProveedorDetalle, setTipoProveedorDetalle] = useState('Proveedor de servicios');
  const [proveedorPreliminar, setProveedorPreliminar] = useState<Proveedor | null>(null);
  const [declaracionAbierta, setDeclaracionAbierta] = useState(false);
  const [invites, setInvites] = useState<ProveedorInvitation[]>([]);

  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [generatedLink, setGeneratedLink] = useState<string | null>(null);
  const [generatedEmail, setGeneratedEmail] = useState<string | null>(null);
  const [loadingInvites, setLoadingInvites] = useState(false);

  const [creatingSolicitud, setCreatingSolicitud] = useState(false);
  const [generatingInvite, setGeneratingInvite] = useState(false);

  const [uploadingDocId, setUploadingDocId] = useState<string | null>(null);

  const [revokingId, setRevokingId] = useState<string | null>(null);

  // Estados cargados dinámicamente
  const [empresas, setEmpresas] = useState<EmpresaOption[]>([]);
  const [loadingEmpresas, setLoadingEmpresas] = useState(false);
  const [empresasError, setEmpresasError] = useState<string | null>(null);

  const loadEmpresas = useCallback(async () => {
    setLoadingEmpresas(true);
    setEmpresasError(null);

    try {
      const data = await EmpresasService.getAll();

      setEmpresas(data);

      if (data.length === 0) {
        setEmpresasError(
          'No se encontraron empresas disponibles para seleccionar.'
        );
      }
    } catch (caught: unknown) {
      const err = caught as ApiError;
      console.error('Error cargando empresas:', err);

      const message =
        err?.response?.data?.message ||
        err?.response?.data?.error ||
        'No se pudieron cargar las empresas.';

      setEmpresas([]);
      setEmpresasError(message);
    } finally {
      setLoadingEmpresas(false);
    }
  }, []);

  const loadInvites = useCallback(async () => {
    setLoadingInvites(true);
    setError(null);

    try {
      const data = await InvitacionesService.getAll();
      const invitesData = Array.isArray(data) ? data : [];

      setInvites(invitesData);
      setError(null);
    } catch (caught: unknown) {
      const err = caught as ApiError;
      console.error('Error cargando invitaciones:', err);

      const status = err?.response?.status;
      const responseData = err?.response?.data;

      if (status === 404 || status === 204) {
        setInvites([]);
        setError(null);
        return;
      }

      if (
        responseData === null ||
        responseData === undefined ||
        Array.isArray(responseData?.data)
      ) {
        setInvites([]);
        setError(null);
        return;
      }

      const message =
        responseData?.message ||
        responseData?.error ||
        'No se pudieron cargar las invitaciones.';

      setInvites([]);
      setError(message);
    } finally {
      setLoadingInvites(false);
    }
  }, []);

  useEffect(() => {
    if (canCreate) {
      loadEmpresas();
      loadInvites();
    }
  }, [canCreate, loadEmpresas, loadInvites]);

  const documentosSolicitante = useMemo(() => {
    if (!proveedorPreliminar) {
      return [];
    }

    return proveedorPreliminar.documentos.filter(
      documento => {
        const responsable =
          documento.responsableCarga ||
          documento.responsable_carga;

        const esPrerrequisito =
          documento.esPrerrequisito ??
          documento.es_prerrequisito;

        return (
          responsable === 'pm' &&
          Boolean(esPrerrequisito)
        );
      }
    );
  }, [proveedorPreliminar]);

  const puedeGenerarInvitacion = useMemo(() => {
    if (!proveedorPreliminar) {
      return false;
    }

    if (documentosSolicitante.length === 0) {
      return false;
    }

    return documentosSolicitante.every(
      documentoEstaCargado
    );
  }, [proveedorPreliminar, documentosSolicitante]);

  const filteredInvites = useMemo(() => {
    const ownerEmail = normalizeEmail(user?.email);

    if (canViewAllInvites) {
      return invites;
    }

    if (!ownerEmail) {
      return [];
    }

    return invites.filter(
      invite =>
        normalizeEmail(invite.createdByEmail) ===
        ownerEmail
    );
  }, [invites, user?.email, canViewAllInvites]);

  const handleCopy = useCallback(
    async (text: string) => {
      try {
        await navigator.clipboard.writeText(text);

        setSuccess(
          'Link copiado al portapapeles.'
        );
        setError(null);
      } catch {
        setError(
          'No se pudo copiar el link automáticamente. Cópialo manualmente.'
        );
      }
    },
    []
  );

  const handleCancel = () => {
    navigate('/proveedores/seguimiento');
  };

  const handleBackDashboard = () => {
    navigate('/');
  };

  const handleEmpresaChange = (
    empresaId: string,
    checked: boolean
  ) => {
    setEmpresaIds(previousIds => {
      if (checked) {
        if (previousIds.includes(empresaId)) {
          return previousIds;
        }

        return [...previousIds, empresaId];
      }

      return previousIds.filter(
        currentId => currentId !== empresaId
      );
    });
  };

  const refreshProveedorPreliminar = async (
    proveedorId: string
  ) => {
    const actualizado =
      await InternoProveedoresService.getProveedorById(
        proveedorId
      );

    if (actualizado) {
      setProveedorPreliminar(actualizado);
      return actualizado;
    }

    return proveedorPreliminar;
  };

  const handleCrearSolicitud = async (
    event: React.FormEvent
  ) => {
    event.preventDefault();

    setError(null);
    setSuccess(null);
    setGeneratedLink(null);
    setGeneratedEmail(null);

    if (!canCreate) {
      setError(
        'No tienes permisos para crear solicitudes de proveedor.'
      );
      return;
    }

    const normalizedEmail = normalizeEmail(email);

    if (!normalizedEmail) {
      setError('Ingresa el correo del proveedor.');
      return;
    }

    if (
      !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
        normalizedEmail
      )
    ) {
      setError('Ingresa un correo válido.');
      return;
    }

    if (loadingEmpresas) {
      setError('Espera a que termine la carga de empresas.');
      return;
    }

    if (empresas.length === 0) {
      setError(
        empresasError ||
          'No hay empresas disponibles para crear la solicitud.'
      );
      return;
    }

    if (empresaIds.length === 0) {
      setError('Debes seleccionar al menos una empresa.');
      return;
    }

    setCreatingSolicitud(true);

    try {
      const proveedor =
        await InternoProveedoresService.crearSolicitudPreliminar(
          {
            emailProveedor: normalizedEmail,
            empresaIds,
            razonSocial:
              razonSocial.trim() || undefined,
            rut: rut.trim() || undefined,
            giro: giro.trim() || undefined,
            tipo,
            tipoProveedorDetalle,
          }
        );

      if (!proveedor) {
        setError(
          'No se pudo crear la solicitud preliminar.'
        );
        return;
      }

      setProveedorPreliminar(proveedor);

      setSuccess(
        'Solicitud preliminar creada. Ahora sube los documentos obligatorios del solicitante.'
      );

      window.scrollTo({
        top: 0,
        behavior: 'smooth',
      });
    } catch (caught: unknown) {
      const err = caught as ApiError;
      console.error(
        'Error creando solicitud preliminar:',
        err
      );

      const message =
        err?.response?.data?.message ||
        err?.response?.data?.error ||
        'No se pudo crear la solicitud preliminar.';

      setError(message);
    } finally {
      setCreatingSolicitud(false);
    }
  };

  const handleUploadDocumento = async (
    documento: DocumentoProveedor,
    file?: File
  ) => {
    if (!proveedorPreliminar) {
      return;
    }

    setError(null);
    setSuccess(null);

    const validationError =
      validateDocumentoFile(file);

    if (validationError || !file) {
      setError(
        validationError ||
          'Debes seleccionar un archivo.'
      );
      return;
    }

    setUploadingDocId(documento.id);

    try {
      await InternoProveedoresService.uploadDocumento(
        documento.id,
        file
      );

      await refreshProveedorPreliminar(
        proveedorPreliminar.id
      );

      setSuccess(
        `Documento "${documento.nombre}" cargado correctamente.`
      );
    } catch (caught: unknown) {
      const err = caught as ApiError;
      console.error(
        'Error subiendo documento:',
        err
      );

      const message =
        err?.response?.data?.message ||
        err?.response?.data?.error ||
        'No se pudo subir el documento.';

      setError(message);
    } finally {
      setUploadingDocId(null);
    }
  };

  const handleGenerarInvitacion = async () => {
    setError(null);
    setSuccess(null);
    setGeneratedLink(null);
    setGeneratedEmail(null);

    if (!proveedorPreliminar) {
      setError(
        'Primero debes crear la solicitud preliminar.'
      );
      return;
    }

    if (!puedeGenerarInvitacion) {
      setError(
        'Antes de invitar al proveedor debes cargar la Declaración de conflicto de interés del solicitante y la Autorización de jefatura.'
      );
      return;
    }

    const normalizedEmail = normalizeEmail(email);

    const pendingAlreadyExists = invites.some(
      invite =>
        normalizeEmail(invite.email) ===
          normalizedEmail &&
        invite.status === 'pending'
    );

    if (pendingAlreadyExists) {
      setError(
        'Ya existe una invitación pendiente para este correo.'
      );
      return;
    }

    setGeneratingInvite(true);

    try {
      const invite =
        await InvitacionesService.create(
          normalizedEmail,
          proveedorPreliminar.id
        );

      const link = getInvitationLink(invite);

      setGeneratedLink(link);
      setGeneratedEmail(normalizedEmail);

      setSuccess(
        'Invitación creada correctamente. Copia el link y envíaselo al proveedor.'
      );

      await loadInvites();

      window.scrollTo({
        top: 0,
        behavior: 'smooth',
      });
    } catch (caught: unknown) {
      const err = caught as ApiError;
      console.error(
        'Error creando invitación:',
        err
      );

      const message =
        err?.response?.data?.message ||
        err?.response?.data?.error ||
        'No se pudo crear la invitación.';

      setError(message);
    } finally {
      setGeneratingInvite(false);
    }
  };

  const handleRevoke = async (
    inviteId: string
  ) => {
    setError(null);
    setSuccess(null);
    setRevokingId(inviteId);

    try {
      await InvitacionesService.revoke(inviteId);

      setSuccess(
        'Invitación revocada correctamente.'
      );

      await loadInvites();
    } catch (caught: unknown) {
      const err = caught as ApiError;
      console.error(
        'Error revocando invitación:',
        err
      );

      const message =
        err?.response?.data?.message ||
        err?.response?.data?.error ||
        'No se pudo revocar la invitación.';

      setError(message);
    } finally {
      setRevokingId(null);
    }
  };

  if (!canCreate) {
    return (
      <div style={{ ...ui.page, ...styles.page }}>
        <div
          style={{
            ...ui.card,
            ...styles.accessCard,
          }}
        >
          <h1 style={ui.title}>
            Acceso no permitido
          </h1>

          <p style={ui.subtitle}>
            Solo usuarios autorizados del flujo PM
            pueden crear solicitudes e invitar
            proveedores.
          </p>

          <button
            style={ui.secondaryButton}
            onClick={handleBackDashboard}
            type="button"
          >
            Volver al dashboard
          </button>
        </div>
      </div>
    );
  }

  const mailtoHref =
    generatedLink && generatedEmail
      ? `mailto:${encodeURIComponent(
          generatedEmail
        )}?subject=${encodeURIComponent(
          'Activación Portal Proveedores'
        )}&body=${encodeURIComponent(
          `Hola,\n\nUsa este enlace para activar tu cuenta en el Portal de Proveedores:\n\n${generatedLink}\n\nSaludos.`
        )}`
      : '#';

  const formDisabled =
    creatingSolicitud ||
    Boolean(proveedorPreliminar);

  return (
    <div style={{ ...ui.page, ...styles.page }}>
      <PageHero
        title="Crear Solicitud de Proveedor"
        subtitle="Registra los datos iniciales, selecciona las empresas que habilitará el PM, gestiona los documentos del solicitante y emite el enlace de invitación."
        action={
          <button
            style={ui.secondaryButton}
            onClick={handleBackDashboard}
          >
            Volver
          </button>
        }
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

      <div
        style={{
          ...ui.card,
          ...styles.formCard,
        }}
      >
        <form onSubmit={handleCrearSolicitud}>
          <div style={styles.sectionBlock}>
  <div style={styles.sectionHeader}>
    <div>
      <h2 style={ui.sectionTitle}>
        1. Datos iniciales
      </h2>

      <p style={styles.sectionSubtitle}>
        Estos datos permiten crear la solicitud preliminar. Las empresas
        seleccionadas serán definidas por el PM y no podrán ser modificadas
        por el proveedor.
      </p>
    </div>
  </div>

  {/* DATOS GENERALES */}
  <div style={styles.gridTwo}>
    <div style={styles.formGroup}>
      <label style={ui.label}>
        Correo del proveedor *
      </label>

      <input
        type="email"
        value={email}
        onChange={event =>
          setEmail(event.target.value)
        }
        style={ui.input}
        placeholder="proveedor@empresa.cl"
        disabled={formDisabled}
      />
    </div>

    <div style={styles.formGroup}>
      <label style={ui.label}>
        Razón social
      </label>

      <input
        type="text"
        value={razonSocial}
        onChange={event =>
          setRazonSocial(event.target.value)
        }
        style={ui.input}
        placeholder="Opcional"
        disabled={formDisabled}
      />
    </div>

    <div style={styles.formGroup}>
      <label style={ui.label}>
        RUT
      </label>

      <input
        type="text"
        value={rut}
        onChange={event =>
          setRut(event.target.value)
        }
        style={ui.input}
        placeholder="Opcional"
        disabled={formDisabled}
      />
    </div>

    <div style={styles.formGroup}>
      <label style={ui.label}>
        Giro
      </label>

      <input
        type="text"
        value={giro}
        onChange={event =>
          setGiro(event.target.value)
        }
        style={ui.input}
        placeholder="Opcional"
        disabled={formDisabled}
      />
    </div>

    <div style={styles.formGroup}>
      <label style={ui.label}>
        Tipo *
      </label>

      <select
        value={tipo}
        onChange={event =>
          setTipo(
            event.target.value as
              | 'Nacional'
              | 'Internacional'
          )
        }
        style={ui.input}
        disabled={formDisabled}
      >
        <option value="Nacional">
          Nacional
        </option>

        <option value="Internacional">
          Internacional
        </option>
      </select>
    </div>

    <div style={styles.formGroup}>
      <label style={ui.label}>
        Tipo proveedor *
      </label>

      <select
        value={tipoProveedorDetalle}
        onChange={event =>
          setTipoProveedorDetalle(
            event.target.value
          )
        }
        style={ui.input}
        disabled={formDisabled}
      >
        <option value="Proveedor de servicios">
          Proveedor de servicios
        </option>

        <option value="Proveedor de productos nacional">
          Proveedor de productos nacional
        </option>

        <option value="Proveedor de productos internacional">
          Proveedor de productos internacional
        </option>
      </select>
    </div>
  </div>

  {/* EMPRESAS AL FINAL Y EN HORIZONTAL */}
  <div style={styles.empresasSection}>
    <div style={styles.empresasSectionHeader}>
      <div>
        <label style={ui.label}>
          Empresas a las que pertenecerá *
        </label>

        <p style={styles.empresasHelperText}>
          Selecciona una o más empresas para asociarlas a la solicitud.
        </p>
      </div>

      {empresaIds.length > 0 && (
        <span style={styles.selectionBadge}>
          {empresaIds.length}{' '}
          {empresaIds.length === 1
            ? 'empresa seleccionada'
            : 'empresas seleccionadas'}
        </span>
      )}
    </div>

    {loadingEmpresas ? (
      <div style={styles.multiSelectMessage}>
        Cargando empresas...
      </div>
    ) : empresasError ? (
      <div style={styles.multiSelectError}>
        {empresasError}
      </div>
    ) : empresas.length === 0 ? (
      <div style={styles.multiSelectMessage}>
        No hay empresas disponibles.
      </div>
    ) : (
      <div style={styles.multiSelectBox}>
        {empresas.map(empresa => {
          const empresaId = String(empresa.id);
          const checked =
            empresaIds.includes(empresaId);

          return (
            <label
              key={empresaId}
              style={{
                ...styles.checkboxRow,
                ...(checked
                  ? styles.checkboxRowSelected
                  : {}),
                opacity: formDisabled ? 0.65 : 1,
                cursor: formDisabled
                  ? 'not-allowed'
                  : 'pointer',
              }}
            >
              <input
                type="checkbox"
                checked={checked}
                disabled={formDisabled}
                onChange={event =>
                  handleEmpresaChange(
                    empresaId,
                    event.target.checked
                  )
                }
                style={styles.checkboxInput}
              />

              <span style={styles.checkboxLabel}>
                {empresa.nombre}
              </span>
            </label>
          );
        })}
      </div>
    )}
  </div>
</div>

          {!proveedorPreliminar && (
            <div style={styles.actions}>
              <button
                type="button"
                style={ui.secondaryButton}
                onClick={handleCancel}
                disabled={creatingSolicitud}
              >
                Cancelar
              </button>

              <button
                type="submit"
                style={{
                  ...ui.primaryButton,
                  opacity: creatingSolicitud
                    ? 0.7
                    : 1,
                  cursor: creatingSolicitud
                    ? 'not-allowed'
                    : 'pointer',
                }}
                disabled={creatingSolicitud}
              >
                {creatingSolicitud
                  ? 'Creando solicitud...'
                  : 'Crear solicitud preliminar'}
              </button>
            </div>
          )}
        </form>
      </div>

      {proveedorPreliminar && (
        <div
          style={{
            ...ui.card,
            ...styles.formCard,
            marginTop: spacing.lg,
          }}
        >
          <div style={styles.sectionHeader}>
            <div>
              <h2 style={ui.sectionTitle}>
                2. Cargar documentos obligatorios del solicitante
              </h2>

              <p style={styles.sectionSubtitle}>
                Completa y firma en el portal la Declaración de conflicto de interés, y sube la Autorización de jefatura. La autorización puede ser un correo, PDF, captura o documento equivalente.
              </p>
            </div>
          </div>

          {documentosSolicitante.length === 0 ? (
            <p style={styles.sectionSubtitle}>
              No se encontraron documentos obligatorios del solicitante.
            </p>
          ) : (
            <div style={styles.docsList}>
              {documentosSolicitante.map(
                documento => {
                  const cargado = documentoEstaCargado(
                    documento
                  );

                  return (
                    <div
                      key={documento.id}
                      style={styles.docCard}
                    >
                      <div>
                        <p style={styles.docTitle}>
                          {documento.nombre}
                        </p>

                        <p style={styles.docMeta}>
                          Estado:{' '}
                          <strong>
                            {documento.estado}
                          </strong>
                        </p>

                        {documento.archivoNombre && (
                          <p style={styles.docMeta}>
                            Archivo:{' '}
                            {
                              documento.archivoNombre
                            }
                          </p>
                        )}
                      </div>

                      <div style={styles.docRight}>
                        <span
                          style={{
                            ...styles.statusBadge,
                            ...(cargado
                              ? styles.statusBadgeOk
                              : styles.statusBadgePending),
                          }}
                        >
                          {cargado
                            ? 'Cargado'
                            : 'Pendiente'}
                        </span>

                        {documento.nombre ===
                        DOCUMENTO_CONFLICTO_INTERES ? (
                          documento.estado !== 'Aprobado' && (
                            <button
                              type="button"
                              style={ui.primaryButton}
                              onClick={() =>
                                setDeclaracionAbierta(true)
                              }
                            >
                              {cargado
                                ? 'Volver a firmar'
                                : 'Completar y firmar'}
                            </button>
                          )
                        ) : (
                          <input
                            type="file"
                            accept=".pdf,.png,.jpg,.jpeg"
                            disabled={
                              uploadingDocId ===
                              documento.id
                            }
                            onChange={event =>
                              handleUploadDocumento(
                                documento,
                                event.target.files?.[0]
                              )
                            }
                          />
                        )}
                      </div>
                    </div>
                  );
                }
              )}
            </div>
          )}

          <div
            style={{
              ...ui.infoCard,
              ...styles.requirementPanel,
            }}
          >
            <p style={styles.infoTitle}>
              Requisito para invitar
            </p>

            <p style={styles.infoSubtitle}>
              El botón de invitación se habilitará cuando ambos documentos del solicitante estén en estado{' '}
              <strong>Subido</strong> o{' '}
              <strong>Aprobado</strong>.
            </p>
          </div>
        </div>
      )}

      {proveedorPreliminar && declaracionAbierta && (
        <DeclaracionModal
          tipo="conflicto_interes"
          proveedorId={proveedorPreliminar.id}
          razonSocial={proveedorPreliminar.razonSocial}
          rut={proveedorPreliminar.rut}
          declaranteNombre={user?.displayName || user?.email || ''}
          onCerrar={() => setDeclaracionAbierta(false)}
          onFirmada={async () => {
            setDeclaracionAbierta(false);
            await refreshProveedorPreliminar(
              proveedorPreliminar.id
            );
            setSuccess(
              'Declaración de conflicto de interés firmada correctamente.'
            );
          }}
        />
      )}

      {proveedorPreliminar && (
        <div
          style={{
            ...ui.card,
            ...styles.formCard,
            marginTop: spacing.lg,
          }}
        >
          <div style={styles.sectionHeader}>
            <div>
              <h2 style={ui.sectionTitle}>
                3. Generar invitación
              </h2>

              <p style={styles.sectionSubtitle}>
                Una vez cargados y aprobados los documentos requeridos del solicitante, genera y comparte el enlace de acceso exclusivo para el proveedor.
              </p>
            </div>
          </div>

          {!generatedLink ? (
            <div>
              <button
                type="button"
                onClick={handleGenerarInvitacion}
                disabled={
                  !puedeGenerarInvitacion ||
                  generatingInvite
                }
                style={{
                  ...ui.primaryButton,
                  opacity:
                    !puedeGenerarInvitacion ||
                    generatingInvite
                      ? 0.5
                      : 1,
                  cursor:
                    !puedeGenerarInvitacion ||
                    generatingInvite
                      ? 'not-allowed'
                      : 'pointer',
                }}
              >
                {generatingInvite
                  ? 'Generando enlace...'
                  : 'Generar enlace de invitación'}
              </button>
            </div>
          ) : (
            <div
              style={{
                ...ui.infoCard,
                ...styles.infoPanel,
              }}
            >
              <div style={styles.infoPanelHeader}>
                <div style={styles.infoIcon}>🔑</div>

                <div>
                  <h3 style={styles.infoTitle}>
                    Enlace de activación listo
                  </h3>

                  <p style={styles.infoSubtitle}>
                    Comparte este enlace único con el proveedor para que complete su registro en el portal.
                  </p>
                </div>
              </div>

              <div
                style={{
                  padding: '12px',
                  background: '#f1f5f9',
                  borderRadius: radius.md,
                  border: '1px solid #cbd5e1',
                  fontFamily: 'monospace',
                  fontSize: '13px',
                  color: '#334155',
                  wordBreak: 'break-all',
                  marginBottom: spacing.md,
                }}
              >
                {generatedLink}
              </div>

              <div style={styles.linkActions}>
                <button
                  type="button"
                  style={ui.primaryButton}
                  onClick={() =>
                    handleCopy(generatedLink)
                  }
                >
                  Copiar enlace
                </button>

                <a
                  href={mailtoHref}
                  style={styles.mailButton}
                >
                  Enviar por correo
                </a>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Historial de Invitaciones */}
      <div
        style={{
          ...ui.card,
          ...styles.formCard,
          marginTop: spacing.xl,
        }}
      >
        <div style={styles.sectionHeader}>
          <div>
            <h2 style={ui.sectionTitle}>
              Invitaciones creadas recientemente
            </h2>

            <p style={styles.sectionSubtitle}>
              Lista de los últimos 20 enlaces de invitación generados.
            </p>
          </div>
        </div>

        {loadingInvites ? (
          <p style={styles.sectionSubtitle}>
            Cargando invitaciones...
          </p>
        ) : filteredInvites.length === 0 ? (
          <p style={styles.sectionSubtitle}>
            No se encontraron invitaciones creadas.
          </p>
        ) : (
          <div style={styles.invitesList}>
            {filteredInvites
              .slice(0, 20)
              .map(invite => {
                const link = getInvitationLink(invite);

                return (
                  <div
                    key={invite.id}
                    style={styles.inviteCard}
                  >
                    <div
                      style={{
                        minWidth: '260px',
                      }}
                    >
                      <div
                        style={styles.inviteEmail}
                      >
                        {invite.email}
                      </div>

                      <div
                        style={styles.inviteMeta}
                      >
                        {new Date(
                          invite.createdAt
                        ).toLocaleString()}{' '}
                        ·{' '}
                        {getStatusLabel(
                          invite.status
                        )}
                        {invite.proveedorId
                          ? ` · Solicitud ${invite.proveedorId}`
                          : ''}
                        {invite.createdByName &&
                        canViewAllInvites
                          ? ` · ${invite.createdByName}`
                          : ''}
                      </div>
                    </div>

                    <div
                      style={
                        styles.inviteActions
                      }
                    >
                      <button
                        type="button"
                        style={
                          ui.secondaryButton
                        }
                        onClick={() =>
                          handleCopy(link)
                        }
                      >
                        Copiar link
                      </button>

                      {invite.status ===
                        'pending' && (
                        <button
                          type="button"
                          style={{
                            ...styles.revokeButton,
                            opacity:
                              revokingId ===
                              invite.id
                                ? 0.7
                                : 1,
                            cursor:
                              revokingId ===
                              invite.id
                                ? 'not-allowed'
                                : 'pointer',
                          }}
                          onClick={() =>
                            handleRevoke(
                              invite.id
                            )
                          }
                          disabled={
                            revokingId ===
                            invite.id
                          }
                        >
                          {revokingId ===
                          invite.id
                            ? 'Revocando...'
                            : 'Revocar'}
                        </button>
                      )}
                    </div>
                  </div>
                );
              })}
          </div>
        )}
      </div>
    </div>
  );
};

const styles: Record<
  string,
  React.CSSProperties
> = {
  page: {
    background: colors.bgPage,
  },

  formCard: {
    maxWidth: '1080px',
  },

  accessCard: {
    maxWidth: '720px',
  },

  sectionBlock: {
    marginBottom: '28px',
  },

  sectionHeader: {
    marginBottom: spacing.md,
  },

  sectionSubtitle: {
    margin: 0,
    fontSize: '14px',
    color: colors.textSoft,
    lineHeight: 1.5,
  },

  gridTwo: {
    display: 'grid',
    gridTemplateColumns:
      'repeat(auto-fit, minmax(260px, 1fr))',
    gap: spacing.md,
  },

  formGroup: {
    display: 'flex',
    flexDirection: 'column',
    gap: spacing.xs,
  },

  multiSelectBox: {
    display: 'grid',
    gridTemplateColumns:
      'repeat(auto-fit, minmax(170px, 1fr))',
    gap: '10px',
    padding: '12px',
    minHeight: '64px',
    border: `1px solid ${colors.border}`,
    borderRadius: radius.lg,
    background: colors.bgSoft,
  },

  checkboxRow: {
    display: 'flex',
    alignItems: 'center',
    gap: '10px',
    padding: '10px 12px',
    border: `1px solid ${colors.border}`,
    borderRadius: radius.md,
    background: '#ffffff',
    color: colors.textMain,
    fontSize: '14px',
    fontWeight: 700,
  },

  checkboxRowSelected: {
    border: `1px solid ${colors.primary}`,
    background: colors.primarySoft,
  },

  checkboxInput: {
    width: '18px',
    height: '18px',
    flexShrink: 0,
    accentColor: colors.primary,
  },

  empresasSection: {
  marginTop: spacing.xl,
  paddingTop: spacing.lg,
  borderTop: `1px solid ${colors.border}`,
},

empresasSectionHeader: {
  display: 'flex',
  justifyContent: 'space-between',
  alignItems: 'flex-start',
  gap: spacing.md,
  marginBottom: spacing.sm,
  flexWrap: 'wrap',
},

empresasHelperText: {
  margin: '5px 0 0 0',
  color: colors.textSoft,
  fontSize: '12px',
  lineHeight: 1.5,
},

selectionBadge: {
  display: 'inline-flex',
  alignItems: 'center',
  padding: '6px 10px',
  borderRadius: radius.pill,
  background: colors.primarySoft,
  color: colors.primaryDark,
  fontSize: '12px',
  fontWeight: 700,
},

checkboxLabel: {
  lineHeight: 1.3,
  overflowWrap: 'anywhere',
},

multiSelectMessage: {
  width: '100%',
  padding: '14px',
  border: `1px solid ${colors.border}`,
  borderRadius: radius.md,
  background: colors.bgSoft,
  color: colors.textSoft,
  fontSize: '14px',
  boxSizing: 'border-box',
},

multiSelectError: {
  width: '100%',
  padding: '14px',
  border: `1px solid ${colors.danger}`,
  borderRadius: radius.md,
  background: colors.dangerSoft,
  color: '#991b1b',
  fontSize: '14px',
  fontWeight: 600,
  boxSizing: 'border-box',
},

  docsList: {
    display: 'grid',
    gap: spacing.sm,
  },

  docCard: {
    border: `1px solid ${colors.border}`,
    borderRadius: radius.lg,
    padding: spacing.md,
    background: '#ffffff',
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    gap: spacing.md,
    flexWrap: 'wrap',
  },

  docTitle: {
    margin: 0,
    fontWeight: 800,
    color: colors.textMain,
  },

  docMeta: {
    margin: '6px 0 0 0',
    color: colors.textSoft,
    fontSize: '13px',
  },

  docRight: {
    display: 'flex',
    flexDirection: 'column',
    gap: spacing.sm,
    alignItems: 'flex-end',
  },

  statusBadge: {
    padding: '6px 10px',
    borderRadius: radius.pill,
    fontSize: '12px',
    fontWeight: 800,
  },

  statusBadgeOk: {
    background: colors.successSoft,
    color: '#166534',
  },

  statusBadgePending: {
    background: '#e5e7eb',
    color: '#374151',
  },

  requirementPanel: {
    marginTop: spacing.md,
  },

  infoPanel: {
    marginTop: spacing.xs,
    background:
      'linear-gradient(135deg, #ffffff 0%, #f8fafc 100%)',
  },

  infoPanelHeader: {
    display: 'flex',
    alignItems: 'flex-start',
    gap: spacing.sm,
    marginBottom: '14px',
  },

  infoIcon: {
    width: '42px',
    height: '42px',
    borderRadius: radius.lg,
    background: colors.primarySoft,
    color: colors.primaryDark,
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    fontSize: '20px',
    flexShrink: 0,
  },

  infoTitle: {
    margin: 0,
    fontWeight: 800,
    color: colors.textMain,
    fontSize: '15px',
  },

  infoSubtitle: {
    margin: '4px 0 0 0',
    fontSize: '13px',
    color: colors.textSoft,
    lineHeight: 1.5,
  },

  linkActions: {
    display: 'flex',
    gap: spacing.sm,
    flexWrap: 'wrap',
  },

  mailButton: {
    ...ui.primaryButton,
    textDecoration: 'none',
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
  },

  actions: {
    marginTop: spacing.xl,
    display: 'flex',
    justifyContent: 'flex-end',
    gap: spacing.sm,
    flexWrap: 'wrap',
  },

  invitesList: {
    display: 'grid',
    gap: spacing.sm,
  },

  inviteCard: {
    border: `1px solid ${colors.border}`,
    borderRadius: radius.lg,
    padding: '14px',
    background: '#ffffff',
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.md,
    flexWrap: 'wrap',
  },

  inviteEmail: {
    fontWeight: 700,
    color: colors.textMain,
  },

  inviteMeta: {
    fontSize: '12px',
    color: colors.textSoft,
  },

  inviteActions: {
    display: 'flex',
    gap: spacing.xs,
    flexWrap: 'wrap',
  },

  revokeButton: {
    background: colors.dangerSoft,
    color: '#991b1b',
    border: 'none',
    padding: '10px 14px',
    borderRadius: radius.md,
    cursor: 'pointer',
    fontWeight: 700,
  },
};

export default CrearProveedor;