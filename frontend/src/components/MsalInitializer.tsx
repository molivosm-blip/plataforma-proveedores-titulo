import React, { useEffect, useRef, useState } from 'react';
import { Spin } from 'antd';
import { msalInstance } from '../config/di';

interface MsalInitializerProps {
  children: React.ReactNode;
}

const MsalInitializer: React.FC<MsalInitializerProps> = ({ children }) => {
  const [isInitialized, setIsInitialized] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const hasInitializedRef = useRef(false);

  useEffect(() => {
    const initializeMsal = async () => {
      if (hasInitializedRef.current) {
        return;
      }

      hasInitializedRef.current = true;

      try {
        await msalInstance.initialize();

        /*
         * Procesa cualquier resultado pendiente de redirect.
         * Si no hay redirect pendiente, retorna null.
         */
        try {
          const redirectResult =
            await msalInstance.handleRedirectPromise();

          if (redirectResult?.account) {
            msalInstance.setActiveAccount(
              redirectResult.account
            );
          }
        } catch (redirectError) {
          /*
           * Errores típicos: el usuario canceló, rechazó el consentimiento
           * o la cuenta no pertenece al tenant. No deben bloquear la app:
           * se guarda el mensaje y la pantalla de login lo muestra.
           */
          console.warn(
            'Error en el redirect de Microsoft:',
            redirectError
          );

          const message =
            redirectError instanceof Error
              ? redirectError.message
              : 'No se pudo completar el inicio de sesión con Microsoft.';

          sessionStorage.setItem(
            'msal_redirect_error',
            message
          );
          sessionStorage.removeItem('entra_login_pending');
        }

        setIsInitialized(true);
      } catch (err) {
        console.error('Error inicializando MSAL:', err);
        setError('Error inicializando el sistema de autenticación');
      }
    };

    initializeMsal();
  }, []);

  if (error) {
    return (
      <div style={styles.centeredContainer}>
        <h2 style={styles.errorTitle}>Error de inicialización</h2>
        <p style={styles.errorText}>{error}</p>

        <button
          type="button"
          style={styles.reloadButton}
          onClick={() => window.location.reload()}
        >
          Recargar página
        </button>
      </div>
    );
  }

  if (!isInitialized) {
    return (
      <div style={styles.centeredContainer}>
        <Spin size="large" />
        <p style={styles.loadingText}>
          Inicializando sistema de autenticación...
        </p>
      </div>
    );
  }

  return <>{children}</>;
};

const styles: Record<string, React.CSSProperties> = {
  centeredContainer: {
    display: 'flex',
    justifyContent: 'center',
    alignItems: 'center',
    height: '100vh',
    flexDirection: 'column',
    padding: '24px',
    textAlign: 'center',
    background: '#f8fafc',
    color: '#111827',
  },

  loadingText: {
    marginTop: 16,
    color: '#4b5563',
    fontSize: '14px',
  },

  errorTitle: {
    margin: '0 0 8px 0',
    fontSize: '22px',
    fontWeight: 700,
    color: '#991b1b',
  },

  errorText: {
    margin: '0 0 16px 0',
    color: '#4b5563',
    fontSize: '14px',
  },

  reloadButton: {
    border: 'none',
    background: '#2563eb',
    color: '#ffffff',
    padding: '10px 14px',
    borderRadius: '8px',
    cursor: 'pointer',
    fontWeight: 700,
  },
};

export default MsalInitializer;

