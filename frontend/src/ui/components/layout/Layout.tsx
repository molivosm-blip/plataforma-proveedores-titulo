import React, { useEffect } from 'react';
import Sidebar from './Sidebar';
import { useLocalStorage } from '../../../hooks/useLocalStorage';
import { usePreferenciasUI } from '../../preferencias/preferenciasUI';

interface LayoutProps {
  children: React.ReactNode;
}

const Layout: React.FC<LayoutProps> = ({ children }) => {
  const [sidebarOpen, setSidebarOpen] = useLocalStorage<boolean>(
    'sidebar-open',
    false
  );

  const [isDesktop, setIsDesktop] = useLocalStorage<boolean>(
    'is-desktop',
    false
  );

  const { menu } = usePreferenciasUI();

  useEffect(() => {
    const handleResize = () => {
      const desktop = window.innerWidth >= 1024;

      setIsDesktop(desktop);

      if (desktop && sidebarOpen && menu === 'fijo') {
        setSidebarOpen(false);
      }
    };

    handleResize();

    window.addEventListener('resize', handleResize);

    return () => {
      window.removeEventListener('resize', handleResize);
    };
  }, [setIsDesktop, setSidebarOpen, sidebarOpen, menu]);

  // Con el menú oculto, el escritorio se comporta como el móvil: botón ☰ y menú que se abre encima.
  const menuFijo = isDesktop && menu === 'fijo';

  const styles: Record<string, React.CSSProperties> = {
    container: {
      fontFamily:
        "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
      background:
        'linear-gradient(135deg, #f8fafc 0%, #ffffff 50%, #f1f5f9 100%)',
      minHeight: '100vh',
      color: '#1f2937',
      display: 'flex',
    },

    overlay: {
      position: 'fixed',
      inset: 0,
      background: 'rgba(0, 0, 0, 0.5)',
      zIndex: 999,
      opacity: 0,
      pointerEvents: 'none',
      transition: 'opacity 0.3s ease',
    },

    overlayShow: {
      opacity: 1,
      pointerEvents: 'auto',
    },

    mainContent: {
      flex: 1,
      display: 'flex',
      flexDirection: 'column',
      minWidth: 0,
      width: '100%',
    },

    mainContentDesktop: {
      marginLeft: '260px',
    },

    mobileTopBar: {
      display: menuFijo ? 'none' : 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      padding: '12px 16px',
      background: '#ffffff',
      borderBottom: '1px solid #e5e7eb',
      position: 'sticky',
      top: 0,
      zIndex: 80,
    },

    menuButton: {
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      width: '40px',
      height: '40px',
      borderRadius: '10px',
      border: '1px solid #e5e7eb',
      background: '#ffffff',
      color: '#111827',
      cursor: 'pointer',
      fontSize: '20px',
      fontWeight: 700,
    },

    mobileTitle: {
      margin: 0,
      fontSize: '16px',
      fontWeight: 800,
      color: '#111827',
    },

    main: {
      flex: 1,
      minWidth: 0,
    },
  };

  return (
    <div style={styles.container}>
      <Sidebar
        isOpen={sidebarOpen}
        isDesktop={menuFijo}
        onClose={() => setSidebarOpen(false)}
      />

      <div
        style={{
          ...styles.overlay,
          ...(sidebarOpen && !menuFijo ? styles.overlayShow : {}),
        }}
        onClick={() => setSidebarOpen(false)}
      />

      <div
        style={{
          ...styles.mainContent,
          ...(menuFijo ? styles.mainContentDesktop : {}),
        }}
      >
        {!menuFijo && (
          <div style={styles.mobileTopBar}>
            <button
              style={styles.menuButton}
              onClick={() => setSidebarOpen(true)}
              aria-label="Abrir menú"
              type="button"
            >
              ☰
            </button>

            <p style={styles.mobileTitle}>Portal de Proveedores</p>

            <div style={{ width: '40px' }} />
          </div>
        )}

        <main style={styles.main}>{children}</main>
      </div>
    </div>
  );
};

export default Layout;