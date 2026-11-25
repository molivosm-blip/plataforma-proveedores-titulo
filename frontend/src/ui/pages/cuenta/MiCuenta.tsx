import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';

import { useAuth } from '../../../config/useAuth';
import { clearSidebarStorage } from '../../../utils/logoutUtils';
import PageHero from '../../components/common/PageHero';
import { ui } from '../../theme/commonStyles';
import { colors, radius, spacing } from '../../theme/tokens';
import {
  CuentaService,
  mensajeErrorCuenta,
  type PerfilCuenta,
  type PreferenciaAviso,
} from '../../../infrastructure/auth/api/cuentaService';
import {
  guardarPreferencias,
  usePreferenciasUI,
  type ModoMenu,
  type Tema,
} from '../../preferencias/preferenciasUI';

/*
|--------------------------------------------------------------------------
| Mi cuenta
|--------------------------------------------------------------------------
|
| Perfil, contraseña y sesión, avisos por correo y apariencia.
| "Mi Perfil" y "Configuración" del menú de usuario abren esta página.
|
*/

type Seccion = 'perfil' | 'seguridad' | 'notificaciones' | 'apariencia';

const SECCIONES: { id: Seccion; etiqueta: string }[] = [
  { id: 'perfil', etiqueta: 'Mi perfil' },
  { id: 'seguridad', etiqueta: 'Contraseña y sesión' },
  { id: 'notificaciones', etiqueta: 'Notificaciones' },
  { id: 'apariencia', etiqueta: 'Apariencia' },
];

const ROLES: Record<string, { nombre: string; descripcion: string }> = {
  proveedor: {
    nombre: 'Proveedor',
    descripcion: 'Completa su solicitud, sube los documentos y firma las declaraciones.',
  },
  pm_admin: {
    nombre: 'Product Manager (administrador)',
    descripcion: 'Invita proveedores, hace seguimiento de todas las solicitudes y administra usuarios.',
  },
  pm_user: {
    nombre: 'Product Manager',
    descripcion: 'Invita proveedores y hace seguimiento de sus solicitudes.',
  },
  admin_portal: {
    nombre: 'Administrador del portal',
    descripcion: 'Ve todo el proceso y administra los usuarios internos.',
  },
  admin_area: {
    nombre: 'Administrador de área',
    descripcion: 'Valida las solicitudes de su área y administra a los usuarios de ella.',
  },
  usuario_area: {
    nombre: 'Usuario de área',
    descripcion: 'Valida las solicitudes que corresponden a su área.',
  },
};

const AREAS: Record<string, string> = {
  pm: 'Product Manager',
  contraloria: 'Contraloría',
  legal_crediticia: 'Legal Crediticia',
  legal_judicial: 'Legal Judicial',
  datos_maestros: 'Datos Maestros',
};

const formatearFecha = (valor?: string | null): string => {
  if (!valor) return 'Sin registro';

  const fecha = new Date(valor.replace(' ', 'T'));

  if (Number.isNaN(fecha.getTime())) return valor;

  return fecha.toLocaleString('es-CL', { dateStyle: 'long', timeStyle: 'short' });
};

const styles: Record<string, React.CSSProperties> = {
  page: { ...ui.page },
  layout: { display: 'grid', gridTemplateColumns: 'minmax(180px, 230px) 1fr', gap: spacing.lg, alignItems: 'start' },
  nav: { ...ui.card, padding: spacing.sm, display: 'flex', flexDirection: 'column', gap: '4px' },
  navItem: {
    textAlign: 'left',
    padding: '11px 14px',
    border: 'none',
    background: 'transparent',
    borderRadius: radius.md,
    fontSize: '14px',
    fontWeight: 600,
    color: colors.textSoft,
    cursor: 'pointer',
  },
  navItemActive: { background: colors.primarySoft, color: colors.primaryDark },
  card: { ...ui.card },
  h2: { ...ui.sectionTitle, margin: '0 0 6px 0' },
  help: { margin: '0 0 18px 0', color: colors.textSoft, fontSize: '14px', lineHeight: 1.6 },
  fila: {
    display: 'flex',
    justifyContent: 'space-between',
    gap: spacing.md,
    padding: '12px 0',
    borderBottom: `1px solid ${colors.border}`,
    fontSize: '14px',
    flexWrap: 'wrap',
  },
  filaEtiqueta: { color: colors.textSoft, fontWeight: 600 },
  filaValor: { color: colors.textMain, fontWeight: 600, textAlign: 'right' },
  avatar: {
    width: '64px',
    height: '64px',
    borderRadius: '50%',
    background: 'linear-gradient(135deg, #3b82f6, #8b5cf6)',
    color: '#fff',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    fontSize: '26px',
    fontWeight: 700,
    flexShrink: 0,
  },
  cabeceraPerfil: { display: 'flex', alignItems: 'center', gap: spacing.md, marginBottom: spacing.md },
  subcard: { ...ui.infoCard, marginTop: spacing.md },
  campo: { marginBottom: spacing.md },
  label: { ...ui.label, display: 'block', marginBottom: '6px' },
  input: { ...ui.input },
  requisitos: { margin: '8px 0 0 0', padding: 0, listStyle: 'none', fontSize: '13px', lineHeight: 1.8 },
  acciones: { display: 'flex', gap: spacing.sm, alignItems: 'center', flexWrap: 'wrap' },
  boton: { ...ui.primaryButton },
  botonSecundario: { ...ui.secondaryButton },
  botonPeligro: {
    background: '#fff',
    color: colors.danger,
    border: `1px solid #fecaca`,
    padding: '12px 18px',
    borderRadius: radius.md,
    cursor: 'pointer',
    fontWeight: 700,
  },
  aviso: { margin: '0 0 14px 0' },
  opcion: {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.md,
    padding: '16px 0',
    borderBottom: `1px solid ${colors.border}`,
  },
  opcionTitulo: { margin: 0, fontSize: '15px', fontWeight: 700, color: colors.textMain },
  opcionDescripcion: { margin: '4px 0 0 0', fontSize: '13px', color: colors.textSoft, lineHeight: 1.5 },
  tarjetas: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: spacing.sm },
  tarjeta: {
    textAlign: 'left',
    padding: '14px',
    borderRadius: radius.lg,
    borderWidth: '2px',
    borderStyle: 'solid',
    borderColor: colors.border,
    background: '#fff',
    cursor: 'pointer',
    color: colors.textMain,
  },
  tarjetaActiva: { borderColor: colors.primary, background: colors.primarySoft },
  tarjetaTitulo: { display: 'block', fontSize: '14px', fontWeight: 700 },
  tarjetaTexto: { display: 'block', marginTop: '4px', fontSize: '12px', color: colors.textSoft, lineHeight: 1.4 },
  grupoTitulo: { margin: '22px 0 10px 0', fontSize: '14px', fontWeight: 800, color: colors.textMain },
};

const Fila: React.FC<{ etiqueta: string; valor: React.ReactNode }> = ({ etiqueta, valor }) => (
  <div style={styles.fila}>
    <span style={styles.filaEtiqueta}>{etiqueta}</span>
    <span style={styles.filaValor}>{valor}</span>
  </div>
);

/* ------------------------------------------------------------------ perfil */

const SeccionPerfil: React.FC<{ perfil: PerfilCuenta }> = ({ perfil }) => {
  const nombre = perfil.displayName || perfil.email.split('@')[0];
  const rol = ROLES[perfil.role];

  return (
    <section style={styles.card}>
      <div style={styles.cabeceraPerfil}>
        <div style={styles.avatar} aria-hidden="true">{nombre.charAt(0).toUpperCase()}</div>
        <div>
          <h2 style={{ ...styles.h2, margin: 0 }}>{nombre}</h2>
          <p style={{ margin: '2px 0 0 0', color: colors.textSoft, fontSize: '14px' }}>{perfil.email}</p>
        </div>
      </div>

      <Fila etiqueta="Rol" valor={rol?.nombre ?? perfil.role} />
      {perfil.area && <Fila etiqueta="Área" valor={AREAS[perfil.area] ?? perfil.area} />}
      <Fila
        etiqueta="Inicio de sesión"
        valor={perfil.authType === 'entra' ? 'Cuenta Microsoft' : 'Correo y contraseña del portal'}
      />
      <Fila etiqueta="Cuenta creada" valor={formatearFecha(perfil.creadoEn)} />

      {rol && (
        <div style={styles.subcard}>
          <strong style={{ fontSize: '13px' }}>Qué puede hacer este rol</strong>
          <p style={{ margin: '6px 0 0 0', fontSize: '14px', color: colors.textSoft, lineHeight: 1.6 }}>
            {rol.descripcion}
          </p>
        </div>
      )}

      {perfil.proveedor && (
        <>
          <h3 style={styles.grupoTitulo}>Empresa</h3>
          <Fila etiqueta="Razón social" valor={perfil.proveedor.razonSocial ?? '—'} />
          <Fila etiqueta="RUT" valor={perfil.proveedor.rut ?? '—'} />
          <Fila etiqueta="Tipo de proveedor" valor={perfil.proveedor.tipo ?? '—'} />
          {perfil.proveedor.giro && <Fila etiqueta="Giro" valor={perfil.proveedor.giro} />}
          <Fila etiqueta="Estado de la solicitud" valor={perfil.proveedor.estado ?? '—'} />
        </>
      )}
    </section>
  );
};

/* -------------------------------------------------------- contraseña y sesión */

const SeccionSeguridad: React.FC<{ perfil: PerfilCuenta }> = ({ perfil }) => {
  const { logout } = useAuth();
  const navigate = useNavigate();

  const [actual, setActual] = useState('');
  const [nueva, setNueva] = useState('');
  const [repetida, setRepetida] = useState('');
  const [ver, setVer] = useState(false);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [exito, setExito] = useState(false);
  const [saliendo, setSaliendo] = useState(false);

  const requisitos = useMemo(
    () => [
      { ok: nueva.length >= 10, texto: 'Al menos 10 caracteres' },
      { ok: /\p{L}/u.test(nueva) && /\d/.test(nueva), texto: 'Al menos una letra y un número' },
      { ok: nueva.length > 0 && nueva.length <= 72, texto: 'No más de 72 caracteres' },
      { ok: nueva.length > 0 && nueva !== actual, texto: 'Distinta de la actual' },
      { ok: repetida.length > 0 && nueva === repetida, texto: 'Las dos contraseñas nuevas coinciden' },
    ],
    [actual, nueva, repetida]
  );

  const valido = actual.length > 0 && requisitos.every((r) => r.ok);

  const enviar = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!valido || guardando) return;

    setGuardando(true);
    setError(null);
    setExito(false);

    try {
      await CuentaService.cambiarPassword(actual, nueva);
      setActual('');
      setNueva('');
      setRepetida('');
      setExito(true);
    } catch (caught) {
      setError(mensajeErrorCuenta(caught, 'No fue posible cambiar la contraseña. Intenta nuevamente.'));
    } finally {
      setGuardando(false);
    }
  };

  const cerrarSesion = async () => {
    setSaliendo(true);

    try {
      await logout();
      clearSidebarStorage();
    } finally {
      navigate('/login');
    }
  };

  return (
    <>
      <section style={styles.card}>
        <h2 style={styles.h2}>Contraseña</h2>

        {perfil.puedeCambiarPassword ? (
          <form onSubmit={enviar} autoComplete="off">
            <p style={styles.help}>
              Al cambiarla te enviaremos un aviso por correo. La política es la misma que al activar tu cuenta.
            </p>

            {error && <div style={{ ...ui.errorBox, ...styles.aviso }} role="alert">{error}</div>}
            {exito && <div style={{ ...ui.successBox, ...styles.aviso }} role="status">Tu contraseña fue actualizada.</div>}

            <div style={styles.campo}>
              <label style={styles.label} htmlFor="pw-actual">Contraseña actual</label>
              <input id="pw-actual" style={styles.input} type={ver ? 'text' : 'password'} value={actual}
                autoComplete="current-password" onChange={(e) => setActual(e.target.value)} />
            </div>

            <div style={styles.campo}>
              <label style={styles.label} htmlFor="pw-nueva">Contraseña nueva</label>
              <input id="pw-nueva" style={styles.input} type={ver ? 'text' : 'password'} value={nueva}
                autoComplete="new-password" onChange={(e) => setNueva(e.target.value)} />
            </div>

            <div style={styles.campo}>
              <label style={styles.label} htmlFor="pw-repetida">Repite la contraseña nueva</label>
              <input id="pw-repetida" style={styles.input} type={ver ? 'text' : 'password'} value={repetida}
                autoComplete="new-password" onChange={(e) => setRepetida(e.target.value)} />
              <ul style={styles.requisitos} aria-label="Requisitos de la contraseña">
                {requisitos.map((r) => (
                  <li key={r.texto} style={{ color: r.ok ? colors.success : colors.textSoft }}>
                    {r.ok ? '✓' : '○'} {r.texto}
                  </li>
                ))}
              </ul>
            </div>

            <div style={styles.acciones}>
              <button type="submit" style={{ ...styles.boton, opacity: valido && !guardando ? 1 : 0.55 }}
                disabled={!valido || guardando}>
                {guardando ? 'Guardando…' : 'Cambiar contraseña'}
              </button>
              <button type="button" style={styles.botonSecundario} onClick={() => setVer((v) => !v)}>
                {ver ? 'Ocultar' : 'Mostrar'} contraseñas
              </button>
            </div>
          </form>
        ) : (
          <p style={{ ...styles.help, margin: 0 }}>
            Tu cuenta inicia sesión con Microsoft, así que la contraseña se administra en tu cuenta
            Microsoft y no en este portal.
          </p>
        )}
      </section>

      <section style={{ ...styles.card, marginTop: spacing.lg }}>
        <h2 style={styles.h2}>Sesión</h2>
        <p style={styles.help}>
          Si usas un equipo compartido, cierra la sesión al terminar.
        </p>

        <Fila etiqueta="Sesión actual iniciada" valor={formatearFecha(perfil.ingresoActual)} />
        <Fila etiqueta="Ingreso anterior" valor={formatearFecha(perfil.ingresoAnterior)} />

        <div style={{ ...styles.acciones, marginTop: spacing.md }}>
          <button type="button" style={styles.botonPeligro} onClick={cerrarSesion} disabled={saliendo}>
            {saliendo ? 'Cerrando…' : 'Cerrar sesión en este equipo'}
          </button>
        </div>
      </section>
    </>
  );
};

/* ----------------------------------------------------------- notificaciones */

const Interruptor: React.FC<{ activo: boolean; etiqueta: string; onCambio: (valor: boolean) => void; deshabilitado?: boolean }> = ({
  activo, etiqueta, onCambio, deshabilitado,
}) => (
  <button
    type="button"
    role="switch"
    aria-checked={activo}
    aria-label={etiqueta}
    disabled={deshabilitado}
    onClick={() => onCambio(!activo)}
    style={{
      width: '48px', height: '28px', borderRadius: '999px', border: 'none', padding: '3px',
      background: activo ? colors.primary : colors.borderStrong,
      cursor: deshabilitado ? 'not-allowed' : 'pointer', flexShrink: 0, transition: 'background 0.2s',
      display: 'flex', justifyContent: activo ? 'flex-end' : 'flex-start', opacity: deshabilitado ? 0.6 : 1,
    }}
  >
    <span style={{ width: '22px', height: '22px', borderRadius: '50%', background: '#fff', display: 'block' }} />
  </button>
);

const SeccionNotificaciones: React.FC = () => {
  const [items, setItems] = useState<PreferenciaAviso[] | null>(null);
  const [disponible, setDisponible] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [guardando, setGuardando] = useState<string | null>(null);

  useEffect(() => {
    let activo = true;

    CuentaService.obtenerPreferencias()
      .then((r) => {
        if (!activo) return;
        setItems(r.items);
        setDisponible(r.disponible);
      })
      .catch((caught) => {
        if (activo) setError(mensajeErrorCuenta(caught, 'No se pudieron cargar tus preferencias.'));
      });

    return () => {
      activo = false;
    };
  }, []);

  const cambiar = async (grupo: string, correo: boolean) => {
    if (!items) return;

    const anteriores = items;
    setItems(items.map((i) => (i.grupo === grupo ? { ...i, correo } : i)));
    setGuardando(grupo);
    setError(null);

    try {
      const r = await CuentaService.guardarPreferencias({ [grupo]: correo });
      setItems(r.items);
    } catch (caught) {
      setItems(anteriores);
      setError(mensajeErrorCuenta(caught, 'No se pudo guardar el cambio.'));
    } finally {
      setGuardando(null);
    }
  };

  return (
    <section style={styles.card}>
      <h2 style={styles.h2}>Avisos por correo</h2>
      <p style={styles.help}>
        Todos los avisos aparecen siempre en la campana del portal. Aquí eliges cuáles te llegan además por correo.
        Los avisos de seguridad, como el cambio de contraseña, siempre se envían.
      </p>

      {error && <div style={{ ...ui.errorBox, ...styles.aviso }} role="alert">{error}</div>}
      {!disponible && (
        <div style={{ ...ui.errorBox, ...styles.aviso }}>
          Esta función aún no está activada en el servidor. Pide al administrador ejecutar <code>php artisan cuenta:aplicar</code>.
        </div>
      )}
      {!items && !error && <p style={styles.help}>Cargando…</p>}

      {items?.map((item) => (
        <div key={item.grupo} style={styles.opcion}>
          <div>
            <p style={styles.opcionTitulo}>{item.titulo}</p>
            <p style={styles.opcionDescripcion}>{item.descripcion}</p>
          </div>
          <Interruptor
            activo={item.correo}
            etiqueta={`Recibir por correo: ${item.titulo}`}
            deshabilitado={!disponible || guardando === item.grupo}
            onCambio={(valor) => cambiar(item.grupo, valor)}
          />
        </div>
      ))}
    </section>
  );
};

/* --------------------------------------------------------------- apariencia */

const TEMAS: { id: Tema; titulo: string; texto: string }[] = [
  { id: 'claro', titulo: 'Claro', texto: 'Fondo claro, como hasta ahora.' },
  { id: 'oscuro', titulo: 'Oscuro', texto: 'Menos brillo en ambientes con poca luz.' },
  { id: 'sistema', titulo: 'Automático', texto: 'Sigue el tema de tu computador.' },
];

const MENUS: { id: ModoMenu; titulo: string; texto: string }[] = [
  { id: 'fijo', titulo: 'Fijo', texto: 'El menú siempre visible a la izquierda.' },
  { id: 'oculto', titulo: 'Oculto', texto: 'Más espacio para el contenido; se abre con el botón ☰.' },
];

const SeccionApariencia: React.FC = () => {
  const prefs = usePreferenciasUI();

  const tarjeta = (activa: boolean): React.CSSProperties => ({ ...styles.tarjeta, ...(activa ? styles.tarjetaActiva : {}) });

  return (
    <section style={styles.card}>
      <h2 style={styles.h2}>Apariencia</h2>
      <p style={styles.help}>Estas opciones se guardan en este navegador y no afectan a otros usuarios.</p>

      <h3 style={{ ...styles.grupoTitulo, marginTop: 0 }}>Tema</h3>
      <div style={styles.tarjetas} role="radiogroup" aria-label="Tema">
        {TEMAS.map((t) => (
          <button key={t.id} type="button" role="radio" aria-checked={prefs.tema === t.id}
            style={tarjeta(prefs.tema === t.id)} onClick={() => guardarPreferencias({ tema: t.id })}>
            <span style={styles.tarjetaTitulo}>{t.titulo}</span>
            <span style={styles.tarjetaTexto}>{t.texto}</span>
          </button>
        ))}
      </div>

      <h3 style={styles.grupoTitulo}>Menú lateral</h3>
      <div style={styles.tarjetas} role="radiogroup" aria-label="Menú lateral">
        {MENUS.map((m) => (
          <button key={m.id} type="button" role="radio" aria-checked={prefs.menu === m.id}
            style={tarjeta(prefs.menu === m.id)} onClick={() => guardarPreferencias({ menu: m.id })}>
            <span style={styles.tarjetaTitulo}>{m.titulo}</span>
            <span style={styles.tarjetaTexto}>{m.texto}</span>
          </button>
        ))}
      </div>
    </section>
  );
};

/* ------------------------------------------------------------------- página */

const MiCuenta: React.FC = () => {
  const [params, setParams] = useSearchParams();
  const [perfil, setPerfil] = useState<PerfilCuenta | null>(null);
  const [error, setError] = useState<string | null>(null);

  const pedida = params.get('seccion');
  const seccion: Seccion = SECCIONES.some((s) => s.id === pedida) ? (pedida as Seccion) : 'perfil';

  const cargar = useCallback(async () => {
    setError(null);

    try {
      setPerfil(await CuentaService.obtenerPerfil());
    } catch (caught) {
      setError(mensajeErrorCuenta(caught, 'No se pudo cargar tu cuenta.'));
    }
  }, []);

  useEffect(() => {
    cargar();
  }, [cargar]);

  return (
    <div style={styles.page}>
      <PageHero
        eyebrow="MI CUENTA"
        title="Perfil y configuración"
        subtitle="Revisa tus datos, cambia tu contraseña, elige qué avisos recibes por correo y ajusta cómo se ve el portal."
      />

      <style>{`@media (max-width: 760px) { .mi-cuenta-layout { grid-template-columns: 1fr !important; } }`}</style>

      <div className="mi-cuenta-layout" style={styles.layout}>
        <nav style={styles.nav} aria-label="Secciones de Mi cuenta">
          {SECCIONES.map((s) => (
            <button key={s.id} type="button"
              style={{ ...styles.navItem, ...(seccion === s.id ? styles.navItemActive : {}) }}
              aria-current={seccion === s.id ? 'page' : undefined}
              onClick={() => setParams({ seccion: s.id })}>
              {s.etiqueta}
            </button>
          ))}
        </nav>

        <div>
          {error && (
            <div style={{ ...ui.errorBox, marginBottom: spacing.md }} role="alert">
              {error}{' '}
              <button type="button" style={{ ...styles.botonSecundario, padding: '6px 12px', marginLeft: '8px' }} onClick={cargar}>
                Reintentar
              </button>
            </div>
          )}

          {seccion === 'apariencia' && <SeccionApariencia />}
          {seccion === 'notificaciones' && <SeccionNotificaciones />}
          {perfil && seccion === 'perfil' && <SeccionPerfil perfil={perfil} />}
          {perfil && seccion === 'seguridad' && <SeccionSeguridad perfil={perfil} />}
          {!perfil && !error && (seccion === 'perfil' || seccion === 'seguridad') && <p style={styles.help}>Cargando…</p>}
        </div>
      </div>
    </div>
  );
};

export default MiCuenta;
