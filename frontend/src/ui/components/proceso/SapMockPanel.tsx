import React, { useCallback, useEffect, useState } from 'react';

import {
  ProcesoProveedorService,
  mensajeError,
  type SapEstado,
} from '../../../infrastructure/auth/api/procesoProveedorService';
import { colors } from '../../theme/tokens';
import { badge, ps } from './procesoStyles';

/*
|--------------------------------------------------------------------------
| Servicio simulado de SAP (Datos Maestros)
|--------------------------------------------------------------------------
|
| Muestra el mensaje que se enviaría a SAP y registra la respuesta
| simulada. No existe conexión con el ERP.
|
*/

const ESTADOS = {
  exitoso: { label: 'Exitoso', color: colors.success, soft: colors.successSoft },
  observado: { label: 'Observado', color: colors.warning, soft: colors.warningSoft },
  error: { label: 'Error', color: colors.danger, soft: colors.dangerSoft },
};

interface SapMockPanelProps {
  proveedorId: string;
}

const SapMockPanel: React.FC<SapMockPanelProps> = ({ proveedorId }) => {
  const [estado, setEstado] = useState<SapEstado | null>(null);
  const [enviando, setEnviando] = useState(false);
  const [verPayload, setVerPayload] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const cargar = useCallback(async (): Promise<void> => {
    try {
      setEstado(await ProcesoProveedorService.getSap(proveedorId));
    } catch (caught: unknown) {
      setError(mensajeError(caught, 'No se pudo cargar la información de SAP.'));
    }
  }, [proveedorId]);

  useEffect(() => {
    void cargar();
  }, [cargar]);

  const enviar = async (): Promise<void> => {
    setError(null);
    setEnviando(true);

    try {
      await ProcesoProveedorService.enviarSap(proveedorId);
      await cargar();
    } catch (caught: unknown) {
      setError(mensajeError(caught, 'No se pudo enviar el proveedor.'));
    } finally {
      setEnviando(false);
    }
  };

  if (!estado) {
    return error ? <div style={ps.error}>{error}</div> : <p style={ps.help}>Cargando…</p>;
  }

  return (
    <div>
      <p style={ps.help}>
        Servicio simulado: reproduce el intercambio diseñado para una futura integración con SAP. No crea el
        proveedor en el sistema corporativo.
      </p>

      {error && <div style={ps.error}>{error}</div>}

      {estado.motivoBloqueo && (
        <div style={{ ...ps.error, background: colors.warningSoft, color: colors.warning }}>{estado.motivoBloqueo}</div>
      )}

      <div style={ps.footer}>
        <button type="button" style={ps.secondaryButton} onClick={() => setVerPayload(v => !v)}>
          {verPayload ? 'Ocultar mensaje' : 'Ver mensaje a enviar'}
        </button>
        <button
          type="button"
          style={{ ...ps.primaryButton, opacity: estado.motivoBloqueo || enviando ? 0.6 : 1 }}
          onClick={enviar}
          disabled={Boolean(estado.motivoBloqueo) || enviando}
        >
          {enviando ? 'Enviando…' : 'Enviar a SAP (simulado)'}
        </button>
      </div>

      {verPayload && (
        <pre style={{ ...ps.section, ...ps.mono, whiteSpace: 'pre-wrap', marginTop: '12px' }}>
          {JSON.stringify(estado.payload, null, 2)}
        </pre>
      )}

      <div style={{ ...ps.section, marginTop: '12px' }}>
        <p style={ps.sectionTitle}>Envíos registrados</p>
        {estado.envios.length === 0 ? (
          <p style={ps.help}>Aún no hay envíos.</p>
        ) : (
          <table style={ps.table}>
            <thead>
              <tr>
                <th style={ps.th}>Fecha</th>
                <th style={ps.th}>Estado</th>
                <th style={ps.th}>Respuesta</th>
                <th style={ps.th}>N° proveedor</th>
              </tr>
            </thead>
            <tbody>
              {estado.envios.map(envio => (
                <tr key={envio.id}>
                  <td style={ps.td}>{envio.createdAt}</td>
                  <td style={ps.td}>
                    <span style={badge(ESTADOS[envio.estado].color, ESTADOS[envio.estado].soft)}>
                      {ESTADOS[envio.estado].label}
                    </span>
                  </td>
                  <td style={ps.td}>
                    {envio.respuesta?.codigo && <span style={ps.mono}>{envio.respuesta.codigo} · </span>}
                    {envio.respuesta?.mensaje}
                  </td>
                  <td style={ps.td}>{envio.numeroProveedorSap ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
};

export default SapMockPanel;
