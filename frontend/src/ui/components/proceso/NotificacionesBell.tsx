import React, { useCallback, useEffect, useState } from 'react';

import {
  ProcesoProveedorService,
  type Notificacion,
} from '../../../infrastructure/auth/api/procesoProveedorService';
import { colors, radius, shadows } from '../../theme/tokens';

/*
|--------------------------------------------------------------------------
| Campana de notificaciones
|--------------------------------------------------------------------------
|
| Consulta las notificaciones del usuario cada minuto. Cada aviso también
| se envía por correo (en el ambiente local llega a Mailpit).
|
*/

const INTERVALO_MS = 60000;

const NotificacionesBell: React.FC = () => {
  const [abierta, setAbierta] = useState(false);
  const [items, setItems] = useState<Notificacion[]>([]);
  const [noLeidas, setNoLeidas] = useState(0);

  const cargar = useCallback(async (): Promise<void> => {
    try {
      const data = await ProcesoProveedorService.getNotificaciones();
      setItems(data.items);
      setNoLeidas(data.noLeidas);
    } catch {
      /* Si falla la consulta, la campana queda con los últimos datos. */
    }
  }, []);

  useEffect(() => {
    void cargar();
    const id = window.setInterval(() => void cargar(), INTERVALO_MS);
    return () => window.clearInterval(id);
  }, [cargar]);

  const leer = async (notificacion: Notificacion): Promise<void> => {
    if (notificacion.leida) {
      return;
    }

    await ProcesoProveedorService.marcarLeida(notificacion.id);
    await cargar();
  };

  const leerTodas = async (): Promise<void> => {
    await ProcesoProveedorService.marcarTodasLeidas();
    await cargar();
  };

  return (
    <div style={styles.wrapper}>
      <button
        type="button"
        style={styles.bell}
        onClick={() => setAbierta(v => !v)}
        aria-label={`Notificaciones${noLeidas ? `, ${noLeidas} sin leer` : ''}`}
      >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
          <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
          <path d="M13.73 21a2 2 0 0 1-3.46 0" />
        </svg>
        {noLeidas > 0 && <span style={styles.counter}>{noLeidas > 99 ? '99+' : noLeidas}</span>}
      </button>

      {abierta && (
        <div style={styles.panel}>
          <div style={styles.panelHeader}>
            <strong>Notificaciones</strong>
            {noLeidas > 0 && (
              <button type="button" style={styles.link} onClick={leerTodas}>
                Marcar todas como leídas
              </button>
            )}
          </div>

          {items.length === 0 ? (
            <p style={styles.empty}>No tienes notificaciones.</p>
          ) : (
            <ul style={styles.list}>
              {items.map(n => (
                <li
                  key={n.id}
                  style={{ ...styles.item, background: n.leida ? colors.bgCard : colors.primarySoft }}
                  onClick={() => void leer(n)}
                >
                  <p style={styles.itemTitle}>{n.titulo}</p>
                  <p style={styles.itemText}>{n.mensaje}</p>
                  <p style={styles.itemMeta}>
                    {n.createdAt}
                    {n.emailEnviado ? ' · enviado por correo' : ''}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
};

const styles: Record<string, React.CSSProperties> = {
  wrapper: {
    position: 'fixed',
    top: '16px',
    right: '20px',
    zIndex: 900,
  },
  bell: {
    position: 'relative',
    width: '42px',
    height: '42px',
    borderRadius: radius.pill,
    border: `1px solid ${colors.border}`,
    background: colors.bgCard,
    color: colors.textMain,
    boxShadow: shadows.card,
    cursor: 'pointer',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
  },
  counter: {
    position: 'absolute',
    top: '-4px',
    right: '-4px',
    minWidth: '20px',
    height: '20px',
    padding: '0 5px',
    borderRadius: radius.pill,
    background: colors.danger,
    color: '#fff',
    fontSize: '11px',
    fontWeight: 700,
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
  },
  panel: {
    position: 'absolute',
    top: '50px',
    right: 0,
    width: 'min(380px, calc(100vw - 32px))',
    maxHeight: '70vh',
    overflowY: 'auto',
    background: colors.bgCard,
    border: `1px solid ${colors.border}`,
    borderRadius: radius.lg,
    boxShadow: shadows.modal,
  },
  panelHeader: {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: '8px',
    padding: '12px 14px',
    borderBottom: `1px solid ${colors.border}`,
    fontSize: '14px',
  },
  link: {
    border: 'none',
    background: 'transparent',
    color: colors.primaryDark,
    fontWeight: 700,
    fontSize: '12px',
    cursor: 'pointer',
  },
  empty: {
    margin: 0,
    padding: '16px 14px',
    fontSize: '13px',
    color: colors.textSoft,
  },
  list: {
    listStyle: 'none',
    margin: 0,
    padding: 0,
  },
  item: {
    padding: '10px 14px',
    borderBottom: `1px solid ${colors.border}`,
    cursor: 'pointer',
  },
  itemTitle: {
    margin: 0,
    fontSize: '13px',
    fontWeight: 700,
    color: colors.textMain,
  },
  itemText: {
    margin: '4px 0',
    fontSize: '13px',
    lineHeight: 1.4,
    color: colors.textMain,
  },
  itemMeta: {
    margin: 0,
    fontSize: '11px',
    color: colors.textSoft,
  },
};

export default NotificacionesBell;
