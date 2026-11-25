import React from 'react';

import { useAuth } from '../../../config/useAuth';
import { useApiUserData } from '../../../hooks/useApiUserData';
import { formatRut, validateRut } from '../../../utils/rutUtils';

interface WelcomePopupProps {
  visible: boolean;
  onClose: () => void;
}

/*
|--------------------------------------------------------------------------
| Estilos
|--------------------------------------------------------------------------
*/

const styles: Record<string, React.CSSProperties> = {
  overlay: {
    position: 'fixed',
    inset: 0,
    background: 'rgba(0, 0, 0, 0.6)',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    padding: '20px',
    zIndex: 1000,
    backdropFilter: 'blur(4px)',
    animation: 'fadeIn 0.3s ease-out',
  },

  modal: {
    background: '#ffffff',
    borderRadius: '20px',
    padding: '40px',
    maxWidth: '500px',
    width: '90%',
    maxHeight: '80vh',
    overflowY: 'auto',
    boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
    textAlign: 'center',
    transform: 'scale(0.9)',
    animation: 'modalSlideIn 0.3s ease-out forwards',
    position: 'relative',
  },

  closeButton: {
    position: 'absolute',
    top: '16px',
    right: '16px',
    background: 'transparent',
    border: 'none',
    fontSize: '24px',
    cursor: 'pointer',
    color: '#9ca3af',
    padding: '4px',
    borderRadius: '50%',
    width: '32px',
    height: '32px',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    transition: 'all 0.2s ease',
  },

  icon: {
    fontSize: '64px',
    marginBottom: '24px',
    display: 'block',
  },

  title: {
    fontSize: '28px',
    fontWeight: 600,
    color: '#1f2937',
    margin: '0 0 16px 0',
    lineHeight: 1.3,
  },

  subtitle: {
    fontSize: '16px',
    color: '#6b7280',
    lineHeight: 1.6,
    margin: '0 0 24px 0',
  },

  userInfo: {
    background: '#f8fafc',
    padding: '16px',
    borderRadius: '12px',
    border: '1px solid #e5e7eb',
    marginBottom: '32px',
    textAlign: 'left',
  },

  infoRow: {
    fontSize: '14px',
    color: '#4b5563',
    marginBottom: '10px',
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    gap: '16px',
  },

  label: {
    fontWeight: 600,
    color: '#374151',
    flexShrink: 0,
  },

  value: {
    color: '#1f2937',
    fontFamily: 'monospace',
    fontSize: '14px',
    textAlign: 'right',
    overflowWrap: 'anywhere',
  },

  rutValue: {
    color: '#1f2937',
    fontFamily: 'monospace',
    fontSize: '14px',
    fontWeight: 600,
    background: '#f0f9ff',
    padding: '2px 6px',
    borderRadius: '4px',
    border: '1px solid #0ea5e9',
    textAlign: 'right',
  },

  unavailableValue: {
    color: '#ef4444',
    fontFamily: 'monospace',
    fontSize: '14px',
    fontStyle: 'italic',
    textAlign: 'right',
  },

  continueButton: {
    background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
    color: '#ffffff',
    border: 'none',
    borderRadius: '12px',
    padding: '16px 32px',
    fontSize: '16px',
    fontWeight: 500,
    cursor: 'pointer',
    transition: 'all 0.2s ease',
    boxShadow: '0 4px 15px 0 rgba(102, 126, 234, 0.3)',
    width: '100%',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    gap: '8px',
  },
};

/*
|--------------------------------------------------------------------------
| Componente
|--------------------------------------------------------------------------
*/

const WelcomePopup: React.FC<WelcomePopupProps> = ({
  visible,
  onClose,
}) => {
  const { user } = useAuth();

  /*
   * La API corporativa se consulta solamente para usuarios autenticados
   * mediante Microsoft Entra ID.
   *
   * Los proveedores locales no deberían consultarse en la API interna.
   */
  const shouldLoadApiUserData =
    visible && user?.authType === 'entra';

  const {
    userData,
    loading: userDataLoading,
  } = useApiUserData(
    user?.email || '',
    shouldLoadApiUserData
  );

  /*
  |--------------------------------------------------------------------------
  | Datos derivados
  |--------------------------------------------------------------------------
  */

  const displayName =
    user?.displayName ||
    user?.display_name ||
    user?.email ||
    'Usuario';

  const fullName = [
    userData?.nombre,
    userData?.apepat,
    userData?.apemat,
  ]
    .filter(
      (value): value is string =>
        typeof value === 'string' &&
        value.trim().length > 0
    )
    .map(value => value.trim())
    .join(' ');

  const departamento =
    typeof userData?.departamento === 'string'
      ? userData.departamento.trim()
      : '';

  const rutCompleto = userData?.rutusu
    ? `${userData.rutusu}${userData.digusu || ''}`
    : '';

  const rutFormateado = rutCompleto
    ? formatRut(rutCompleto)
    : '';

  const rutValido = rutCompleto
    ? validateRut(rutCompleto)
    : false;

  const accessDate = new Date().toLocaleString('es-CL', {
    timeZone: 'America/Santiago',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });

  /*
  |--------------------------------------------------------------------------
  | Eventos
  |--------------------------------------------------------------------------
  */

  const handleOverlayClick = (
    event: React.MouseEvent<HTMLDivElement>
  ): void => {
    if (event.target === event.currentTarget) {
      onClose();
    }
  };

  const handleKeyDown = (
    event: React.KeyboardEvent<HTMLDivElement>
  ): void => {
    if (event.key === 'Escape') {
      onClose();
    }
  };

  if (!visible) {
    return null;
  }

  /*
  |--------------------------------------------------------------------------
  | Render
  |--------------------------------------------------------------------------
  */

  return (
    <>
      <style>
        {`
          @keyframes fadeIn {
            from {
              opacity: 0;
            }

            to {
              opacity: 1;
            }
          }

          @keyframes modalSlideIn {
            from {
              opacity: 0;
              transform: scale(0.9) translateY(-10px);
            }

            to {
              opacity: 1;
              transform: scale(1) translateY(0);
            }
          }

          .welcome-popup-close:hover {
            background: #f3f4f6 !important;
            color: #374151 !important;
          }

          .welcome-popup-button:hover {
            transform: translateY(-1px);
            box-shadow:
              0 8px 25px 0 rgba(102, 126, 234, 0.4) !important;
          }

          .welcome-popup-button:focus-visible,
          .welcome-popup-close:focus-visible {
            outline: 3px solid rgba(102, 126, 234, 0.35);
            outline-offset: 2px;
          }

          @media (max-width: 520px) {
            .welcome-popup-modal {
              padding: 32px 20px !important;
              width: 100% !important;
            }
          }

          @media (prefers-reduced-motion: reduce) {
            .welcome-popup-modal,
            .welcome-popup-overlay {
              animation: none !important;
              transition: none !important;
            }
          }
        `}
      </style>

      <div
        className="welcome-popup-overlay"
        style={styles.overlay}
        onClick={handleOverlayClick}
        onKeyDown={handleKeyDown}
        role="presentation"
      >
        <div
          className="welcome-popup-modal"
          style={styles.modal}
          role="dialog"
          aria-modal="true"
          aria-labelledby="welcome-popup-title"
          aria-describedby="welcome-popup-description"
          tabIndex={-1}
        >
          <button
            type="button"
            style={styles.closeButton}
            onClick={onClose}
            className="welcome-popup-close"
            title="Cerrar"
            aria-label="Cerrar mensaje de bienvenida"
          >
            ✕
          </button>

          <div style={styles.icon} aria-hidden="true">
            ✅
          </div>

          <h2
            id="welcome-popup-title"
            style={styles.title}
          >
            ¡Bienvenido de vuelta!
          </h2>

          <p
            id="welcome-popup-description"
            style={styles.subtitle}
          >
            Hola{' '}
            <strong style={{ color: '#1f2937' }}>
              {displayName}
            </strong>
            ,
            <br />
            te has autenticado exitosamente en la plataforma.
          </p>

          <div style={styles.userInfo}>
            <div style={styles.infoRow}>
              <span style={styles.label}>
                Email:
              </span>

              <span style={styles.value}>
                {user?.email || 'No disponible'}
              </span>
            </div>

            {user?.authType === 'entra' && (
              <>
                {rutCompleto ? (
                  <div style={styles.infoRow}>
                    <span style={styles.label}>
                      RUT:
                    </span>

                    <span style={styles.rutValue}>
                      {rutFormateado}
                      {rutValido ? ' ✓' : ' ⚠️'}
                    </span>
                  </div>
                ) : (
                  <div style={styles.infoRow}>
                    <span style={styles.label}>
                      RUT:
                    </span>

                    <span style={styles.unavailableValue}>
                      {userDataLoading
                        ? 'Cargando...'
                        : 'No disponible'}
                    </span>
                  </div>
                )}

                {fullName && (
                  <div style={styles.infoRow}>
                    <span style={styles.label}>
                      Nombre completo:
                    </span>

                    <span style={styles.value}>
                      {fullName}
                    </span>
                  </div>
                )}

                {departamento && (
                  <div style={styles.infoRow}>
                    <span style={styles.label}>
                      Departamento:
                    </span>

                    <span style={styles.value}>
                      {departamento}
                    </span>
                  </div>
                )}

                {userDataLoading && !userData && (
                  <div style={styles.infoRow}>
                    <span style={styles.label}>
                      Datos adicionales:
                    </span>

                    <span style={styles.value}>
                      Cargando...
                    </span>
                  </div>
                )}
              </>
            )}

            {user?.jobTitle && (
              <div style={styles.infoRow}>
                <span style={styles.label}>
                  Cargo:
                </span>

                <span style={styles.value}>
                  {user.jobTitle}
                </span>
              </div>
            )}

            <div
              style={{
                ...styles.infoRow,
                marginBottom: 0,
              }}
            >
              <span style={styles.label}>
                Hora de acceso:
              </span>

              <span style={styles.value}>
                {accessDate}
              </span>
            </div>
          </div>

          <button
            type="button"
            style={styles.continueButton}
            onClick={onClose}
            className="welcome-popup-button"
          >
            <span aria-hidden="true">
              🚀
            </span>

            Continuar al Dashboard
          </button>
        </div>
      </div>
    </>
  );
};

export default WelcomePopup;