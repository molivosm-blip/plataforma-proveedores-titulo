import { useSyncExternalStore } from 'react';

/*
|--------------------------------------------------------------------------
| Preferencias de apariencia
|--------------------------------------------------------------------------
|
| Se guardan en este navegador (localStorage), no en el servidor:
| - tema: claro, oscuro o el del sistema operativo.
| - menu: fijo a la izquierda u oculto (se abre con el botón ☰).
|
| El tema oscuro invierte los colores de la página con un filtro CSS y
| devuelve a su color original las imágenes (los colores muy saturados, como
| el logo, quedan algo más apagados). Así funciona en todas las pantallas sin
| duplicar estilos.
|
*/

export type Tema = 'claro' | 'oscuro' | 'sistema';
export type ModoMenu = 'fijo' | 'oculto';

export interface PreferenciasUI {
  tema: Tema;
  menu: ModoMenu;
}

const CLAVE = 'ui-preferencias';
const ID_ESTILO = 'ui-tema-oscuro';

const POR_DEFECTO: PreferenciasUI = { tema: 'claro', menu: 'fijo' };

const CSS_OSCURO = `
html[data-tema="oscuro"] {
  background: #ffffff;
  filter: invert(0.92) hue-rotate(180deg);
}
html[data-tema="oscuro"] img,
html[data-tema="oscuro"] video,
html[data-tema="oscuro"] canvas {
  filter: invert(1) hue-rotate(180deg);
}
`;

const oyentes = new Set<() => void>();
let cacheCrudo: string | null | undefined;
let cacheValor: PreferenciasUI = POR_DEFECTO;

const esTema = (v: unknown): v is Tema => v === 'claro' || v === 'oscuro' || v === 'sistema';
const esMenu = (v: unknown): v is ModoMenu => v === 'fijo' || v === 'oculto';

const leerCrudo = (): string | null => {
  try {
    return window.localStorage.getItem(CLAVE);
  } catch {
    return null;
  }
};

export const leerPreferencias = (): PreferenciasUI => {
  const crudo = leerCrudo();

  if (crudo === cacheCrudo) {
    return cacheValor;
  }

  let valor = POR_DEFECTO;

  try {
    const p = crudo ? JSON.parse(crudo) : null;
    valor = {
      tema: esTema(p?.tema) ? p.tema : POR_DEFECTO.tema,
      menu: esMenu(p?.menu) ? p.menu : POR_DEFECTO.menu,
    };
  } catch {
    valor = POR_DEFECTO;
  }

  cacheCrudo = crudo;
  cacheValor = valor;

  return valor;
};

const sistemaPrefiereOscuro = (): boolean =>
  typeof window !== 'undefined' &&
  typeof window.matchMedia === 'function' &&
  window.matchMedia('(prefers-color-scheme: dark)').matches;

export const aplicarTema = (tema: Tema = leerPreferencias().tema): void => {
  if (typeof document === 'undefined') return;

  if (!document.getElementById(ID_ESTILO)) {
    const estilo = document.createElement('style');
    estilo.id = ID_ESTILO;
    estilo.textContent = CSS_OSCURO;
    document.head.appendChild(estilo);
  }

  const oscuro = tema === 'oscuro' || (tema === 'sistema' && sistemaPrefiereOscuro());

  document.documentElement.dataset.tema = oscuro ? 'oscuro' : 'claro';
};

export const guardarPreferencias = (cambios: Partial<PreferenciasUI>): void => {
  const nuevo = { ...leerPreferencias(), ...cambios };

  try {
    window.localStorage.setItem(CLAVE, JSON.stringify(nuevo));
  } catch {
    // Sin almacenamiento disponible: el cambio vale solo hasta recargar.
    cacheCrudo = undefined;
    cacheValor = nuevo;
  }

  aplicarTema(nuevo.tema);
  oyentes.forEach((oyente) => oyente());
};

/** Aplica el tema guardado al iniciar la app y sigue al sistema si así se eligió. */
export const iniciarPreferenciasUI = (): void => {
  aplicarTema();

  if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
    window
      .matchMedia('(prefers-color-scheme: dark)')
      .addEventListener('change', () => {
        if (leerPreferencias().tema === 'sistema') aplicarTema('sistema');
      });
  }

  window.addEventListener('storage', (e) => {
    if (e.key === CLAVE) {
      aplicarTema();
      oyentes.forEach((oyente) => oyente());
    }
  });
};

const suscribir = (oyente: () => void): (() => void) => {
  oyentes.add(oyente);
  return () => oyentes.delete(oyente);
};

export const usePreferenciasUI = (): PreferenciasUI =>
  useSyncExternalStore(suscribir, leerPreferencias, () => POR_DEFECTO);
