import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';

import { useAuth } from '../../../config/useAuth';
import { useLocalStorage } from '../../../hooks/useLocalStorage';
import { useUserIntegration } from '../../../hooks/useUserIntegration';
import { clearSidebarStorage } from '../../../utils/logoutUtils';
import LogoutModal from '../modals/LogoutModal';
import NotificacionesBell from '../proceso/NotificacionesBell';
import logo from '../../../assets/logodimerc.png';

import {
  XIcon,
  ChevronDownIcon,
  ChevronRightIcon,
  UserIcon,
  SettingsIcon,
  LogOutIcon,
} from './sidebar/Icons';

import { menuItems, PAGE_CONFIGS } from './sidebar/menu';

import {
  filterMenuItemsByRole,
  getPathById,
  getRouteInfoFromPath,
  getExpandedMenusForPath,
} from '../../../utils/menuBuilder';

import { useUserPhoto } from '../../../hooks/useUserPhoto';
import { styles } from './sidebar/styles';

import type { MenuItem, RouteConfig, SubMenuItem } from '../../../types/menu.types';
import type { UserRole } from '../../../domain/auth/AuthEntity';

interface SidebarProps {
  isOpen: boolean;
  isDesktop: boolean;
  onClose: () => void;
}

const isStringArray = (value: unknown): value is string[] =>
  Array.isArray(value) && value.every(item => typeof item === 'string');

const isNumberArray = (value: unknown): value is number[] =>
  Array.isArray(value) && value.every(item => typeof item === 'number');

const getRoleLabel = (role?: string | null): string => {
  switch (role) {
    case 'pm_admin':
      return 'PM Admin';
    case 'pm_user':
      return 'PM Usuario';
    case 'proveedor':
      return 'Proveedor';
    case 'admin_portal':
      return 'Administrador Portal';
    case 'admin_area':
      return 'Administrador de Área';
    case 'usuario_area':
      return 'Usuario de Área';
    default:
      return 'Sin rol';
  }
};

const normalizeRoleInput = (role: string): string =>
  role.trim().toLowerCase();

const isAdminGlobalRole = (role?: string | null): boolean =>
  role === 'pm_admin' || role === 'admin_portal';

const isProveedorRole = (role?: string | null): boolean =>
  role === 'proveedor';

const canInviteProveedorMenu = (
  role?: string | null,
  area?: string | null
): boolean => {
  if (isAdminGlobalRole(role)) return true;
  if (role === 'pm_user') return true;

  if ((role === 'admin_area' || role === 'usuario_area') && area === 'pm') {
    return true;
  }

  return false;
};

const normalizeMenuText = (value?: string | null): string =>
  (value || '')
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');

const isProviderOnlyItem = (item: SubMenuItem): boolean => {
  const id = normalizeMenuText(item.id);
  const label = normalizeMenuText(item.label);

  return (
    id.includes('mis-solicitudes') ||
    id.includes('mis_solicitudes') ||
    id.includes('solicitudes-proveedor') ||
    label.includes('mis solicitudes')
  );
};

const isUsuariosPmItem = (item: SubMenuItem): boolean => {
  const id = normalizeMenuText(item.id);
  const label = normalizeMenuText(item.label);

  return (
    id.includes('usuarios-pm') ||
    id.includes('usuarios_pm') ||
    id.includes('pm-users') ||
    label.includes('usuarios pm')
  );
};

const isInvitarProveedorItem = (item: SubMenuItem): boolean => {
  const id = normalizeMenuText(item.id);
  const label = normalizeMenuText(item.label);

  return (
    id.includes('invitar') ||
    id.includes('crear-proveedor') ||
    id.includes('crear_proveedor') ||
    label.includes('invitar proveedor') ||
    label.includes('crear proveedor')
  );
};

const shouldShowSubMenuItem = (
  item: SubMenuItem,
  role?: string | null,
  area?: string | null
): boolean => {
  if (isProviderOnlyItem(item)) {
    return isProveedorRole(role);
  }

  if (isUsuariosPmItem(item)) {
    return isAdminGlobalRole(role);
  }

  if (isInvitarProveedorItem(item)) {
    return canInviteProveedorMenu(role, area);
  }

  return true;
};

const getFirstNavigableSubItemId = (item: MenuItem): string | null => {
  const firstSubItem = item.submenu?.[0];

  if (!firstSubItem) {
    return null;
  }

  if (firstSubItem.submenu && firstSubItem.submenu.length > 0) {
    return firstSubItem.submenu[0].id;
  }

  return firstSubItem.id;
};

const hasActiveChild = (
  item: MenuItem,
  activeMenuItem: string,
  locationPath: string
): boolean => {
  if (!item.submenu) return false;

  return item.submenu.some(subItem => {
    if (subItem.id === activeMenuItem) return true;

    const subPath = getPathById(subItem.id, menuItems, item.id);

    if (
      subPath &&
      (locationPath === subPath || locationPath.startsWith(`${subPath}/`))
    ) {
      return true;
    }

    if (subItem.submenu) {
      return subItem.submenu.some(subSubItem => {
        if (subSubItem.id === activeMenuItem) return true;

        const subSubPath = getPathById(subSubItem.id, menuItems, item.id);

        return Boolean(
          subSubPath &&
            (locationPath === subSubPath ||
              locationPath.startsWith(`${subSubPath}/`))
        );
      });
    }

    return false;
  });
};

const SubSubmenuItem = React.memo<{
  item: { id: string; label: string };
  active: boolean;
  onSelect: () => void;
}>(({ item, active, onSelect }) => (
  <button
    className="sub-submenu-item"
    style={{
      display: 'flex',
      alignItems: 'center',
      gap: '8px',
      width: '100%',
      padding: '6px 12px',
      marginBottom: '2px',
      marginLeft: '16px',
      border: 'none',
      background: active ? '#f0f9ff' : 'none',
      borderRadius: '6px',
      fontSize: '12px',
      fontWeight: '500',
      color: active ? '#0369a1' : '#9ca3af',
      cursor: 'pointer',
      transition: 'all 0.2s ease',
      textAlign: 'left',
    }}
    onClick={event => {
      event.stopPropagation();
      onSelect();
    }}
    type="button"
  >
    <span
      style={{
        width: '4px',
        height: '4px',
        borderRadius: '50%',
        backgroundColor: active ? '#0369a1' : '#d1d5db',
      }}
    />
    {item.label}
  </button>
));

SubSubmenuItem.displayName = 'SubSubmenuItem';

const SubmenuItem = React.memo<{
  item: SubMenuItem;
  activeMenuItem: string;
  expanded: boolean;
  onSelect: () => void;
  onSubSelect?: (subSubId: string) => void;
}>(({ item, activeMenuItem, expanded, onSelect, onSubSelect }) => {
  const hasSubSubmenu = !!item.submenu;
  const isActive = activeMenuItem === item.id;

  return (
    <div>
      <button
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: '8px',
          width: '100%',
          padding: '8px 16px',
          marginBottom: '4px',
          border: 'none',
          background: isActive ? '#eff6ff' : 'none',
          borderRadius: '8px',
          fontSize: '13px',
          fontWeight: '500',
          color: isActive ? '#3b82f6' : '#9ca3af',
          cursor: 'pointer',
          transition: 'all 0.2s ease',
          textAlign: 'left',
        }}
        onClick={event => {
          event.stopPropagation();
          onSelect();
        }}
        type="button"
      >
        <span>{item.label}</span>

        {hasSubSubmenu && (
          <div style={{ marginLeft: 'auto' }}>
            {expanded ? <ChevronDownIcon /> : <ChevronRightIcon />}
          </div>
        )}
      </button>

      {hasSubSubmenu && item.submenu && (
        <div
          className="submenu-container"
          style={{
            marginLeft: '16px',
            marginTop: '4px',
            marginBottom: '8px',
            overflow: 'hidden',
            transition: 'max-height 0.3s ease-out',
            maxHeight: expanded ? '300px' : '0px',
          }}
        >
          {item.submenu.map(subSubItem => (
            <SubSubmenuItem
              key={subSubItem.id}
              item={subSubItem}
              active={activeMenuItem === subSubItem.id}
              onSelect={() =>
                onSubSelect ? onSubSelect(subSubItem.id) : onSelect()
              }
            />
          ))}
        </div>
      )}
    </div>
  );
});

SubmenuItem.displayName = 'SubmenuItem';

const MenuItemComponent = React.memo<{
  item: MenuItem;
  activeMenuItem: string;
  activeParentMenu: string;
  expanded: boolean;
  expandedSubmenus: Record<string, boolean>;
  onItemClick: (item: MenuItem) => void;
  onSubmenuClick: (parentId: string, item: SubMenuItem) => void;
  onSubSubmenuClick?: (
    parentId: string,
    subItemId: string,
    subSubId: string
  ) => void;
}>(
  ({
    item,
    activeMenuItem,
    activeParentMenu,
    expanded,
    expandedSubmenus,
    onItemClick,
    onSubmenuClick,
    onSubSubmenuClick,
  }) => {
    const isActive = activeParentMenu === item.id;
    const hasSubmenu = !!item.submenu && item.submenu.length > 0;

    return (
      <div>
        <button
          style={{
            ...styles.navItem,
            ...(isActive ? styles.navItemActive : {}),
          }}
          onClick={event => {
            event.stopPropagation();
            onItemClick(item);
          }}
          type="button"
        >
          {item.icon}

          <span>{item.label}</span>

          {item.badge && <span style={styles.navBadge}>{item.badge}</span>}

          {hasSubmenu && (
            <div style={{ marginLeft: 'auto' }}>
              {expanded ? <ChevronDownIcon /> : <ChevronRightIcon />}
            </div>
          )}
        </button>

        {hasSubmenu && item.submenu && (
          <div
            style={{
              ...styles.submenu,
              ...(expanded ? styles.submenuVisible : { maxHeight: '0px' }),
            }}
          >
            {item.submenu.map(subItem => (
              <SubmenuItem
                key={subItem.id}
                item={subItem}
                activeMenuItem={activeMenuItem}
                expanded={!!expandedSubmenus[subItem.id]}
                onSelect={() => onSubmenuClick(item.id, subItem)}
                onSubSelect={subSubId =>
                  onSubSubmenuClick &&
                  onSubSubmenuClick(item.id, subItem.id, subSubId)
                }
              />
            ))}
          </div>
        )}
      </div>
    );
  }
);

MenuItemComponent.displayName = 'MenuItemComponent';

const Sidebar: React.FC<SidebarProps> = ({ isOpen, isDesktop, onClose }) => {
  const { user, logout, hasRole, role, area } = useAuth();

  const { hasUserRole, syncUserApiData, isApiDataLoaded } =
    useUserIntegration();

  const navigate = useNavigate();
  const location = useLocation();

  const checkPermission = useCallback(
    (rol: unknown): boolean => {
      if (!rol) {
        return true;
      }

      if (typeof rol === 'string') {
        return hasRole(normalizeRoleInput(rol) as UserRole);
      }

      if (isStringArray(rol)) {
        return hasRole(rol.map(item => normalizeRoleInput(item)) as UserRole[]);
      }

      if (typeof rol === 'number') {
        return hasUserRole(rol);
      }

      if (isNumberArray(rol)) {
        return rol.some(item => hasUserRole(item));
      }

      return false;
    },
    [hasRole, hasUserRole]
  );

  const [showUserMenu, setShowUserMenu] = useState(false);
  const [showLogoutModal, setShowLogoutModal] = useState(false);

  const [expandedMenus, setExpandedMenus] = useLocalStorage<
    Record<string, boolean>
  >('sidebar-expanded-menus', {});

  const [expandedSubmenus, setExpandedSubmenus] = useLocalStorage<
    Record<string, boolean>
  >('sidebar-expanded-submenus', {});

  const [activeMenuItem, setActiveMenuItem] = useLocalStorage<string>(
    'sidebar-active-menu-item',
    'dashboard'
  );

  const [activeParentMenu, setActiveParentMenu] = useLocalStorage<string>(
    'sidebar-active-parent-menu',
    'dashboard'
  );

  const {
    userPhoto,
    photoLoading,
    loadUserPhoto,
    clearUserPhoto,
    userName,
    userInitial,
  } = useUserPhoto();

  const userEmail = user?.email || 'usuario@empresa.com';
  const roleLabel = getRoleLabel(role);
  const areaLabel = area ? ` · Área: ${area}` : '';

  const filteredMenuItems = useMemo(() => {
    const baseItems = filterMenuItemsByRole(
      menuItems,
      checkPermission,
      isApiDataLoaded
    );

    return baseItems
      .map(item => {
        if (!item.submenu) {
          return item;
        }

        const filteredSubmenu = item.submenu.filter(subItem =>
          shouldShowSubMenuItem(subItem, role, area)
        );

        return {
          ...item,
          submenu: filteredSubmenu,
        };
      })
      .filter(item => {
        if (!item.submenu) return true;
        return item.submenu.length > 0;
      });
  }, [checkPermission, isApiDataLoaded, role, area]);

  const navigateToItem = useCallback(
    (itemId: string, parentId?: string) => {
      let path = getPathById(itemId, menuItems, parentId);

      if (!path) {
        const findInConfigs = (
          id: string,
          configs: Record<string, RouteConfig>
        ): string | null => {
          for (const cfgKey of Object.keys(configs)) {
            const cfg = configs[cfgKey];

            if (cfg.id === id) return cfg.path;

            if (cfg.children) {
              const found = findInConfigs(id, cfg.children);

              if (found) return found;
            }
          }

          return null;
        };

        const fallback = findInConfigs(itemId, PAGE_CONFIGS);

        if (fallback) {
          path = fallback;
        }
      }

      if (path) {
        navigate(path);
        setActiveMenuItem(itemId);

        if (parentId) {
          setActiveParentMenu(parentId);
          setExpandedMenus({ [parentId]: true });
        }

        if (!isDesktop) {
          onClose();
        }

        return;
      }

      console.warn(`No se encontró la ruta para el item: ${itemId}`);
    },
    [
      navigate,
      isDesktop,
      onClose,
      setActiveMenuItem,
      setActiveParentMenu,
      setExpandedMenus,
    ]
  );

  const handleMenuItemClick = useCallback(
    (item: MenuItem) => {
      const hasSubmenu = !!item.submenu && item.submenu.length > 0;

      if (hasSubmenu) {
        setExpandedMenus({ [item.id]: true });
        setActiveParentMenu(item.id);

        const firstSubItem = item.submenu?.[0];

        if (firstSubItem?.submenu && firstSubItem.submenu.length > 0) {
          setExpandedSubmenus({ [firstSubItem.id]: true });
        } else {
          setExpandedSubmenus({});
        }

        const firstChildId = getFirstNavigableSubItemId(item);

        if (firstChildId) {
          navigateToItem(firstChildId, item.id);
        }

        return;
      }

      setExpandedMenus({});
      setExpandedSubmenus({});
      setActiveParentMenu(item.id);
      setActiveMenuItem(item.id);
      navigateToItem(item.id);
    },
    [
      navigateToItem,
      setExpandedMenus,
      setExpandedSubmenus,
      setActiveParentMenu,
      setActiveMenuItem,
    ]
  );

  const handleSubmenuClick = useCallback(
    (parentId: string, subItem: SubMenuItem) => {
      setExpandedMenus({ [parentId]: true });
      setActiveParentMenu(parentId);

      const hasSubSubmenu = !!subItem.submenu;

      if (hasSubSubmenu) {
        const isCurrentlyExpanded = expandedSubmenus[subItem.id];

        setExpandedSubmenus(
          isCurrentlyExpanded ? {} : { [subItem.id]: true }
        );

        return;
      }

      navigateToItem(subItem.id, parentId);
    },
    [
      expandedSubmenus,
      navigateToItem,
      setExpandedMenus,
      setExpandedSubmenus,
      setActiveParentMenu,
    ]
  );

  const handleSubSubmenuClick = useCallback(
    (parentId: string, _subItemId: string, subSubId: string) => {
      setExpandedMenus({ [parentId]: true });
      setActiveParentMenu(parentId);

      navigateToItem(subSubId, parentId);
    },
    [navigateToItem, setExpandedMenus, setActiveParentMenu]
  );

  const handleLogout = useCallback(() => {
    setShowUserMenu(false);
    setShowLogoutModal(true);
  }, []);

  const confirmLogout = useCallback(async () => {
    try {
      /*
       * logout() también limpia la cuenta de Microsoft guardada por
       * MSAL; si no, el siguiente login reutiliza la misma cuenta.
       */
      await logout();
      clearSidebarStorage();
    } finally {
      setShowLogoutModal(false);
      navigate('/login');
    }
  }, [navigate, logout]);

  const cancelLogout = useCallback(() => {
    setShowLogoutModal(false);
  }, []);

  const irACuenta = useCallback(
    (seccion: 'perfil' | 'seguridad') => {
      setShowUserMenu(false);
      navigate(`/cuenta?seccion=${seccion}`);

      if (!isDesktop) {
        onClose();
      }
    },
    [navigate, isDesktop, onClose]
  );

  useEffect(() => {
    loadUserPhoto();
  }, [loadUserPhoto]);

  useEffect(() => {
    return () => {
      if (userPhoto?.startsWith('blob:')) {
        URL.revokeObjectURL(userPhoto);
      }
    };
  }, [userPhoto]);

useEffect(() => {
  const sameObject = (
    a: Record<string, boolean>,
    b: Record<string, boolean>
  ): boolean => JSON.stringify(a) === JSON.stringify(b);

  const routeInfo = getRouteInfoFromPath(location.pathname, menuItems);

  if (routeInfo) {
    setActiveParentMenu(current =>
      current === routeInfo.parentMenu ? current : routeInfo.parentMenu
    );

    setActiveMenuItem(current =>
      current === routeInfo.menuItem ? current : routeInfo.menuItem
    );
  }

  const {
    expandedMenus: newExpanded,
    expandedSubmenus: newSubExpanded,
  } = getExpandedMenusForPath(location.pathname, menuItems);

  setExpandedMenus(current =>
    sameObject(current, newExpanded) ? current : newExpanded
  );

  setExpandedSubmenus(current =>
    sameObject(current, newSubExpanded) ? current : newSubExpanded
  );
}, [
  location.pathname,
  setActiveMenuItem,
  setActiveParentMenu,
  setExpandedMenus,
  setExpandedSubmenus,
]);

  useEffect(() => {
    if (user?.email && !isApiDataLoaded) {
      syncUserApiData().catch(() => {});
    }
  }, [user?.email, isApiDataLoaded, syncUserApiData]);

  useEffect(() => {
    if (!showUserMenu) return;

    const handleClickOutside = (event: MouseEvent) => {
      if (!(event.target as Element)?.closest('.user-profile')) {
        setShowUserMenu(false);
      }
    };

    document.addEventListener('click', handleClickOutside);

    return () => {
      document.removeEventListener('click', handleClickOutside);
    };
  }, [showUserMenu]);

  return (
    <>
      <div
        style={{
          ...styles.sidebar,
          ...(isOpen && !isDesktop ? styles.sidebarOpen : {}),
          ...(isDesktop ? styles.sidebarDesktop : {}),
        }}
      >
        <div style={styles.sidebarHeader}>
          <div style={styles.logo}>
            <div style={styles.logoIcon}>
              <img
                style={{ width: '100%', height: 'auto' }}
                src={logo}
                alt="Logo"
              />
            </div>
          </div>

          <button
            style={{
              ...styles.closeBtn,
              ...(!isDesktop ? styles.closeBtnMobile : {}),
            }}
            onClick={onClose}
            aria-label="Cerrar menú"
            type="button"
          >
            <XIcon />
          </button>
        </div>

        <nav style={styles.nav} className="sidebar-nav">
          {filteredMenuItems.map(item => {
            const shouldForceExpanded =
              activeParentMenu === item.id ||
              hasActiveChild(item, activeMenuItem, location.pathname);

            return (
              <MenuItemComponent
                key={item.id}
                item={item}
                activeMenuItem={activeMenuItem}
                activeParentMenu={activeParentMenu}
                expanded={!!expandedMenus[item.id] || shouldForceExpanded}
                expandedSubmenus={expandedSubmenus}
                onItemClick={handleMenuItemClick}
                onSubmenuClick={handleSubmenuClick}
                onSubSubmenuClick={handleSubSubmenuClick}
              />
            );
          })}
        </nav>

        <div className="user-profile" style={styles.userProfile}>
          <button
            style={styles.userBtn}
            onClick={() => setShowUserMenu(!showUserMenu)}
            aria-label="Menú de usuario"
            type="button"
          >
            <div style={styles.userAvatar}>
              {userPhoto ? (
                <img
                  src={userPhoto}
                  alt={userName}
                  style={styles.userAvatarImage}
                  onError={clearUserPhoto}
                />
              ) : (
                <span>{userInitial}</span>
              )}

              {photoLoading && (
                <div
                  style={{
                    position: 'absolute',
                    inset: 0,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    backgroundColor: 'rgba(0,0,0,0.3)',
                    borderRadius: '50%',
                    fontSize: '10px',
                    color: 'white',
                  }}
                >
                  ⏳
                </div>
              )}
            </div>

            <div style={styles.userInfo}>
              <p style={styles.userName}>{userName}</p>
              <span style={styles.userEmail}>{userEmail}</span>
              <span
                style={{
                  display: 'block',
                  fontSize: '11px',
                  color: '#9ca3af',
                  marginTop: '2px',
                }}
              >
                {roleLabel}
                {areaLabel}
              </span>
            </div>

            <ChevronDownIcon />
          </button>

          <div
            style={{
              ...styles.userMenu,
              ...(showUserMenu ? styles.userMenuShow : {}),
            }}
          >
            <button
              style={styles.userMenuItem}
              type="button"
              onClick={() => irACuenta('perfil')}
            >
              <UserIcon />
              <span>Mi Perfil</span>
            </button>

            <button
              style={styles.userMenuItem}
              type="button"
              onClick={() => irACuenta('seguridad')}
            >
              <SettingsIcon />
              <span>Configuración</span>
            </button>

            <hr />

            <button
              style={{
                ...styles.userMenuItem,
                ...styles.userMenuItemDanger,
              }}
              onClick={handleLogout}
              type="button"
            >
              <LogOutIcon />
              <span>Cerrar sesión</span>
            </button>
          </div>
        </div>
      </div>

      {user && <NotificacionesBell />}

      <LogoutModal
        show={showLogoutModal}
        onConfirm={confirmLogout}
        onCancel={cancelLogout}
        userName={userName}
      />

      <style>
        {`
          .sidebar-nav::-webkit-scrollbar {
            width: 6px;
          }

          .sidebar-nav::-webkit-scrollbar-track {
            background: #f9fafb;
            border-radius: 3px;
          }

          .sidebar-nav::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 3px;
          }

          .sidebar-nav::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
          }

          .sub-submenu-item:hover {
            background: #f8fafc !important;
            color: #475569 !important;
          }

          .submenu-container {
            overflow: hidden;
            transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
          }
        `}
      </style>
    </>
  );
};

export default Sidebar;
