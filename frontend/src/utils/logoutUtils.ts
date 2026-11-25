// Utilidades de limpieza de storage al cerrar sesión

/**
 * Limpia el estado guardado del sidebar (menús expandidos, ítem activo).
 * La sesión y la caché de MSAL las limpia AuthContext.logout().
 */
export const clearSidebarStorage = (): void => {
  localStorage.removeItem('sidebar-expanded-menus');
  localStorage.removeItem('sidebar-expanded-submenus');
  localStorage.removeItem('sidebar-active-menu-item');
  localStorage.removeItem('sidebar-active-parent-menu');
  localStorage.removeItem('sidebar-open');
  localStorage.removeItem('is-desktop');
};
