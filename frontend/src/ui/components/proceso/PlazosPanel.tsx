import React, { useEffect, useState } from 'react';

import {
  ProcesoProveedorService,
  mensajeError,
  type EtapaPlazo,
} from '../../../infrastructure/auth/api/procesoProveedorService';
import { colors } from '../../theme/tokens';
import { badge, ps } from './procesoStyles';

/*
| Tiempo de cada etapa del workflow y si superó el plazo configurado.
*/

interface PlazosPanelProps {
  proveedorId: string;
}

const estadoEtapa = (etapa: EtapaPlazo) => {
  if (etapa.enEspera) {
    return { label: 'En espera', color: colors.textSoft, soft: colors.bgSoft };
  }

  if (etapa.vencida) {
    return { label: 'Fuera de plazo', color: colors.danger, soft: colors.dangerSoft };
  }

  if (etapa.fin) {
    return { label: 'Terminada', color: colors.success, soft: colors.successSoft };
  }

  return { label: 'En curso', color: colors.info, soft: colors.infoSoft };
};

const PlazosPanel: React.FC<PlazosPanelProps> = ({ proveedorId }) => {
  const [etapas, setEtapas] = useState<EtapaPlazo[] | null>(null);
  const [plazoDias, setPlazoDias] = useState(0);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    ProcesoProveedorService.getPlazos(proveedorId)
      .then(data => {
        setEtapas(data.etapas);
        setPlazoDias(data.plazoDias);
      })
      .catch((caught: unknown) => setError(mensajeError(caught, 'No se pudieron cargar los plazos.')));
  }, [proveedorId]);

  if (error) {
    return <div style={ps.error}>{error}</div>;
  }

  if (!etapas) {
    return <p style={ps.help}>Cargando…</p>;
  }

  return (
    <div>
      <p style={ps.help}>
        Cada etapa comienza cuando la anterior queda aprobada. Plazo por etapa: {plazoDias} días corridos.
      </p>

      <table style={ps.table}>
        <thead>
          <tr>
            <th style={ps.th}>Etapa</th>
            <th style={ps.th}>Estado</th>
            <th style={ps.th}>Inicio</th>
            <th style={ps.th}>Término</th>
            <th style={ps.th}>Días</th>
            <th style={ps.th}>Plazo</th>
          </tr>
        </thead>
        <tbody>
          {etapas.map(etapa => {
            const estado = estadoEtapa(etapa);

            return (
              <tr key={etapa.stepId}>
                <td style={ps.td}>{etapa.stepName}</td>
                <td style={ps.td}>{etapa.status}</td>
                <td style={ps.td}>{etapa.inicio ?? '—'}</td>
                <td style={ps.td}>{etapa.fin ?? '—'}</td>
                <td style={ps.td}>{etapa.dias ?? '—'}</td>
                <td style={ps.td}>
                  <span style={badge(estado.color, estado.soft)}>{estado.label}</span>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
};

export default PlazosPanel;
