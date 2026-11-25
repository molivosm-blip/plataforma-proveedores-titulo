import React, { useMemo, useState } from 'react';
import {
  WORKFLOW_AREAS,
  WORKFLOW_AREA_LABELS,
  type Proveedor,
  type ValidacionStep,
  type WorkflowArea,
  type WorkflowStatus,
} from '../../../../utils/proveedorHelpers';
import { colors, radius, spacing } from '../../../theme/tokens';

/*
|--------------------------------------------------------------------------
| Validaciones por área (workflow)
|--------------------------------------------------------------------------
|
| Muestra el avance del proceso en las 5 áreas y permite aprobar, observar
| o rechazar solo en las áreas que el usuario puede gestionar. Las reglas
| replican AreaPermissionService del backend (el backend siempre valida):
|
| - admin_portal / pm_admin: todas las áreas, y pueden reabrir.
| - pm_user: solo el área PM de los proveedores que creó.
| - admin_area / usuario_area: solo su propia área.
| - Una validación aprobada solo la modifica un administrador global.
|--------------------------------------------------------------------------
*/

type EstadoEditable = Exclude<WorkflowStatus, 'Pendiente'>;

const OPCIONES: EstadoEditable[] = [
  'En revisión',
  'Observado',
  'Aprobado',
  'Rechazado',
];

interface Props {
  proveedor: Proveedor;
  role?: string;
  userArea?: string | null;
  userId?: string;
  onActualizar: (
    area: WorkflowArea,
    status: WorkflowStatus,
    comments?: string
  ) => Promise<void>;
  onReabrir: (
    area: WorkflowArea,
    comments?: string
  ) => Promise<void>;
}

const isAdminGlobal = (role?: string): boolean =>
  role === 'pm_admin' || role === 'admin_portal';

const getApiError = (err: unknown, fallback: string): string => {
  const data = (
    err as { response?: { data?: { message?: string } } }
  )?.response?.data;
  return data?.message || fallback;
};

const statusStyle = (
  status: WorkflowStatus
): { bg: string; fg: string; dot: string } => {
  switch (status) {
    case 'Aprobado':
      return { bg: colors.successSoft, fg: '#166534', dot: colors.success };
    case 'Rechazado':
      return { bg: colors.dangerSoft, fg: '#991b1b', dot: colors.danger };
    case 'Observado':
      return { bg: colors.warningSoft, fg: '#92400e', dot: colors.warning };
    case 'En revisión':
      return { bg: colors.infoSoft, fg: '#1d4ed8', dot: colors.info };
    default:
      return { bg: '#e5e7eb', fg: '#374151', dot: '#9ca3af' };
  }
};

const formatFecha = (value?: string | null): string => {
  if (!value) return '—';
  const date = new Date(value.replace(' ', 'T'));
  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleString('es-CL');
};

const ValidacionesPanel: React.FC<Props> = ({
  proveedor,
  role,
  userArea,
  userId,
  onActualizar,
  onReabrir,
}) => {
  const [editando, setEditando] = useState<WorkflowArea | null>(null);
  const [status, setStatus] = useState<EstadoEditable>('Aprobado');
  const [comentario, setComentario] = useState('');
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [ok, setOk] = useState<string | null>(null);

  const admin = isAdminGlobal(role);

  /* Siempre se muestran las 5 áreas, aunque aún no exista la fila. */
  const pasos = useMemo(
    () =>
      WORKFLOW_AREAS.map<ValidacionStep>(area => {
        const existente = proveedor.validaciones?.find(
          v => v.area === area
        );
        return (
          existente ?? {
            area,
            areaName: WORKFLOW_AREA_LABELS[area],
            status: 'Pendiente',
          }
        );
      }),
    [proveedor.validaciones]
  );

  const aprobadas = pasos.filter(p => p.status === 'Aprobado').length;
  const progreso = Math.round((aprobadas / pasos.length) * 100);

  const puedeGestionar = (paso: ValidacionStep): boolean => {
    if (admin) return true;

    if (paso.status === 'Aprobado') return false;

    if (role === 'pm_user') {
      return (
        paso.area === 'pm' &&
        String(proveedor.createdById ?? '') === String(userId ?? '')
      );
    }

    if (role === 'admin_area' || role === 'usuario_area') {
      return !!userArea && paso.area === userArea;
    }

    return false;
  };

  const abrir = (paso: ValidacionStep): void => {
    setError(null);
    setOk(null);
    setEditando(paso.area);
    setStatus(
      paso.status === 'Pendiente' || paso.status === 'En revisión'
        ? 'Aprobado'
        : (paso.status as EstadoEditable)
    );
    setComentario(paso.comments ?? '');
  };

  const cancelar = (): void => {
    setEditando(null);
    setComentario('');
  };

  const guardar = async (area: WorkflowArea): Promise<void> => {
    setError(null);
    setOk(null);

    if (
      (status === 'Observado' || status === 'Rechazado') &&
      !comentario.trim()
    ) {
      setError(
        `Indica el motivo para marcar la validación como ${status.toLowerCase()}.`
      );
      return;
    }

    setGuardando(true);

    try {
      await onActualizar(area, status, comentario.trim() || undefined);
      setOk(
        `${WORKFLOW_AREA_LABELS[area]}: validación actualizada a ${status}.`
      );
      cancelar();
    } catch (err) {
      setError(getApiError(err, 'No se pudo actualizar la validación.'));
    } finally {
      setGuardando(false);
    }
  };

  const reabrir = async (area: WorkflowArea): Promise<void> => {
    setError(null);
    setOk(null);
    setGuardando(true);

    try {
      await onReabrir(area);
      setOk(`${WORKFLOW_AREA_LABELS[area]}: validación reabierta.`);
    } catch (err) {
      setError(getApiError(err, 'No se pudo reabrir la validación.'));
    } finally {
      setGuardando(false);
    }
  };

  return (
    <div>
      <div style={styles.progressHeader}>
        <div>
          <p style={styles.progressLabel}>Avance de validaciones</p>
          <p style={styles.progressValue}>
            {aprobadas} de {pasos.length} áreas aprobadas
          </p>
        </div>
        <strong style={{ fontSize: 22, color: colors.primaryDark }}>
          {progreso}%
        </strong>
      </div>

      <div
        style={styles.progressTrack}
        role="progressbar"
        aria-valuenow={progreso}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label="Avance de validaciones"
      >
        <div style={{ ...styles.progressFill, width: `${progreso}%` }} />
      </div>

      {error && (
        <div role="alert" style={styles.errorBox}>
          {error}
        </div>
      )}
      {ok && (
        <div role="status" style={styles.okBox}>
          {ok}
        </div>
      )}

      <ol style={styles.list}>
        {pasos.map((paso, index) => {
          const tono = statusStyle(paso.status);
          const gestionable = puedeGestionar(paso);
          const abierto = editando === paso.area;

          return (
            <li key={paso.area} style={styles.item}>
              <div
                style={{ ...styles.step, background: tono.dot }}
                aria-hidden="true"
              >
                {index + 1}
              </div>

              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={styles.itemHeader}>
                  <strong style={{ color: colors.textMain }}>
                    {WORKFLOW_AREA_LABELS[paso.area]}
                  </strong>
                  <span
                    style={{
                      ...styles.badge,
                      background: tono.bg,
                      color: tono.fg,
                    }}
                  >
                    {paso.status}
                  </span>
                </div>

                {paso.validatedAt && (
                  <p style={styles.meta}>
                    Resuelta el {formatFecha(paso.validatedAt)}
                  </p>
                )}
                {paso.comments && (
                  <p style={styles.comment}>
                    <strong>Comentario:</strong> {paso.comments}
                  </p>
                )}

                {abierto ? (
                  <div style={styles.editor}>
                    <label
                      style={styles.editorLabel}
                      htmlFor={`val-status-${paso.area}`}
                    >
                      Resultado
                    </label>
                    <select
                      id={`val-status-${paso.area}`}
                      style={styles.input}
                      value={status}
                      onChange={e =>
                        setStatus(e.target.value as EstadoEditable)
                      }
                    >
                      {OPCIONES.map(op => (
                        <option key={op} value={op}>
                          {op}
                        </option>
                      ))}
                    </select>

                    <label
                      style={styles.editorLabel}
                      htmlFor={`val-comment-${paso.area}`}
                    >
                      Comentario
                      {status === 'Observado' || status === 'Rechazado'
                        ? ' (obligatorio)'
                        : ' (opcional)'}
                    </label>
                    <textarea
                      id={`val-comment-${paso.area}`}
                      style={{ ...styles.input, minHeight: 72 }}
                      value={comentario}
                      onChange={e => setComentario(e.target.value)}
                      placeholder="Describe el motivo o las observaciones"
                    />

                    <div style={styles.actions}>
                      <button
                        type="button"
                        style={styles.primary}
                        disabled={guardando}
                        onClick={() => void guardar(paso.area)}
                      >
                        {guardando ? 'Guardando...' : 'Guardar'}
                      </button>
                      <button
                        type="button"
                        style={styles.secondary}
                        disabled={guardando}
                        onClick={cancelar}
                      >
                        Cancelar
                      </button>
                    </div>
                  </div>
                ) : (
                  gestionable && (
                    <div style={styles.actions}>
                      <button
                        type="button"
                        style={styles.primary}
                        disabled={guardando}
                        onClick={() => abrir(paso)}
                      >
                        Gestionar validación
                      </button>
                      {admin &&
                        (paso.status === 'Aprobado' ||
                          paso.status === 'Rechazado' ||
                          paso.status === 'Observado') && (
                          <button
                            type="button"
                            style={styles.secondary}
                            disabled={guardando}
                            onClick={() => void reabrir(paso.area)}
                          >
                            Reabrir
                          </button>
                        )}
                    </div>
                  )
                )}
              </div>
            </li>
          );
        })}
      </ol>

      <p style={styles.footnote}>
        Solo puedes gestionar las áreas que corresponden a tu rol. Cada
        cambio queda registrado en el historial del proveedor.
      </p>
    </div>
  );
};

const styles: Record<string, React.CSSProperties> = {
  progressHeader: {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: spacing.xs,
  },
  progressLabel: { margin: 0, fontSize: 12, fontWeight: 700, color: colors.textSoft, textTransform: 'uppercase', letterSpacing: '0.04em' },
  progressValue: { margin: '4px 0 0', fontWeight: 700, color: colors.textMain },
  progressTrack: {
    height: 10,
    borderRadius: radius.pill,
    background: '#e5e7eb',
    overflow: 'hidden',
    marginBottom: spacing.md,
  },
  progressFill: {
    height: '100%',
    background: `linear-gradient(90deg, ${colors.primary}, ${colors.success})`,
    transition: 'width 0.4s ease',
  },
  errorBox: {
    background: colors.dangerSoft,
    color: '#991b1b',
    padding: '10px 14px',
    borderRadius: radius.md,
    marginBottom: spacing.sm,
    fontSize: 14,
  },
  okBox: {
    background: colors.successSoft,
    color: '#166534',
    padding: '10px 14px',
    borderRadius: radius.md,
    marginBottom: spacing.sm,
    fontSize: 14,
  },
  list: { listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: spacing.sm },
  item: {
    display: 'flex',
    gap: spacing.sm,
    padding: spacing.md,
    border: `1px solid ${colors.border}`,
    borderRadius: radius.xl,
    background: '#fafafa',
  },
  step: {
    width: 30,
    height: 30,
    flex: '0 0 30px',
    borderRadius: '50%',
    color: '#fff',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    fontWeight: 800,
    fontSize: 13,
  },
  itemHeader: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: spacing.xs, flexWrap: 'wrap' },
  badge: { padding: '4px 10px', borderRadius: radius.pill, fontSize: 12, fontWeight: 700 },
  meta: { margin: '6px 0 0', fontSize: 12, color: colors.textSoft },
  comment: { margin: '6px 0 0', fontSize: 13, color: '#374151' },
  editor: { marginTop: spacing.sm, display: 'grid', gap: 6 },
  editorLabel: { fontSize: 12, fontWeight: 700, color: colors.textSoft },
  input: {
    width: '100%',
    boxSizing: 'border-box',
    padding: '10px 12px',
    borderRadius: radius.md,
    border: `1px solid ${colors.borderStrong}`,
    background: '#fff',
    fontSize: 14,
    fontFamily: 'inherit',
  },
  actions: { display: 'flex', gap: spacing.xs, flexWrap: 'wrap', marginTop: spacing.sm },
  primary: {
    background: colors.primarySoft,
    color: '#4338ca',
    border: 'none',
    padding: '9px 14px',
    borderRadius: radius.md,
    cursor: 'pointer',
    fontWeight: 700,
    fontSize: 13,
  },
  secondary: {
    background: '#f3f4f6',
    color: colors.textMain,
    border: 'none',
    padding: '9px 14px',
    borderRadius: radius.md,
    cursor: 'pointer',
    fontWeight: 700,
    fontSize: 13,
  },
  footnote: { margin: `${spacing.md} 0 0`, fontSize: 12, color: colors.textSoft },
};

export default ValidacionesPanel;
