// types/menu.types.ts
// Tipos para el sistema de menú dinámico

import type { UserRole } from '../domain/auth/AuthEntity';

/**
 * Valor permitido para controlar visibilidad por rol/permisos.
 *
 * Soporta:
 * - Rol del portal: 'pm_admin', 'pm_user', 'admin_portal', etc.
 * - Código legacy numérico.
 * - Arrays de roles.
 * - Arrays de códigos.
 * - Arrays mixtos si fuera necesario.
 */
export type MenuPermission =
  | UserRole
  | string
  | number
  | Array<UserRole | string | number>;

/**
 * Configuración de una ruta en PAGE_CONFIGS.
 */
export interface RouteConfig {
  id: string;
  title: string;
  emoji?: string;
  path: string;

  /**
   * Se usa "rol" para coincidir con la plantilla actual.
   */
  rol?: MenuPermission;

  children?: Record<string, RouteConfig>;
}

/**
 * Item de submenú, segundo y tercer nivel.
 */
export interface SubMenuItem {
  id: string;
  label: string;
  path: string;
  rol?: MenuPermission;
  submenu?: SubMenuItem[];
}

/**
 * Item del menú principal, primer nivel.
 */
export interface MenuItem {
  id: string;
  label: string;
  icon?: string;
  path: string;
  badge?: number | string;
  rol?: MenuPermission;
  submenu?: SubMenuItem[];
}

/**
 * Información de ruta activa.
 */
export interface RouteInfo {
  menuItem: string;
  parentMenu: string;
}

/**
 * Estado de menús expandidos.
 */
export interface ExpandedMenusState {
  expandedMenus: Record<string, boolean>;
  expandedSubmenus: Record<string, boolean>;
}

/**
 * Item de breadcrumb.
 */
export interface BreadcrumbItem {
  id: string;
  label: string;
  path: string;
}

/**
 * Item de ruta simple.
 */
export interface RouteItem {
  id: string;
  path: string;
}