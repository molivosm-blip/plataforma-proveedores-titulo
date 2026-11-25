import React, { useCallback, useEffect, useState } from 'react';

import {
  ProcesoProveedorService,
  mensajeError,
  type EvaluacionCrediticia,
  type ResultadoDicom,
} from '../../../infrastructure/auth/api/procesoProveedorService';
import { descargarArchivoDocumento } from '../../../infrastructure/auth/api/documentosArchivoService';
import { ESTADO_REGLA, SEMAFORO, badge, ps } from './procesoStyles';

/*
|--------------------------------------------------------------------------
| Evaluación crediticia (Legal Crediticia)
|--------------------------------------------------------------------------
|
| El analista registra lo que revisó fuera del portal (inicio de
| actividades en el SII y resultado DICOM) y el sistema aplica las reglas
| del procedimiento sobre los documentos, las declaraciones y las
| consultas Gesintel registradas. El semáforo es una recomendación.
|
*/

/*
 * "no_consultado" significa automático: el backend usa la última consulta
 * DICOM registrada para la empresa o el representante legal.
 */
const OPCIONES_DICOM: Array<{ value: ResultadoDicom; label: string }> = [
  { value: 'no_consultado', label: 'Automático (última consulta DICOM)' },
  { value: 'sin_morosidad', label: 'Sin morosidad' },
  { value: 'con_morosidad', label: 'Con morosidad o protestos' },
];

interface EvaluacionCrediticiaPanelProps {
  proveedorId: string;
}

const EvaluacionCrediticiaPanel: React.FC<EvaluacionCrediticiaPanelProps> = ({ proveedorId }) => {
  const [evaluaciones, setEvaluaciones] = useState<EvaluacionCrediticia[]>([]);
  const [cargando, setCargando] = useState(true);
  const [procesando, setProcesando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [exito, setExito] = useState<string | null>(null);

  const [fechaInicio, setFechaInicio] = useState('');
  const [dicomEmpresa, setDicomEmpresa] = useState<ResultadoDicom>('no_consultado');
  const [dicomRepresentante, setDicomRepresentante] = useState<ResultadoDicom>('no_consultado');
  const [observaciones, setObservaciones] = useState('');

  const cargar = useCallback(async (): Promise<void> => {
    setCargando(true);

    try {
      const data = await ProcesoProveedorService.getEvaluaciones(proveedorId);
      setEvaluaciones(data);

      const ultima = data[0];

      if (ultima) {
        setFechaInicio(ultima.fechaInicioActividades ?? '');
      }
    } catch (caught: unknown) {
      setError(mensajeError(caught, 'No se pudo cargar la evaluación crediticia.'));
    } finally {
      setCargando(false);
    }
  }, [proveedorId]);

  useEffect(() => {
    void cargar();
  }, [cargar]);

  const ejecutar = async (accion: () => Promise<string>): Promise<void> => {
    setError(null);
    setExito(null);
    setProcesando(true);

    try {
      setExito(await accion());
      await cargar();
    } catch (caught: unknown) {
      setError(mensajeError(caught, 'No se pudo completar la acción.'));
    } finally {
      setProcesando(false);
    }
  };

  const evaluar = (): Promise<void> =>
    ejecutar(async () => {
      const evaluacion = await ProcesoProveedorService.crearEvaluacion(proveedorId, {
        fecha_inicio_actividades: fechaInicio || null,
        dicom_resultado: dicomEmpresa,
        dicom_representante_resultado: dicomRepresentante,
        observaciones,
      });
      setObservaciones('');
      return `Evaluación registrada: ${SEMAFORO[evaluacion.resultado].label}.`;
    });

  const reevaluar = (): Promise<void> =>
    ejecutar(async () => {
      const evaluacion = await ProcesoProveedorService.reevaluar(proveedorId);
      return `Re-evaluación registrada: ${SEMAFORO[evaluacion.resultado].label}.`;
    });

  const informe = (evaluacionId: string): Promise<void> =>
    ejecutar(async () => {
      const documentoId = await ProcesoProveedorService.generarInforme(proveedorId, evaluacionId);
      if (documentoId) {
        await descargarArchivoDocumento(documentoId, 'informe_crediticio.pdf');
      }
      return 'Informe generado y guardado en los documentos del proveedor.';
    });

  const ultima = evaluaciones[0];

  return (
    <div>
      {error && <div style={ps.error}>{error}</div>}
      {exito && <div style={ps.success}>{exito}</div>}

      <div style={ps.section}>
        <p style={ps.sectionTitle}>Datos revisados por el analista</p>
        <p style={ps.help}>
          Registra la fecha de inicio de actividades que revisaste en el SII. El resultado DICOM se lee
          automáticamente de la última consulta hecha en "Usar APIs" (empresa y persona); cámbialo solo si
          necesitas corregirlo. El resto de las reglas se calcula con los documentos, las declaraciones firmadas
          y las consultas Gesintel.
        </p>

        <div style={ps.grid}>
          <label style={ps.label}>
            Inicio de actividades (SII)
            <input style={ps.input} type="date" value={fechaInicio} onChange={e => setFechaInicio(e.target.value)} />
          </label>

          <label style={ps.label}>
            DICOM empresa
            <select
              style={ps.input}
              value={dicomEmpresa}
              onChange={e => setDicomEmpresa(e.target.value as ResultadoDicom)}
            >
              {OPCIONES_DICOM.map(o => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </label>

          <label style={ps.label}>
            DICOM representante legal
            <select
              style={ps.input}
              value={dicomRepresentante}
              onChange={e => setDicomRepresentante(e.target.value as ResultadoDicom)}
            >
              {OPCIONES_DICOM.map(o => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </label>
        </div>

        <label style={{ ...ps.label, marginTop: '12px' }}>
          Observaciones
          <textarea
            style={{ ...ps.input, minHeight: '64px' }}
            value={observaciones}
            onChange={e => setObservaciones(e.target.value)}
          />
        </label>

        <div style={ps.footer}>
          {ultima && (
            <button type="button" style={ps.secondaryButton} onClick={reevaluar} disabled={procesando}>
              Re-evaluar ahora
            </button>
          )}
          <button type="button" style={ps.primaryButton} onClick={evaluar} disabled={procesando}>
            {procesando ? 'Procesando…' : 'Evaluar'}
          </button>
        </div>
      </div>

      {cargando ? (
        <p style={ps.help}>Cargando evaluaciones…</p>
      ) : !ultima ? (
        <p style={ps.help}>Este proveedor aún no tiene evaluaciones crediticias.</p>
      ) : (
        <>
          <div style={ps.section}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '12px', alignItems: 'center' }}>
              <p style={{ ...ps.sectionTitle, margin: 0 }}>
                Última evaluación{' '}
                <span style={badge(SEMAFORO[ultima.resultado].color, SEMAFORO[ultima.resultado].soft)}>
                  {SEMAFORO[ultima.resultado].label}
                </span>
              </p>
              <button type="button" style={ps.secondaryButton} onClick={() => informe(ultima.id)} disabled={procesando}>
                Generar informe PDF
              </button>
            </div>
            <p style={ps.help}>
              {ultima.origen === 'reevaluacion' ? 'Re-evaluación periódica' : 'Evaluación manual'} ·{' '}
              {ultima.creadoPor} · {ultima.createdAt}
            </p>

            <table style={ps.table}>
              <thead>
                <tr>
                  <th style={ps.th}>Regla</th>
                  <th style={ps.th}>Estado</th>
                  <th style={ps.th}>Detalle</th>
                </tr>
              </thead>
              <tbody>
                {ultima.reglas.map(regla => (
                  <tr key={regla.clave}>
                    <td style={ps.td}>{regla.nombre}</td>
                    <td style={ps.td}>
                      <span style={badge(ESTADO_REGLA[regla.estado].color, ESTADO_REGLA[regla.estado].soft)}>
                        {ESTADO_REGLA[regla.estado].label}
                      </span>
                    </td>
                    <td style={ps.td}>{regla.detalle}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {evaluaciones.length > 1 && (
            <div style={ps.section}>
              <p style={ps.sectionTitle}>Historial de evaluaciones</p>
              <table style={ps.table}>
                <tbody>
                  {evaluaciones.slice(1).map(e => (
                    <tr key={e.id}>
                      <td style={ps.td}>{e.createdAt}</td>
                      <td style={ps.td}>{e.origen === 'reevaluacion' ? 'Re-evaluación' : 'Manual'}</td>
                      <td style={ps.td}>
                        <span style={badge(SEMAFORO[e.resultado].color, SEMAFORO[e.resultado].soft)}>
                          {SEMAFORO[e.resultado].label}
                        </span>
                      </td>
                      <td style={ps.td}>{e.creadoPor}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  );
};

export default EvaluacionCrediticiaPanel;
