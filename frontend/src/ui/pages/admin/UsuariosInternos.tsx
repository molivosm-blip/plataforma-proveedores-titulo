import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Modal } from 'antd';
import { useAuth } from '../../../config/useAuth';
import { getRoleLabel } from '../../../config/roleMapping';
import type { UserRole } from '../../../domain/auth/AuthEntity';
import {
  UsuariosInternosService,
  type AreaPortal,
  type UsuarioInterno,
} from '../../../infrastructure/auth/api/usuariosInternosService';
import PageHero from '../../components/common/PageHero';
import { ui } from '../../theme/commonStyles';
import { colors, radius, spacing } from '../../theme/tokens';

/*
|--------------------------------------------------------------------------
| Administración de usuarios internos
|--------------------------------------------------------------------------
|
| Consume /admin/usuarios y /admin/areas del backend (MySQL).
| Los usuarios internos inician sesión con Microsoft Entra ID: el correo
| debe coincidir con el de su cuenta Microsoft. No se manejan contraseñas.
|--------------------------------------------------------------------------
*/

const ROLES_GLOBAL: UserRole[] = [
  'admin_portal',
  'pm_admin',
  'pm_user',
  'admin_area',
  'usuario_area',
  'auditor',
];

const ROLES_ADMIN_AREA: UserRole[] = ['usuario_area'];

const rolRequiereArea = (role: UserRole): boolean =>
  role === 'admin_area' || role === 'usuario_area';

const getApiError = (err: unknown, fallback: string): string => {
  const data = (
    err as { response?: { data?: { message?: string } } }
  )?.response?.data;
  return data?.message || fallback;
};

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

interface FormState {
  displayName: string;
  email: string;
  role: UserRole;
  area: string;
}

const EMPTY_FORM: FormState = {
  displayName: '',
  email: '',
  role: 'usuario_area',
  area: '',
};

const UsuariosInternos: React.FC = () => {
  const { user, isAreaAdmin } = useAuth();

  const [usuarios, setUsuarios] = useState<UsuarioInterno[]>([]);
  const [areas, setAreas] = useState<AreaPortal[]>([]);
  const [form, setForm] = useState<FormState>({
    ...EMPTY_FORM,
    area: isAreaAdmin ? user?.area ?? '' : '',
  });
  const [busqueda, setBusqueda] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const rolesDisponibles = isAreaAdmin ? ROLES_ADMIN_AREA : ROLES_GLOBAL;

  const areaName = useCallback(
    (code?: string | null): string =>
      !code
        ? '—'
        : areas.find(area => area.code === code)?.name ?? code,
    [areas]
  );

  const cargar = useCallback(async (): Promise<void> => {
    setLoading(true);
    setError(null);

    try {
      const [listaUsuarios, listaAreas] = await Promise.all([
        UsuariosInternosService.listar(),
        UsuariosInternosService.listarAreas(),
      ]);
      setUsuarios(listaUsuarios);
      setAreas(listaAreas);
    } catch (err) {
      setError(getApiError(err, 'No se pudieron cargar los usuarios.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void cargar();
  }, [cargar]);

  const visibles = useMemo(() => {
    const term = busqueda.trim().toLowerCase();

    if (!term) {
      return usuarios;
    }

    return usuarios.filter(u =>
      [u.displayName, u.email, getRoleLabel(u.role), areaName(u.area)]
        .join(' ')
        .toLowerCase()
        .includes(term)
    );
  }, [usuarios, busqueda, areaName]);

  const kpis = useMemo(
    () => ({
      total: usuarios.length,
      activos: usuarios.filter(u => u.active).length,
      admins: usuarios.filter(
        u => u.role === 'admin_portal' || u.role === 'pm_admin'
      ).length,
      areas: usuarios.filter(u => rolRequiereArea(u.role)).length,
    }),
    [usuarios]
  );

  const setField = <K extends keyof FormState>(
    key: K,
    value: FormState[K]
  ): void => setForm(prev => ({ ...prev, [key]: value }));

  const handleSubmit = async (
    event: React.FormEvent
  ): Promise<void> => {
    event.preventDefault();
    setError(null);
    setSuccess(null);

    const email = form.email.trim().toLowerCase();

    if (!form.displayName.trim() || !email) {
      setError('Completa el nombre y el correo.');
      return;
    }

    if (!EMAIL_REGEX.test(email)) {
      setError('Ingresa un correo válido.');
      return;
    }

    if (usuarios.some(u => u.email.toLowerCase() === email)) {
      setError('Ya existe un usuario con ese correo.');
      return;
    }

    if (rolRequiereArea(form.role) && !form.area) {
      setError('El área es obligatoria para este rol.');
      return;
    }

    setSaving(true);

    try {
      const area = rolRequiereArea(form.role)
        ? form.area
        : form.role === 'pm_user'
        ? 'pm'
        : null;

      await UsuariosInternosService.crear({
        email,
        displayName: form.displayName,
        role: form.role,
        area,
      });

      setSuccess(
        `Usuario creado. Deberá ingresar con la cuenta Microsoft ${email}.`
      );
      setForm({
        ...EMPTY_FORM,
        area: isAreaAdmin ? user?.area ?? '' : '',
      });
      await cargar();
    } catch (err) {
      setError(getApiError(err, 'No se pudo crear el usuario.'));
    } finally {
      setSaving(false);
    }
  };

  const confirmarDesactivar = (target: UsuarioInterno): void => {
    Modal.confirm({
      title: 'Desactivar usuario',
      content: `${target.displayName} (${target.email}) ya no podrá ingresar al portal. Su historial se conserva.`,
      okText: 'Desactivar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: async () => {
        setError(null);
        setSuccess(null);

        try {
          await UsuariosInternosService.desactivar(target.id);
          setSuccess('Usuario desactivado correctamente.');
          await cargar();
        } catch (err) {
          setError(
            getApiError(err, 'No se pudo desactivar el usuario.')
          );
        }
      },
    });
  };

  return (
    <div style={ui.page}>
      <PageHero
        eyebrow="Administración"
        title="Usuarios internos"
        subtitle="Gestiona quién puede ingresar con Microsoft Entra ID y qué rol y área tiene en el portal."
      />

      <div style={styles.kpiGrid}>
        <Kpi title="Usuarios" value={kpis.total} icon="👥" />
        <Kpi title="Activos" value={kpis.activos} icon="✅" />
        <Kpi title="Administradores" value={kpis.admins} icon="🛡️" />
        <Kpi title="Usuarios de área" value={kpis.areas} icon="🏷️" />
      </div>

      {error && (
        <div
          role="alert"
          style={{ ...ui.errorBox, marginBottom: spacing.md }}
        >
          {error}
        </div>
      )}
      {success && (
        <div
          role="status"
          style={{ ...ui.successBox, marginBottom: spacing.md }}
        >
          {success}
        </div>
      )}

      <div style={styles.grid}>
        <form
          onSubmit={handleSubmit}
          style={ui.card}
          aria-label="Crear usuario interno"
        >
          <h2 style={ui.sectionTitle}>Nuevo usuario</h2>
          <p style={styles.help}>
            El correo debe ser el de su cuenta Microsoft (por ejemplo,
            usuario@tuorganizacion.onmicrosoft.com).
          </p>

          <label style={ui.label} htmlFor="u-nombre">
            Nombre *
          </label>
          <input
            id="u-nombre"
            style={styles.field}
            value={form.displayName}
            onChange={e => setField('displayName', e.target.value)}
            placeholder="Ej: Juan Pérez"
          />

          <label style={ui.label} htmlFor="u-email">
            Correo Microsoft *
          </label>
          <input
            id="u-email"
            type="email"
            style={styles.field}
            value={form.email}
            onChange={e => setField('email', e.target.value)}
            placeholder="usuario@dominio.com"
          />

          <label style={ui.label} htmlFor="u-rol">
            Rol *
          </label>
          <select
            id="u-rol"
            style={styles.field}
            value={form.role}
            onChange={e => setField('role', e.target.value as UserRole)}
          >
            {rolesDisponibles.map(role => (
              <option key={role} value={role}>
                {getRoleLabel(role)}
              </option>
            ))}
          </select>

          {rolRequiereArea(form.role) && (
            <>
              <label style={ui.label} htmlFor="u-area">
                Área *
              </label>
              <select
                id="u-area"
                style={styles.field}
                value={form.area}
                disabled={isAreaAdmin}
                onChange={e => setField('area', e.target.value)}
              >
                <option value="">Selecciona un área</option>
                {areas.map(area => (
                  <option key={area.code} value={area.code}>
                    {area.name}
                  </option>
                ))}
              </select>
            </>
          )}

          <button
            type="submit"
            style={{
              ...ui.primaryButton,
              marginTop: spacing.md,
              opacity: saving ? 0.7 : 1,
            }}
            disabled={saving}
          >
            {saving ? 'Creando...' : 'Crear usuario'}
          </button>
        </form>

        <div style={ui.card}>
          <div style={styles.listHeader}>
            <h2 style={{ ...ui.sectionTitle, margin: 0 }}>
              Usuarios registrados
            </h2>
            <input
              type="search"
              aria-label="Buscar usuario"
              style={{ ...styles.field, maxWidth: 240, margin: 0 }}
              value={busqueda}
              onChange={e => setBusqueda(e.target.value)}
              placeholder="Buscar por nombre, correo o rol"
            />
          </div>

          {loading ? (
            <p style={styles.help}>Cargando usuarios...</p>
          ) : visibles.length === 0 ? (
            <div style={styles.empty}>
              <div style={{ fontSize: 32 }}>📭</div>
              <strong>No hay usuarios para mostrar</strong>
            </div>
          ) : (
            <div style={{ display: 'grid', gap: spacing.sm }}>
              {visibles.map(u => (
                <div
                  key={u.id}
                  style={{
                    ...styles.userCard,
                    opacity: u.active ? 1 : 0.55,
                  }}
                >
                  <div style={styles.avatar} aria-hidden="true">
                    {u.displayName.charAt(0).toUpperCase()}
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <p style={styles.userName}>{u.displayName}</p>
                    <p style={styles.userEmail}>{u.email}</p>
                    <div style={{ marginTop: 6, display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                      <span style={styles.badge}>{getRoleLabel(u.role)}</span>
                      {u.area && (
                        <span style={styles.badgeSoft}>
                          {areaName(u.area)}
                        </span>
                      )}
                      {!u.active && (
                        <span style={styles.badgeDanger}>Inactivo</span>
                      )}
                    </div>
                  </div>
                  {u.active && u.id !== user?.id && (
                    <button
                      type="button"
                      style={styles.dangerButton}
                      onClick={() => confirmarDesactivar(u)}
                    >
                      Desactivar
                    </button>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

const Kpi: React.FC<{ title: string; value: number; icon: string }> = ({
  title,
  value,
  icon,
}) => (
  <div style={styles.kpiCard}>
    <div>
      <p style={styles.kpiTitle}>{title}</p>
      <h3 style={styles.kpiValue}>{value}</h3>
    </div>
    <div style={styles.kpiIcon} aria-hidden="true">
      {icon}
    </div>
  </div>
);

const styles: Record<string, React.CSSProperties> = {
  kpiGrid: {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
    gap: spacing.md,
    marginBottom: spacing.lg,
  },
  kpiCard: {
    background: colors.bgCard,
    border: `1px solid ${colors.border}`,
    borderRadius: radius.xl,
    padding: 18,
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  kpiTitle: { margin: 0, fontSize: 14, color: colors.textSoft, fontWeight: 600 },
  kpiValue: { margin: '8px 0 0', fontSize: 30, fontWeight: 800, color: colors.textMain },
  kpiIcon: {
    width: 46,
    height: 46,
    borderRadius: radius.lg,
    background: colors.primarySoft,
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    fontSize: 22,
  },
  grid: {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(340px, 1fr))',
    gap: spacing.lg,
    alignItems: 'start',
  },
  help: { margin: '0 0 16px', fontSize: 13, color: colors.textSoft, lineHeight: 1.5 },
  field: {
    width: '100%',
    boxSizing: 'border-box',
    padding: '11px 12px',
    marginBottom: 14,
    borderRadius: radius.md,
    border: `1px solid ${colors.borderStrong}`,
    background: colors.bgCard,
    color: colors.textMain,
    fontSize: 14,
  },
  listHeader: {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.sm,
    flexWrap: 'wrap',
    marginBottom: spacing.md,
  },
  empty: {
    background: colors.bgSoft,
    border: `1px solid ${colors.border}`,
    borderRadius: radius.xl,
    padding: spacing.xl,
    textAlign: 'center',
  },
  userCard: {
    display: 'flex',
    alignItems: 'center',
    gap: spacing.md,
    border: `1px solid ${colors.border}`,
    borderRadius: radius.xl,
    padding: spacing.md,
    background: '#fafafa',
  },
  avatar: {
    width: 44,
    height: 44,
    flex: '0 0 44px',
    borderRadius: radius.pill,
    background: `linear-gradient(135deg, ${colors.primary} 0%, ${colors.primaryDark} 100%)`,
    color: '#fff',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    fontWeight: 800,
  },
  userName: { margin: 0, fontWeight: 800, color: colors.textMain, fontSize: 15 },
  userEmail: { margin: '2px 0 0', fontSize: 13, color: colors.textSoft, wordBreak: 'break-all' },
  badge: {
    padding: '3px 10px',
    borderRadius: radius.pill,
    background: colors.primarySoft,
    color: colors.primaryDark,
    fontSize: 11,
    fontWeight: 700,
  },
  badgeSoft: {
    padding: '3px 10px',
    borderRadius: radius.pill,
    background: colors.infoSoft,
    color: '#1d4ed8',
    fontSize: 11,
    fontWeight: 700,
  },
  badgeDanger: {
    padding: '3px 10px',
    borderRadius: radius.pill,
    background: colors.dangerSoft,
    color: '#991b1b',
    fontSize: 11,
    fontWeight: 700,
  },
  dangerButton: {
    background: colors.dangerSoft,
    color: '#991b1b',
    border: 'none',
    padding: '9px 12px',
    borderRadius: radius.md,
    cursor: 'pointer',
    fontWeight: 700,
    fontSize: 12,
  },
};

export default UsuariosInternos;
