import React, { useState } from 'react';

import {
  ProcesoProveedorService,
  mensajeError,
  type Declaracion,
  type TipoDeclaracion,
} from '../../../infrastructure/auth/api/procesoProveedorService';
import { ps } from './procesoStyles';

/*
|--------------------------------------------------------------------------
| Declaración digital con firma electrónica simple
|--------------------------------------------------------------------------
|
| conflicto_interes: la firma el solicitante (PM) antes de invitar.
| relacionados_pep:  la firma el representante legal del proveedor.
|
*/

interface Campo {
  key: string;
  label: string;
}

type Fila = Record<string, string>;

interface DeclaracionModalProps {
  tipo: TipoDeclaracion;
  proveedorId: string;
  razonSocial: string;
  rut: string;
  declaranteNombre: string;
  onCerrar: () => void;
  onFirmada: (declaracion: Declaracion) => void;
}

const filaVacia = (campos: Campo[]): Fila =>
  Object.fromEntries(campos.map(campo => [campo.key, '']));

const CAMPOS_RELACIONADOS: Campo[] = [
  { key: 'nombre', label: 'Nombre completo' },
  { key: 'relacion', label: 'Relación' },
  { key: 'cargo', label: 'Cargo o función' },
];

const CAMPOS_TRABAJADORES: Campo[] = [
  { key: 'nombre', label: 'Trabajador del holding' },
  { key: 'parentesco', label: 'Parentesco' },
  { key: 'cargo', label: 'Cargo' },
  { key: 'empresa', label: 'Empresa' },
];

const CAMPOS_EMPRESAS: Campo[] = [
  { key: 'razon_social', label: 'Razón social' },
  { key: 'rut', label: 'RUT' },
];

const CAMPOS_PEP: Campo[] = [
  { key: 'nombre', label: 'Nombre' },
  { key: 'cargo', label: 'Cargo o tipo de PEP' },
  { key: 'relacion', label: 'Relación' },
];

const DeclaracionModal: React.FC<DeclaracionModalProps> = ({
  tipo,
  proveedorId,
  razonSocial,
  rut,
  declaranteNombre,
  onCerrar,
  onFirmada,
}) => {
  const esConflicto = tipo === 'conflicto_interes';

  const [cargo, setCargo] = useState('');
  const [empresa, setEmpresa] = useState('');
  const [declaranteRut, setDeclaranteRut] = useState('');

  const [tieneRelacionados, setTieneRelacionados] = useState(false);
  const [relacionados, setRelacionados] = useState<Fila[]>([filaVacia(CAMPOS_RELACIONADOS)]);

  const [tieneTrabajadores, setTieneTrabajadores] = useState(false);
  const [trabajadores, setTrabajadores] = useState<Fila[]>([filaVacia(CAMPOS_TRABAJADORES)]);

  const [tieneEmpresas, setTieneEmpresas] = useState(false);
  const [empresas, setEmpresas] = useState<Fila[]>([filaVacia(CAMPOS_EMPRESAS)]);

  const [esPep, setEsPep] = useState(false);
  const [peps, setPeps] = useState<Fila[]>([filaVacia(CAMPOS_PEP)]);

  const [aceptaModelo, setAceptaModelo] = useState(false);
  const [acepta, setAcepta] = useState(false);

  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const firmar = async (): Promise<void> => {
    setError(null);
    setEnviando(true);

    const respuestas = esConflicto
      ? {
          cargo,
          empresa,
          tiene_relacionados: tieneRelacionados,
          relacionados,
          acepta,
        }
      : {
          declarante_rut: declaranteRut,
          cargo,
          tiene_trabajadores_relacionados: tieneTrabajadores,
          trabajadores_relacionados: trabajadores,
          tiene_empresas_relacionadas: tieneEmpresas,
          empresas_relacionadas: empresas,
          es_pep: esPep,
          peps,
          acepta_modelo_prevencion: aceptaModelo,
          acepta,
        };

    try {
      const declaracion = esConflicto
        ? await ProcesoProveedorService.firmarConflictoInteres(proveedorId, respuestas)
        : await ProcesoProveedorService.firmarRelacionadosPep(proveedorId, respuestas);

      onFirmada(declaracion);
    } catch (caught: unknown) {
      setError(mensajeError(caught, 'No se pudo firmar la declaración.'));
    } finally {
      setEnviando(false);
    }
  };

  return (
    <div style={ps.overlay} role="dialog" aria-modal="true">
      <div style={ps.modal}>
        <div style={ps.header}>
          <div>
            <p style={ps.eyebrow}>Declaración digital</p>
            <h2 style={ps.title}>
              {esConflicto ? 'Declaración de conflicto de interés' : 'Declaración de relacionados y PEP'}
            </h2>
            <p style={ps.subtitle}>
              {razonSocial} · {rut}
            </p>
          </div>

          <button type="button" style={ps.secondaryButton} onClick={onCerrar}>
            Cerrar
          </button>
        </div>

        {error && <div style={ps.error}>{error}</div>}

        <div style={ps.section}>
          <p style={ps.sectionTitle}>
            {esConflicto ? 'Datos del declarante' : 'Representante legal que firma'}
          </p>
          <div style={ps.grid}>
            <label style={ps.label}>
              Nombre
              <input style={ps.input} value={declaranteNombre} disabled />
            </label>

            {!esConflicto && (
              <label style={ps.label}>
                RUT
                <input
                  style={ps.input}
                  value={declaranteRut}
                  onChange={e => setDeclaranteRut(e.target.value)}
                  placeholder="12.345.678-9"
                />
              </label>
            )}

            <label style={ps.label}>
              Cargo
              <input style={ps.input} value={cargo} onChange={e => setCargo(e.target.value)} />
            </label>

            {esConflicto && (
              <label style={ps.label}>
                Empresa del holding
                <input style={ps.input} value={empresa} onChange={e => setEmpresa(e.target.value)} />
              </label>
            )}
          </div>
        </div>

        {esConflicto ? (
          <PreguntaConFilas
            titulo="Personas naturales relacionadas"
            pregunta="¿Algún familiar suyo u otra persona cercana trabaja o tiene participación societaria en la empresa proveedora en evaluación?"
            ayuda="Por ejemplo: padres, hijos, hermanos, cónyuge o pareja, primos, sobrinos, cuñados o amigos."
            valor={tieneRelacionados}
            onValor={setTieneRelacionados}
            campos={CAMPOS_RELACIONADOS}
            filas={relacionados}
            onFilas={setRelacionados}
          />
        ) : (
          <>
            <PreguntaConFilas
              titulo="Personas naturales relacionadas"
              pregunta="¿Algún director, representante legal, ejecutivo u otro colaborador de su empresa tiene un familiar u otra persona cercana que trabaje en las empresas del holding?"
              valor={tieneTrabajadores}
              onValor={setTieneTrabajadores}
              campos={CAMPOS_TRABAJADORES}
              filas={trabajadores}
              onFilas={setTrabajadores}
            />

            <PreguntaConFilas
              titulo="Empresas relacionadas"
              pregunta="¿Existen empresas relacionadas a la suya que operen o hayan operado con el holding en el último año?"
              valor={tieneEmpresas}
              onValor={setTieneEmpresas}
              campos={CAMPOS_EMPRESAS}
              filas={empresas}
              onFilas={setEmpresas}
            />

            <PreguntaConFilas
              titulo="Relación con funcionario público y PEP"
              pregunta="¿Algún dueño, directivo, representante legal o ejecutivo de su empresa, o un familiar de estos, es funcionario público nacional, extranjero o Persona Expuesta Políticamente (PEP)?"
              ayuda="PEP: personas que desempeñan o desempeñaron funciones públicas destacadas, hasta al menos un año después de terminarlas (por ejemplo ministros, parlamentarios, alcaldes, jueces o directores de empresas públicas)."
              valor={esPep}
              onValor={setEsPep}
              campos={CAMPOS_PEP}
              filas={peps}
              onFilas={setPeps}
            />

            <label style={ps.check}>
              <input type="checkbox" checked={aceptaModelo} onChange={e => setAceptaModelo(e.target.checked)} />
              Declaro conocer que las empresas del holding cuentan con un Modelo de Prevención de Delitos (Ley
              20.393) y que mi empresa no ha sido investigada ni sancionada por los delitos que contempla.
            </label>
          </>
        )}

        <label style={ps.check}>
          <input type="checkbox" checked={acepta} onChange={e => setAcepta(e.target.checked)} />
          Declaro que respondí de buena fe y me comprometo a informar cualquier cambio. Al firmar, el portal
          registra mi identidad, la fecha, mi dirección IP y un código de verificación del contenido (firma
          electrónica simple, Ley 19.799).
        </label>

        <div style={ps.footer}>
          <button type="button" style={ps.secondaryButton} onClick={onCerrar} disabled={enviando}>
            Cancelar
          </button>
          <button
            type="button"
            style={{ ...ps.primaryButton, opacity: acepta && !enviando ? 1 : 0.6 }}
            onClick={firmar}
            disabled={!acepta || enviando}
          >
            {enviando ? 'Firmando…' : 'Firmar declaración'}
          </button>
        </div>
      </div>
    </div>
  );
};

interface PreguntaConFilasProps {
  titulo: string;
  pregunta: string;
  ayuda?: string;
  valor: boolean;
  onValor: (valor: boolean) => void;
  campos: Campo[];
  filas: Fila[];
  onFilas: (filas: Fila[]) => void;
}

const PreguntaConFilas: React.FC<PreguntaConFilasProps> = ({
  titulo,
  pregunta,
  ayuda,
  valor,
  onValor,
  campos,
  filas,
  onFilas,
}) => {
  const actualizar = (index: number, key: string, texto: string): void => {
    onFilas(filas.map((fila, i) => (i === index ? { ...fila, [key]: texto } : fila)));
  };

  return (
    <div style={ps.section}>
      <p style={ps.sectionTitle}>{titulo}</p>
      <p style={ps.help}>{pregunta}</p>
      {ayuda && <p style={ps.help}>{ayuda}</p>}

      <div style={ps.radioRow}>
        <label>
          <input type="radio" checked={!valor} onChange={() => onValor(false)} /> No
        </label>
        <label>
          <input type="radio" checked={valor} onChange={() => onValor(true)} /> Sí
        </label>
      </div>

      {valor && (
        <>
          {filas.map((fila, index) => (
            <div key={index}>
              <div
                style={{
                  ...ps.rowItem,
                  gridTemplateColumns: `repeat(${campos.length}, minmax(0, 1fr))`,
                }}
              >
                {campos.map(campo => (
                  <label key={campo.key} style={ps.label}>
                    {campo.label}
                    <input
                      style={ps.input}
                      value={fila[campo.key] ?? ''}
                      onChange={e => actualizar(index, campo.key, e.target.value)}
                    />
                  </label>
                ))}
              </div>
              {filas.length > 1 && (
                <button
                  type="button"
                  style={ps.dangerLink}
                  onClick={() => onFilas(filas.filter((_, i) => i !== index))}
                >
                  Quitar fila
                </button>
              )}
            </div>
          ))}

          <button type="button" style={ps.linkButton} onClick={() => onFilas([...filas, filaVacia(campos)])}>
            + Agregar fila
          </button>
        </>
      )}
    </div>
  );
};

export default DeclaracionModal;
