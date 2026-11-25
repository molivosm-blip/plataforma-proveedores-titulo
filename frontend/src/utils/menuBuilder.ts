import type { MenuItem, SubMenuItem, RouteConfig } from '../types/menu.types';

type PermissionValue = string | number | Array<string | number> | undefined;

const hasPermissionValue = (
  rol: PermissionValue,
  hasRole: (rol: PermissionValue) => boolean,
  isApiDataLoaded: boolean
): boolean => {
  if (!rol) {
    return true;
  }

  const shouldDeferRoleCheck = (value: PermissionValue): boolean => {
    if (isApiDataLoaded) {
      return false;
    }

    if (typeof value === 'number') {
      return true;
    }

    if (
      Array.isArray(value) &&
      value.length > 0 &&
      value.every(item => typeof item === 'number')
    ) {
      return true;
    }

    return false;
  };

  if (shouldDeferRoleCheck(rol)) {
    return true;
  }

  return hasRole(rol);
};

function buildSubmenuFromChildren(
  children: Record<string, RouteConfig> | undefined
): SubMenuItem[] {
  if (!children) {
    return [];
  }

  return Object.values(children).map(child => ({
    id: child.id,
    label: child.title,
    path: child.path,
    rol: child.rol,
    submenu: child.children
      ? buildSubmenuFromChildren(child.children)
      : undefined,
  }));
}

export function generateMenuItems(
  configs: Record<string, RouteConfig>
): MenuItem[] {
  return Object.values(configs).map(config => ({
    id: config.id,
    label: config.title,
    icon: config.emoji,
    path: config.path,
    rol: config.rol,
    submenu: config.children
      ? buildSubmenuFromChildren(config.children)
      : undefined,
  }));
}

export function filterMenuItemsByRole(
  items: MenuItem[],
  hasRole: (rol: PermissionValue) => boolean,
  isApiDataLoaded: boolean
): MenuItem[] {
  return items
    .map(item => {
      const itemAllowed = hasPermissionValue(
        item.rol as PermissionValue,
        hasRole,
        isApiDataLoaded
      );

      if (!itemAllowed) {
        return null;
      }

      const filteredSubmenu = item.submenu
        ? filterSubmenuByRole(item.submenu, hasRole, isApiDataLoaded)
        : undefined;

      if (item.submenu && (!filteredSubmenu || filteredSubmenu.length === 0)) {
        return null;
      }

      return {
        ...item,
        submenu: filteredSubmenu,
      } as MenuItem;
    })
    .filter((item): item is MenuItem => item !== null);
}

function filterSubmenuByRole(
  submenu: SubMenuItem[],
  hasRole: (rol: PermissionValue) => boolean,
  isApiDataLoaded: boolean
): SubMenuItem[] {
  return submenu
    .map(item => {
      const itemAllowed = hasPermissionValue(
        item.rol as PermissionValue,
        hasRole,
        isApiDataLoaded
      );

      if (!itemAllowed) {
        return null;
      }

      const filteredSubSubmenu = item.submenu
        ? filterSubmenuByRole(item.submenu, hasRole, isApiDataLoaded)
        : undefined;

      if (
        item.submenu &&
        (!filteredSubSubmenu || filteredSubSubmenu.length === 0)
      ) {
        return null;
      }

      return {
        ...item,
        submenu: filteredSubSubmenu,
      } as SubMenuItem;
    })
    .filter((item): item is SubMenuItem => item !== null);
}

export function getPathById(
  id: string,
  items: MenuItem[],
  parentId?: string
): string | null {
  if (parentId) {
    for (const item of items) {
      if (item.id === parentId && item.submenu) {
        const path = getPathFromSubmenu(id, item.submenu);

        if (path) {
          return path;
        }
      }
    }
  }

  for (const item of items) {
    if (item.id === id) {
      return item.path || null;
    }

    if (item.submenu) {
      const path = getPathFromSubmenu(id, item.submenu);

      if (path) {
        return path;
      }
    }
  }

  return null;
}

function getPathFromSubmenu(
  id: string,
  submenu: SubMenuItem[]
): string | null {
  for (const item of submenu) {
    if (item.id === id) {
      return item.path || null;
    }

    if (item.submenu) {
      const path = getPathFromSubmenu(id, item.submenu);

      if (path) {
        return path;
      }
    }
  }

  return null;
}

export function getRouteInfoFromPath(
  path: string,
  items: MenuItem[]
): { menuItem: string; parentMenu: string } | null {
  for (const item of items) {
    if (item.path === path) {
      return {
        menuItem: item.id,
        parentMenu: item.id,
      };
    }

    if (item.submenu) {
      const result = findInSubmenu(path, item.submenu, item.id);

      if (result) {
        return result;
      }
    }
  }

  for (const item of items) {
    if (item.path && item.path !== '/' && path.startsWith(`${item.path}/`)) {
      return {
        menuItem: item.id,
        parentMenu: item.id,
      };
    }
  }

  return null;
}

function findInSubmenu(
  path: string,
  submenu: SubMenuItem[],
  parentId: string
): { menuItem: string; parentMenu: string } | null {
  for (const item of submenu) {
    if (item.path === path) {
      return {
        menuItem: item.id,
        parentMenu: parentId,
      };
    }

    if (item.submenu) {
      const result = findInSubmenu(path, item.submenu, parentId);

      if (result) {
        return result;
      }
    }
  }

  return null;
}

export function getExpandedMenusForPath(
  path: string,
  items: MenuItem[]
): {
  expandedMenus: Record<string, boolean>;
  expandedSubmenus: Record<string, boolean>;
} {
  const expandedMenus: Record<string, boolean> = {};
  const expandedSubmenus: Record<string, boolean> = {};

  for (const item of items) {
    if (item.path && item.path !== '/' && path.startsWith(item.path)) {
      expandedMenus[item.id] = true;

      if (item.submenu) {
        findExpandedSubmenus(path, item.submenu, expandedSubmenus);
      }
    }
  }

  return {
    expandedMenus,
    expandedSubmenus,
  };
}

function findExpandedSubmenus(
  path: string,
  submenu: SubMenuItem[],
  result: Record<string, boolean>
): void {
  for (const item of submenu) {
    if (item.path && path.startsWith(item.path) && item.submenu) {
      result[item.id] = true;
      findExpandedSubmenus(path, item.submenu, result);
    }
  }
}

export function getMenuItemById(
  id: string,
  items: MenuItem[]
): MenuItem | null {
  for (const item of items) {
    if (item.id === id) {
      return item;
    }

    if (item.submenu) {
      const found = getSubmenuItemById(id, item.submenu);

      if (found) {
        return {
          ...item,
          submenu: [found],
        } as MenuItem;
      }
    }
  }

  return null;
}

function getSubmenuItemById(
  id: string,
  submenu: SubMenuItem[]
): SubMenuItem | null {
  for (const item of submenu) {
    if (item.id === id) {
      return item;
    }

    if (item.submenu) {
      const found = getSubmenuItemById(id, item.submenu);

      if (found) {
        return found;
      }
    }
  }

  return null;
}

export function getAllRoutes(
  items: MenuItem[]
): Array<{ id: string; path: string }> {
  const routes: Array<{ id: string; path: string }> = [];

  function extractRoutes(menuItems: MenuItem[] | SubMenuItem[]) {
    for (const item of menuItems) {
      if (item.path) {
        routes.push({
          id: item.id,
          path: item.path,
        });
      }

      if (item.submenu) {
        extractRoutes(item.submenu);
      }
    }
  }

  extractRoutes(items);

  return routes;
}

export function isValidRoute(path: string, items: MenuItem[]): boolean {
  const allRoutes = getAllRoutes(items);

  return allRoutes.some(route => route.path === path);
}

export function getBreadcrumb(
  path: string,
  items: MenuItem[]
): Array<{ id: string; label: string; path: string }> {
  const breadcrumb: Array<{ id: string; label: string; path: string }> = [];

  function findPath(
    currentPath: string,
    menuItems: MenuItem[] | SubMenuItem[],
    accumulated: Array<{ id: string; label: string; path: string }>
  ): boolean {
    for (const item of menuItems) {
      if (!item.path) {
        continue;
      }

      const newAccumulated = [
        ...accumulated,
        {
          id: item.id,
          label: item.label,
          path: item.path,
        },
      ];

      if (item.path === currentPath) {
        breadcrumb.push(...newAccumulated);
        return true;
      }

      if (
        item.submenu &&
        item.path !== '/' &&
        currentPath.startsWith(item.path)
      ) {
        const found = findPath(currentPath, item.submenu, newAccumulated);

        if (found) {
          return true;
        }
      }
    }

    return false;
  }

  findPath(path, items, []);

  return breadcrumb;
}