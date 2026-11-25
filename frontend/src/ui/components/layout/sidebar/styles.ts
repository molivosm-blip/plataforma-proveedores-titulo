import React from 'react';

export const styles: Record<string, React.CSSProperties> = {
  sidebar: {
    position: 'fixed' as const,
    top: 0,
    left: 0,
    width: '260px',
    height: '100vh',
    background: '#ffffff',
    boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
    transform: 'translateX(-100%)',
    transition: 'transform 0.3s ease-in-out',
    zIndex: 1000,
    display: 'flex',
    flexDirection: 'column' as const
  },
  
  sidebarOpen: {
    transform: 'translateX(0)'
  },
  
  sidebarDesktop: {
    position: 'fixed' as const,
    transform: 'translateX(0)',
    boxShadow: '0 0 0 1px rgba(0, 0, 0, 0.05)'
  },
  
  sidebarHeader: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    padding: '24px',
    borderBottom: '1px solid #e5e7eb'
  },
  
  logo: {
    display: 'flex',
    alignItems: 'center',
    gap: '12px'
  },
  
  logoIcon: {
    width: '100%',
    height: 'auto',
    borderRadius: '12px',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    color: 'white'
  },
  
  logoText: {
    fontSize: '18px',
    fontWeight: '700',
    color: '#111827',
    margin: 0
  },
  
  logoVersion: {
    fontSize: '12px',
    color: '#6b7280',
    margin: 0
  },
  
  closeBtn: {
    display: 'none',
    background: 'none',
    border: 'none',
    padding: '4px',
    borderRadius: '6px',
    cursor: 'pointer',
    transition: 'background 0.2s'
  },
  
  closeBtnMobile: {
    display: 'block'
  },
  
  nav: {
    flex: 1,
    padding: '24px 16px',
    overflowY: 'auto' as const,
    overflowX: 'hidden' as const
  },
  
  navItem: {
    display: 'flex',
    alignItems: 'center',
    gap: '12px',
    width: '100%',
    padding: '12px 16px',
    marginBottom: '8px',
    border: 'none',
    background: 'none',
    borderRadius: '12px',
    fontSize: '14px',
    fontWeight: '500',
    color: '#6b7280',
    cursor: 'pointer',
    transition: 'all 0.2s ease',
    textAlign: 'left' as const
  },
  
  navItemActive: {
    background: 'linear-gradient(135deg, #3b82f6, #8b5cf6)',
    color: 'white'
  },
  
  navBadge: {
    marginLeft: 'auto',
    background: '#ef4444',
    color: 'white',
    fontSize: '11px',
    padding: '2px 8px',
    borderRadius: '12px'
  },

  submenu: {
    marginLeft: '32px',
    marginTop: '4px',
    marginBottom: '8px',
    overflow: 'hidden',
    transition: 'max-height 0.3s ease-out'
  },

  submenuVisible: {
    maxHeight: '200px'
  },

  submenuItem: {
    display: 'flex',
    alignItems: 'center',
    gap: '8px',
    width: '100%',
    padding: '8px 16px',
    marginBottom: '4px',
    border: 'none',
    background: 'none',
    borderRadius: '8px',
    fontSize: '13px',
    fontWeight: '500',
    color: '#9ca3af',
    cursor: 'pointer',
    transition: 'all 0.2s ease',
    textAlign: 'left' as const
  },

  submenuItemActive: {
    background: '#eff6ff',
    color: '#3b82f6',
    fontWeight: '600'
  },

  userProfile: {
    padding: '16px 8px',
    borderTop: '1px solid #e5e7eb',
    position: 'relative' as const
  },
  
  userBtn: {
    display: 'flex',
    alignItems: 'center',
    gap: '12px',
    width: '100%',
    padding: '8px',
    border: 'none',
    background: 'none',
    borderRadius: '8px',
    cursor: 'pointer',
    transition: 'background 0.2s'
  },
  
  userAvatar: {
    width: '36px',
    height: '36px',
    background: 'linear-gradient(135deg, #3b82f6, #8b5cf6)',
    borderRadius: '50%',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    color: 'white',
    fontSize: '14px',
    fontWeight: '600',
    overflow: 'hidden',
    position: 'relative' as const
  },
  
  userAvatarImage: {
    width: '100%',
    height: '100%',
    objectFit: 'cover' as const,
    borderRadius: '50%'
  },
  
  userInfo: {
    flex: 1,
    textAlign: 'left' as const
  },
  
  userName: {
    fontSize: '14px',
    fontWeight: '600',
    color: '#111827',
    margin: 0,
    marginBottom: '2px'
  },
  
  userEmail: {
    fontSize: '12px',
    color: '#6b7280'
  },
  
  userMenu: {
    position: 'absolute' as const,
    bottom: '100%',
    left: '24px',
    right: '24px',
    background: 'white',
    borderRadius: '8px',
    boxShadow: '0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)',
    border: '1px solid #e5e7eb',
    padding: '8px',
    marginBottom: '8px',
    opacity: 0,
    transform: 'translateY(8px)',
    transition: 'all 0.2s ease',
    pointerEvents: 'none' as const
  },
  
  userMenuShow: {
    opacity: 1,
    transform: 'translateY(0)',
    pointerEvents: 'auto' as const
  },
  
  userMenuItem: {
    display: 'flex',
    alignItems: 'center',
    gap: '12px',
    width: '100%',
    padding: '8px 12px',
    border: 'none',
    background: 'none',
    borderRadius: '6px',
    fontSize: '14px',
    fontWeight: '500',
    color: '#374151',
    cursor: 'pointer',
    transition: 'background 0.2s',
    textAlign: 'left' as const
  },
  
  userMenuItemDanger: {
    color: '#dc2626'
  }
};
