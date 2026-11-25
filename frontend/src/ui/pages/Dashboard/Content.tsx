import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';

import { useAuth } from '../../../config/useAuth';
import apiClient from '../../../infrastructure/auth/api/apiClient';

import PageHero from '../../components/common/PageHero';
import type { RawApiData } from '../../../utils/apiTypes';

// ==========================================
// INTERFACES Y CONSTANTES
// ==========================================

interface DashboardEvent {
  id?: string;
  accion: string;
  fecha: string;
  createdAt?: string;
  realizadoPor?: string;
  proveedorId?: string | null;
  razonSocial?: string | null;
}

interface EmpresaResumen {
  empresa: string;
  cantidad: number;
}

interface DashboardStats {
  totalProveedores: number;
  solicitudesActivas: number;

  proveedoresEnRevision: number;
  proveedoresObservados: number;
  proveedoresAprobados: number;
  proveedoresRechazados: number;

  documentosTotal: number;
  documentosPendientes: number;
  documentosSubidos: number;
  documentosPorRevisar: number;
  documentosObservados: number;
  documentosAprobados: number;

  workflowTotal: number;
  workflowPendientes: number;
  workflowEnRevision: number;
  workflowObservados: number;
  workflowAprobados: number;
  workflowRechazados: number;

  validacionesTotal: number;
  validacionesPendientes: number;
  validacionesEnRevision: number;
  validacionesObservadas: number;
  validacionesAprobadas: number;
  validacionesRechazadas: number;

  sla: number;
  cumplimiento: number;

  visibilidad: string;
  eventos: DashboardEvent[];
  empresasResumen: EmpresaResumen[];

  plazos: PlazosResumen | null;
}

interface PlazosResumen {
  plazoDias: number;
  etapasVencidas: number;
  promedioTotalDias: number | null;
  proveedoresMedidos: number;
  promedioPorArea: Array<{ area: string; promedioDias: number; etapasCerradas: number }>;
}

const EMPTY_STATS: DashboardStats = {
  totalProveedores: 0,
  solicitudesActivas: 0,

  proveedoresEnRevision: 0,
  proveedoresObservados: 0,
  proveedoresAprobados: 0,
  proveedoresRechazados: 0,

  documentosTotal: 0,
  documentosPendientes: 0,
  documentosSubidos: 0,
  documentosPorRevisar: 0,
  documentosObservados: 0,
  documentosAprobados: 0,

  workflowTotal: 0,
  workflowPendientes: 0,
  workflowEnRevision: 0,
  workflowObservados: 0,
  workflowAprobados: 0,
  workflowRechazados: 0,

  validacionesTotal: 0,
  validacionesPendientes: 0,
  validacionesEnRevision: 0,
  validacionesObservadas: 0,
  validacionesAprobadas: 0,
  validacionesRechazadas: 0,

  sla: 0,
  cumplimiento: 0,

  visibilidad: 'Sin visibilidad',
  eventos: [],
  empresasResumen: [],
  plazos: null,
};

// ==========================================
// FUNCIONES AUXILIARES
// ==========================================

const formatNumber = (value: number) =>
  new Intl.NumberFormat('es-CL').format(Number(value || 0));

const isAdminGlobalRole = (role?: string | null): boolean =>
  role === 'pm_admin' || role === 'admin_portal';

const isAreaRole = (role?: string | null): boolean =>
  role === 'admin_area' || role === 'usuario_area';

const getVisibilityLabel = (
  role?: string | null,
  area?: string | null,
  backendVisibility?: string
): string => {
  if (backendVisibility) return backendVisibility;
  if (role === 'proveedor') return 'Mi cuenta proveedor';
  if (isAdminGlobalRole(role)) return 'Global';
  if (role === 'pm_user') return 'Mis proveedores PM';
  if (isAreaRole(role)) return `Área ${area || 'asignada'}`;
  return 'Sin visibilidad';
};

const canGoSeguimiento = (role?: string | null): boolean =>
  role === 'pm_admin' ||
  role === 'pm_user' ||
  role === 'admin_portal' ||
  role === 'admin_area' ||
  role === 'usuario_area';

const canGoCrearProveedor = (
  role?: string | null,
  area?: string | null
): boolean => {
  if (role === 'pm_admin' || role === 'admin_portal') return true;
  if (role === 'pm_user') return true;
  if ((role === 'admin_area' || role === 'usuario_area') && area === 'pm') {
    return true;
  }
  return false;
};

const canGoMisSolicitudes = (role?: string | null): boolean =>
  role === 'proveedor';

const normalizeDashboardData = (rawData: RawApiData): DashboardStats => {
  const actividadReciente = Array.isArray(rawData?.actividadReciente)
    ? rawData.actividadReciente
    : [];

  const empresasResumen = Array.isArray(rawData?.empresasResumen)
    ? rawData.empresasResumen.map((item: RawApiData) => ({
        empresa:
          item?.empresa ??
          item?.empresaNombre ??
          item?.empresaId ??
          'Sin empresa',
        cantidad: Number(item?.cantidad ?? 0),
      }))
    : [];

  return {
    ...EMPTY_STATS,
    ...rawData,
    totalProveedores: Number(rawData?.totalProveedores ?? 0),
    solicitudesActivas: Number(rawData?.solicitudesActivas ?? 0),
    proveedoresEnRevision: Number(rawData?.proveedoresEnRevision ?? 0),
    proveedoresObservados: Number(rawData?.proveedoresObservados ?? 0),
    proveedoresAprobados: Number(rawData?.proveedoresAprobados ?? 0),
    proveedoresRechazados: Number(rawData?.proveedoresRechazados ?? 0),
    documentosTotal: Number(rawData?.documentosTotal ?? 0),
    documentosPendientes: Number(rawData?.documentosPendientes ?? 0),
    documentosSubidos: Number(rawData?.documentosSubidos ?? 0),
    documentosPorRevisar: Number(rawData?.documentosPorRevisar ?? 0),
    documentosObservados: Number(rawData?.documentosObservados ?? 0),
    documentosAprobados: Number(rawData?.documentosAprobados ?? 0),
    workflowTotal: Number(rawData?.workflowTotal ?? 0),
    workflowPendientes: Number(rawData?.workflowPendientes ?? 0),
    workflowEnRevision: Number(rawData?.workflowEnRevision ?? 0),
    workflowObservados: Number(rawData?.workflowObservados ?? 0),
    workflowAprobados: Number(rawData?.workflowAprobados ?? 0),
    workflowRechazados: Number(rawData?.workflowRechazados ?? 0),
    validacionesTotal: Number(rawData?.validacionesTotal ?? 0),
    validacionesPendientes: Number(rawData?.validacionesPendientes ?? 0),
    validacionesEnRevision: Number(rawData?.validacionesEnRevision ?? 0),
    validacionesObservadas: Number(rawData?.validacionesObservadas ?? 0),
    validacionesAprobadas: Number(rawData?.validacionesAprobadas ?? 0),
    validacionesRechazadas: Number(rawData?.validacionesRechazadas ?? 0),
    sla: Number(rawData?.sla ?? rawData?.cumplimiento ?? 0),
    cumplimiento: Number(rawData?.cumplimiento ?? rawData?.sla ?? 0),
    visibilidad: rawData?.visibilidad ?? EMPTY_STATS.visibilidad,
    eventos: actividadReciente,
    empresasResumen,
    plazos: rawData?.plazos ?? null,
  };
};

// ==========================================
// COMPONENTE PRINCIPAL: CONTENT
// ==========================================

const Content: React.FC = () => {
  const navigate = useNavigate();

  const [selectedPeriod, setSelectedPeriod] = useState('Este mes');
  const [showWelcomeSection, setShowWelcomeSection] = useState(true);
  const [stats, setStats] = useState<DashboardStats>(EMPTY_STATS);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const { user, area } = useAuth();

  const role = user?.role ?? null;
  const userArea = area;
  const userName = user?.displayName || user?.email?.split('@')[0] || 'Usuario';

  useEffect(() => {
    let mounted = true;

    const loadDashboard = async () => {
      setLoading(true);
      setError(null);

      try {
        const response = await apiClient.get('/dashboard');
        const data = response.data?.data ?? response.data ?? {};

        if (!mounted) return;
        setStats(normalizeDashboardData(data));
      } catch (err) {
        console.error('Error cargando dashboard:', err);
        if (mounted) {
          setStats(EMPTY_STATS);
          setError(
            'No se pudo cargar el dashboard. Verifica que el backend esté disponible.'
          );
        }
      } finally {
        if (mounted) {
          setLoading(false);
        }
      }
    };

    loadDashboard();

    return () => {
      mounted = false;
    };
  }, []);

  useEffect(() => {
    const timer = setTimeout(() => {
      setShowWelcomeSection(false);
    }, 3000);

    return () => clearTimeout(timer);
  }, []);

  const visibilityLabel = getVisibilityLabel(role, userArea, stats.visibilidad);
  const workflowPendientesYRevision = stats.workflowPendientes + stats.workflowEnRevision;
  const validacionesPendientesYRevision = stats.validacionesPendientes + stats.validacionesEnRevision;

  const styles: Record<string, React.CSSProperties> = {
    main: {
      flex: 1,
      padding: '24px',
      overflowY: 'auto',
      background: '#f8fafc',
    },
    section: {
      marginBottom: '32px',
    },
    welcomeSection: {
      position: 'relative',
      background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
      borderRadius: '20px',
      padding: '32px',
      color: 'white',
      marginBottom: '24px',
      overflow: 'hidden',
      transition: 'all 0.5s ease-out',
      transform: 'translateY(0)',
      opacity: 1,
    },
    welcomeSectionHidden: {
      transform: 'translateY(-20px)',
      opacity: 0,
      marginBottom: '0px',
      height: '0px',
      padding: '0px',
      overflow: 'hidden',
      pointerEvents: 'none',
    },
    welcomeOverlay: {
      position: 'absolute',
      inset: 0,
      background: 'rgba(255, 255, 255, 0.1)',
      borderRadius: '20px',
    },
    welcomeContent: {
      position: 'relative',
      zIndex: 1,
      display: 'flex',
      justifyContent: 'space-between',
      alignItems: 'flex-start',
      gap: '16px',
      flexWrap: 'wrap',
    },
    welcomeCloseButton: {
      background: 'rgba(255, 255, 255, 0.2)',
      border: 'none',
      borderRadius: '50%',
      width: '32px',
      height: '32px',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      cursor: 'pointer',
      transition: 'background 0.2s ease',
      color: 'white',
      fontSize: '16px',
    },
    welcomeTitle: {
      fontSize: '28px',
      fontWeight: '700',
      margin: 0,
      marginBottom: '8px',
    },
    welcomeSubtitle: {
      fontSize: '16px',
      opacity: 0.92,
      margin: 0,
      marginBottom: '16px',
      lineHeight: 1.5,
    },
    welcomeStats: {
      display: 'flex',
      gap: '24px',
      flexWrap: 'wrap',
    },
    welcomeStat: {
      display: 'flex',
      alignItems: 'center',
      gap: '8px',
      fontSize: '14px',
      fontWeight: 600,
    },
    alertBox: {
      background: '#fef2f2',
      border: '1px solid #fecaca',
      color: '#991b1b',
      padding: '12px 14px',
      borderRadius: '12px',
      marginBottom: '16px',
      fontSize: '14px',
      fontWeight: 600,
    },
    loadingBox: {
      background: '#eff6ff',
      border: '1px solid #bfdbfe',
      color: '#1d4ed8',
      padding: '12px 14px',
      borderRadius: '12px',
      marginBottom: '16px',
      fontSize: '14px',
      fontWeight: 600,
    },
    metricsGrid: {
      display: 'grid',
      gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))',
      gap: '24px',
      marginBottom: '32px',
    },
    metricCard: {
      background: 'white',
      borderRadius: '16px',
      padding: '24px',
      boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03)',
      border: '1px solid #f3f4f6',
    },
    metricHeader: {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      marginBottom: '16px',
      gap: '12px',
    },
    metricIcon: {
      width: '48px',
      height: '48px',
      borderRadius: '12px',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      fontSize: '24px',
      flexShrink: 0,
    },
    metricValue: {
      fontSize: '32px',
      fontWeight: '700',
      color: '#111827',
      margin: 0,
      marginBottom: '4px',
    },
    metricLabel: {
      fontSize: '14px',
      color: '#6b7280',
      margin: 0,
      marginBottom: '8px',
    },
    metricChange: {
      fontSize: '14px',
      fontWeight: '600',
      display: 'flex',
      alignItems: 'center',
      gap: '4px',
      flexWrap: 'wrap',
    },
    metricChangePositive: {
      color: '#10b981',
    },
    metricChangeNegative: {
      color: '#ef4444',
    },
    chartsGrid: {
      display: 'grid',
      gridTemplateColumns: 'repeat(auto-fit, minmax(380px, 1fr))',
      gap: '24px',
    },
    chartCard: {
      background: 'white',
      borderRadius: '16px',
      padding: '24px',
      boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03)',
      border: '1px solid #f3f4f6',
    },
    chartTitle: {
      fontSize: '18px',
      fontWeight: '600',
      color: '#111827',
      margin: 0,
      marginBottom: '16px',
    },
    chartBody: {
      minHeight: '220px',
      background: '#f9fafb',
      borderRadius: '8px',
      color: '#6b7280',
      padding: '16px',
    },
    periodSelect: {
      padding: '8px 12px',
      border: '1px solid #d1d5db',
      borderRadius: '8px',
      fontSize: '14px',
      background: 'white',
      color: '#374151',
      outline: 'none',
      cursor: 'pointer',
    },
    navButton: {
      padding: '12px 24px',
      background: '#3b82f6',
      color: 'white',
      border: 'none',
      borderRadius: '8px',
      fontSize: '14px',
      fontWeight: '500',
      cursor: 'pointer',
      transition: 'background-color 0.2s ease',
      marginRight: '12px',
    },
    navButtonSecondary: {
      background: '#6b7280',
    },
    navButtonSuccess: {
      background: '#10b981',
    },
    listItem: {
      padding: '12px 0',
      borderBottom: '1px solid #f3f4f6',
      display: 'flex',
      justifyContent: 'space-between',
      gap: '12px',
      alignItems: 'flex-start',
    },
    listItemLast: {
      borderBottom: 'none',
    },
    eventTitle: {
      margin: 0,
      color: '#111827',
      fontWeight: 600,
      fontSize: '14px',
    },
    eventMeta: {
      margin: '4px 0 0 0',
      color: '#6b7280',
      fontSize: '12px',
    },
    badge: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: '6px',
      padding: '6px 10px',
      borderRadius: '999px',
      background: '#e5e7eb',
      color: '#374151',
      fontSize: '12px',
      fontWeight: 700,
    },
  };

  return (
    <main style={styles.main}>
      {/* PAGEHERO IMPORTADO */}
      <PageHero 
        title="Dashboard de Control" 
        subtitle={`Panel supervisor de operaciones para ${userName}`}
      />

      {loading && <div style={styles.loadingBox}>Cargando dashboard...</div>}
      {error && <div style={styles.alertBox}>{error}</div>}

      {/* SECCIÓN BIENVENIDA AUTOMÁTICA ORIGINAL */}
      <section style={{ marginBottom: showWelcomeSection ? '24px' : '0px' }}>
        <div
          style={{
            ...styles.welcomeSection,
            ...(showWelcomeSection ? {} : styles.welcomeSectionHidden),
          }}
        >
          <div style={styles.welcomeOverlay} />
          <div style={styles.welcomeContent}>
            <div>
              <h1 style={styles.welcomeTitle}>¡Bienvenido de vuelta, {userName}!</h1>
              <p style={styles.welcomeSubtitle}>
                Aquí tienes un resumen del Portal de Proveedores con documentos, workflow, validaciones y actividad reciente.
              </p>
              <div style={styles.welcomeStats}>
                <div style={styles.welcomeStat}>📄 {formatNumber(stats.solicitudesActivas)} solicitudes activas</div>
                <div style={styles.welcomeStat}>📎 {formatNumber(stats.documentosObservados)} documentos observados</div>
                <div style={styles.welcomeStat}>🔄 {formatNumber(stats.workflowObservados)} etapas observadas</div>
                <div style={styles.welcomeStat}>✅ {formatNumber(stats.proveedoresAprobados)} proveedores aprobados</div>
              </div>
            </div>
            <button
              style={styles.welcomeCloseButton}
              onClick={() => setShowWelcomeSection(false)}
              title="Cerrar sección de bienvenida"
              type="button"
            >
              ×
            </button>
          </div>
        </div>
      </section>

      {/* MÉTRICAS PRINCIPALES */}
      <section style={styles.section}>
        <div style={styles.metricsGrid}>
          <MetricCard
            label="Solicitudes activas"
            value={formatNumber(stats.solicitudesActivas)}
            change={`${formatNumber(stats.totalProveedores)} procesos visibles`}
            changeStyle={{ ...styles.metricChange, ...styles.metricChangePositive }}
            icon="📄"
            iconStyle={{ ...styles.metricIcon, background: '#dbeafe', color: '#3b82f6' }}
            styles={styles}
          />
          <MetricCard
            label="Proveedores en proceso"
            value={formatNumber(stats.totalProveedores)}
            change={`En revisión: ${formatNumber(stats.proveedoresEnRevision)} · Obs: ${formatNumber(stats.proveedoresObservados)}`}
            changeStyle={{ ...styles.metricChange, ...styles.metricChangePositive }}
            icon="🏭"
            iconStyle={{ ...styles.metricIcon, background: '#dcfce7', color: '#10b981' }}
            styles={styles}
          />
          <MetricCard
            label="Documentos por revisar"
            value={formatNumber(stats.documentosPorRevisar)}
            change={`Observados: ${formatNumber(stats.documentosObservados)}`}
            changeStyle={{ ...styles.metricChange, ...styles.metricChangeNegative }}
            icon="📎"
            iconStyle={{ ...styles.metricIcon, background: '#fef3c7', color: '#f59e0b' }}
            styles={styles}
          />
          <MetricCard
            label="Cumplimiento documental"
            value={`${Number(stats.sla || 0).toFixed(1)}%`}
            change={`Aprobados: ${formatNumber(stats.documentosAprobados)}`}
            changeStyle={{ ...styles.metricChange, ...styles.metricChangePositive }}
            icon="⏱️"
            iconStyle={{ ...styles.metricIcon, background: '#fce7f3', color: '#ec4899' }}
            styles={styles}
          />
          <MetricCard
            label="Workflow observado"
            value={formatNumber(stats.workflowObservados)}
            change={`Pendientes / revisión: ${formatNumber(workflowPendientesYRevision)}`}
            changeStyle={{ ...styles.metricChange, ...styles.metricChangeNegative }}
            icon="🔄"
            iconStyle={{ ...styles.metricIcon, background: '#ede9fe', color: '#7c3aed' }}
            styles={styles}
          />
          <MetricCard
            label="Validaciones observadas"
            value={formatNumber(stats.validacionesObservadas)}
            change={`Pendientes / revisión: ${formatNumber(validacionesPendientesYRevision)}`}
            changeStyle={{ ...styles.metricChange, ...styles.metricChangeNegative }}
            icon="🧾"
            iconStyle={{ ...styles.metricIcon, background: '#fee2e2', color: '#ef4444' }}
            styles={styles}
          />
          {stats.plazos && (
            <>
              <MetricCard
                label="Tiempo promedio del proceso"
                value={
                  stats.plazos.promedioTotalDias === null
                    ? '—'
                    : `${stats.plazos.promedioTotalDias} días`
                }
                change={`${formatNumber(stats.plazos.proveedoresMedidos)} proveedores aprobados medidos`}
                changeStyle={{ ...styles.metricChange, ...styles.metricChangePositive }}
                icon="📅"
                iconStyle={{ ...styles.metricIcon, background: '#e0f2fe', color: '#0284c7' }}
                styles={styles}
              />
              <MetricCard
                label="Etapas fuera de plazo"
                value={formatNumber(stats.plazos.etapasVencidas)}
                change={`Plazo por etapa: ${stats.plazos.plazoDias} días`}
                changeStyle={{ ...styles.metricChange, ...styles.metricChangeNegative }}
                icon="⏰"
                iconStyle={{ ...styles.metricIcon, background: '#fee2e2', color: '#dc2626' }}
                styles={styles}
              />
            </>
          )}
        </div>
      </section>

      {/* ACCESOS RÁPIDOS */}
      <section style={styles.section}>
        <div style={{ marginBottom: '16px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '12px', flexWrap: 'wrap' }}>
          <h2 style={{ fontSize: '20px', fontWeight: '600', color: '#111827', margin: 0 }}>Accesos rápidos</h2>
          <div style={{ display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap' }}>
            {canGoSeguimiento(role) && (
              <button style={styles.navButton} onClick={() => navigate('/proveedores/seguimiento')} type="button">
                Seguimiento
              </button>
            )}
            {canGoCrearProveedor(role, userArea) && (
              <button style={{ ...styles.navButton, ...styles.navButtonSuccess }} onClick={() => navigate('/proveedores/crear')} type="button">
                Invitar proveedor
              </button>
            )}
            {canGoMisSolicitudes(role) && (
              <button style={{ ...styles.navButton, ...styles.navButtonSecondary }} onClick={() => navigate('/proveedores/mis-solicitudes')} type="button">
                Mis Solicitudes
              </button>
            )}
            <select style={styles.periodSelect} value={selectedPeriod} onChange={event => setSelectedPeriod(event.target.value)}>
              <option>Este mes</option>
              <option>Último mes</option>
              <option>Último trimestre</option>
              <option>Este año</option>
            </select>
          </div>
        </div>
      </section>

      {/* CUADROS RESUMEN */}
      <section style={styles.section}>
        <div style={styles.chartsGrid}>
          <SummaryCard
            title="Solicitudes por estado"
            items={[
              ['En revisión', stats.proveedoresEnRevision],
              ['Observados', stats.proveedoresObservados],
              ['Aprobados', stats.proveedoresAprobados],
              ['Rechazados', stats.proveedoresRechazados],
            ]}
            styles={styles}
          />

          <SummaryCard
            title="Workflow y validaciones"
            items={[
              ['Workflow pendientes', stats.workflowPendientes],
              ['Workflow en revisión', stats.workflowEnRevision],
              ['Workflow observados', stats.workflowObservados],
              ['Validaciones pendientes', stats.validacionesPendientes],
              ['Validaciones en revisión', stats.validacionesEnRevision],
              ['Validaciones observadas', stats.validacionesObservadas],
            ]}
            styles={styles}
          />

          <div style={styles.chartCard}>
            <h3 style={styles.chartTitle}>Actividad reciente</h3>
            <div style={styles.chartBody}>
              {stats.eventos.length === 0 ? (
                <div style={{ padding: '12px 0' }}>No hay actividad reciente.</div>
              ) : (
                stats.eventos.map((evento, idx) => {
                  const fecha = evento.fecha || evento.createdAt || '';
                  return (
                    <div key={`${fecha}-${idx}`} style={{ ...styles.listItem, ...(idx === stats.eventos.length - 1 ? styles.listItemLast : {}) }}>
                      <div>
                        <p style={styles.eventTitle}>🕒 {evento.accion}</p>
                        <p style={styles.eventMeta}>
                          {evento.razonSocial ? `${evento.razonSocial} · ` : ''}
                          {fecha ? new Date(fecha).toLocaleString() : ''}
                        </p>
                      </div>
                    </div>
                  );
                })
              )}
            </div>
          </div>

          <SummaryCard
            title="Alertas y resumen"
            items={[
              ['Documentos observados', stats.documentosObservados],
              ['Workflow observados', stats.workflowObservados],
              ['Validaciones observadas', stats.validacionesObservadas],
              ['Solicitudes activas', stats.solicitudesActivas],
            ]}
            extraItem={['Visibilidad', visibilityLabel]}
            styles={styles}
          />

          <div style={styles.chartCard}>
            <h3 style={styles.chartTitle}>Procesos por empresa</h3>
            <div style={styles.chartBody}>
              {stats.empresasResumen.length === 0 ? (
                <div style={{ padding: '12px 0' }}>No hay empresas para mostrar.</div>
              ) : (
                stats.empresasResumen.map((item, idx) => (
                  <div key={`${item.empresa}-${idx}`} style={{ ...styles.listItem, ...(idx === stats.empresasResumen.length - 1 ? styles.listItemLast : {}) }}>
                    <span>{item.empresa}</span>
                    <span style={styles.badge}>{formatNumber(item.cantidad)}</span>
                  </div>
                ))
              )}
            </div>
          </div>

          <SummaryCard
            title="Documentación"
            items={[
              ['Pendientes', stats.documentosPendientes],
              ['Subidos', stats.documentosSubidos],
              ['Observados', stats.documentosObservados],
              ['Aprobados', stats.documentosAprobados],
            ]}
            styles={styles}
          />
        </div>
      </section>
    </main>
  );
};

// ==========================================
// COMPONENTES SECUNDARIOS LOCALES
// ==========================================

interface MetricCardProps {
  label: string;
  value: string;
  change: string;
  changeStyle: React.CSSProperties;
  icon: string;
  iconStyle: React.CSSProperties;
  styles: Record<string, React.CSSProperties>;
}

const MetricCard: React.FC<MetricCardProps> = ({
  label,
  value,
  change,
  changeStyle,
  icon,
  iconStyle,
  styles,
}) => (
  <div style={styles.metricCard}>
    <div style={styles.metricHeader}>
      <div>
        <p style={styles.metricLabel}>{label}</p>
        <h3 style={styles.metricValue}>{value}</h3>
        <div style={changeStyle}>{change}</div>
      </div>
      <div style={iconStyle}>{icon}</div>
    </div>
  </div>
);

interface SummaryCardProps {
  title: string;
  items: Array<[string, number]>;
  extraItem?: [string, string];
  styles: Record<string, React.CSSProperties>;
}

const BAR_COLORS: Array<[RegExp, string]> = [
  [/aprobad/i, '#16a34a'],
  [/rechazad/i, '#dc2626'],
  [/observad/i, '#d97706'],
  [/revisi/i, '#2563eb'],
  [/pendiente/i, '#9ca3af'],
];

const getBarColor = (label: string): string =>
  BAR_COLORS.find(([regex]) => regex.test(label))?.[1] ?? '#6d5dfc';

const SummaryCard: React.FC<SummaryCardProps> = ({
  title,
  items,
  extraItem,
  styles,
}) => {
  const totalItems = items.length + (extraItem ? 1 : 0);
  const maxValue = Math.max(1, ...items.map(([, value]) => value));

  return (
    <div style={styles.chartCard}>
      <h3 style={styles.chartTitle}>{title}</h3>
      <div style={styles.chartBody}>
        {items.map(([label, value], idx) => (
          <div
            key={label}
            style={{
              ...styles.listItem,
              ...(idx === totalItems - 1 && !extraItem ? styles.listItemLast : {}),
              flexDirection: 'column',
              gap: '8px',
              alignItems: 'stretch',
            }}
          >
            <div
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                gap: '12px',
              }}
            >
              <span>{label}</span>
              <span style={styles.badge}>{formatNumber(value)}</span>
            </div>

            <div
              role="img"
              aria-label={`${label}: ${value}`}
              style={{
                height: '6px',
                borderRadius: '999px',
                background: '#f3f4f6',
                overflow: 'hidden',
              }}
            >
              <div
                style={{
                  width: `${Math.round((value / maxValue) * 100)}%`,
                  height: '100%',
                  background: getBarColor(label),
                  transition: 'width 0.4s ease',
                }}
              />
            </div>
          </div>
        ))}

        {extraItem && (
          <div style={{ ...styles.listItem, ...styles.listItemLast }}>
            <span>{extraItem[0]}</span>
            <span style={styles.badge}>{extraItem[1]}</span>
          </div>
        )}
      </div>
    </div>
  );
};

export default Content;
