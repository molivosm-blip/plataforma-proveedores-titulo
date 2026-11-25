import React, { useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useMsal } from '@azure/msal-react';
import { Alert, Button, Card, Input, Typography } from 'antd';
import { LockOutlined, MailOutlined } from '@ant-design/icons';
import { useAuth } from '../../../config/useAuth';
import { LOCAL_DEMO_ENABLED } from '../../../config/msalConfig';
import { AuthService } from '../../../infrastructure/auth/api/authService';
import type { User, UserRole } from '../../../domain/auth/AuthEntity';
import logo from '../../../assets/logodimerc.png';
import mailLogo from '../../../assets/email.png';
import styles from './LoginPage.module.css';

const { Title, Text } = Typography;

type LoginMode = 'interno' | 'proveedor';

const PENDING_KEY = 'entra_login_pending';
const REDIRECT_ERROR_KEY = 'msal_redirect_error';

const normalizeEmail = (email?: string | null): string =>
  (email || '').trim().toLowerCase();

const getRedirectByRole = (role: UserRole): string =>
  role === 'proveedor' ? '/proveedores/mis-solicitudes' : '/';

/**
 * Traduce los mensajes del backend / MSAL a textos comprensibles.
 */
const getErrorMessage = (
  error: unknown,
  fallback: string
): string => {
  let raw = '';
  let status: number | undefined;

  if (error && typeof error === 'object' && 'response' in error) {
    const responseError = error as {
      response?: { data?: { message?: string }; status?: number };
    };
    status = responseError.response?.status;
    raw = responseError.response?.data?.message ?? '';
  }

  if (!raw && error instanceof Error) {
    raw = error.message;
  }

  if (
    error &&
    typeof error === 'object' &&
    'code' in error &&
    (error as { code?: string }).code === 'ERR_NETWORK'
  ) {
    return 'No se pudo conectar con el backend. Verifica que esté levantado y que VITE_PROVEEDORES_API_URL sea correcta.';
  }

  const text = raw.toLowerCase();

  if (text.includes('no registrado')) {
    return 'Tu cuenta Microsoft es válida, pero no está registrada en el portal. Solicita acceso a un administrador.';
  }
  if (text.includes('inactivo')) {
    return 'Tu usuario está inactivo. Contacta a un administrador.';
  }
  if (text.includes('permisos internos')) {
    return 'Tu usuario no tiene un rol interno asignado en el portal.';
  }
  if (text.includes('requiere validación del token')) {
    return 'El backend no tiene habilitado el modo local (MSAL_LOCAL_DEMO) y aún no valida tokens de Microsoft.';
  }
  if (text.includes('user_cancelled') || text.includes('access_denied')) {
    return 'El inicio de sesión con Microsoft fue cancelado.';
  }
  if (text.includes('aadsts65001')) {
    return 'Falta el consentimiento del administrador para el permiso access_as_user en Entra ID.';
  }
  if (text.includes('aadsts50011')) {
    return 'La URL de redirección no está registrada en la app de Entra ID (SPA).';
  }
  if (status === 401 && !raw) {
    return 'Credenciales inválidas.';
  }

  return raw || fallback;
};

const LoginPage: React.FC = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const { accounts } = useMsal();
  const { user, loginLocal, loginWithMicrosoft } = useAuth();

  const [mode, setMode] = useState<LoginMode>('interno');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const resumedRef = useRef(false);

  const from =
    (location.state as { from?: string } | null)?.from ?? null;

  const goAfterLogin = (role: UserRole): void => {
    navigate(
      from && from !== '/login' ? from : getRedirectByRole(role),
      { replace: true }
    );
  };

  /* Usuario ya autenticado */
  useEffect(() => {
    if (user) {
      goAfterLogin(user.role);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user]);

  /* Error devuelto por Microsoft durante el redirect */
  useEffect(() => {
    const redirectError = sessionStorage.getItem(REDIRECT_ERROR_KEY);

    if (redirectError) {
      sessionStorage.removeItem(REDIRECT_ERROR_KEY);
      setError(
        getErrorMessage(
          new Error(redirectError),
          'No se pudo completar el inicio de sesión con Microsoft.'
        )
      );
    }
  }, []);

  const handleMicrosoftLogin = async (): Promise<void> => {
    setIsLoading(true);
    setError(null);

    try {
      sessionStorage.setItem(PENDING_KEY, '1');
      await loginWithMicrosoft();
      sessionStorage.removeItem(PENDING_KEY);
    } catch (err: unknown) {
      if (err instanceof Error && err.message === 'REDIRECT_INITIATED') {
        return;
      }

      sessionStorage.removeItem(PENDING_KEY);
      console.error('Error en login Microsoft:', err);
      setError(
        getErrorMessage(err, 'Error al autenticar con Microsoft.')
      );
    } finally {
      setIsLoading(false);
    }
  };

  /*
   * Al volver de Microsoft la sesión del portal se completa sola:
   * antes el usuario debía presionar el botón una segunda vez.
   */
  useEffect(() => {
    if (
      resumedRef.current ||
      user ||
      accounts.length === 0 ||
      !sessionStorage.getItem(PENDING_KEY)
    ) {
      return;
    }

    resumedRef.current = true;
    setMode('interno');
    void handleMicrosoftLogin();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accounts.length, user]);

  const handleProveedorLogin = async (
    event: React.FormEvent
  ): Promise<void> => {
    event.preventDefault();
    setError(null);
    setIsLoading(true);

    try {
      const response = await AuthService.login(
        normalizeEmail(email),
        password
      );

      const authUser: User = {
        ...response.session.user,
        token: response.session.token,
        authType: 'local',
      };

      await loginLocal(authUser);
      goAfterLogin(authUser.role);
    } catch (err: unknown) {
      console.error('Error en login de proveedor:', err);
      setError(getErrorMessage(err, 'Error al iniciar sesión.'));
    } finally {
      setIsLoading(false);
    }
  };

  const changeMode = (next: LoginMode): void => {
    setMode(next);
    setError(null);
  };

  const modeClass = (target: LoginMode): string =>
    `${styles.modeButton} ${
      mode === target ? styles.modeButtonActive : ''
    }`;

  return (
    <div className={styles.container}>
      <div className={styles.backgroundElements}>
        <div className={styles.floatingCircleOne} />
        <div className={styles.floatingCircleTwo} />
        <div className={styles.floatingCircleThree} />
        <div className={styles.geometricPattern} />
      </div>

      <div className={styles.contentContainer}>
        <div className={styles.logoContainer}>
          <img src={logo} alt="Logo Dimerc" className={styles.logo} />
        </div>

        <Card className={styles.loginCard}>
          {LOCAL_DEMO_ENABLED && (
            <div className={styles.demoBadge} role="status">
              Modo local · datos de demostración
            </div>
          )}

          <div className={styles.welcomeSection}>
            <p className={styles.eyebrow}>Portal Documental Proveedores</p>
            <Title level={3} className={styles.welcomeTitle}>
              ¡Hola de nuevo!
            </Title>
            <Text className={styles.welcomeText}>
              Selecciona tu tipo de acceso para continuar.
            </Text>
          </div>

          <div
            className={styles.modeSelector}
            role="tablist"
            aria-label="Tipo de acceso"
          >
            <button
              type="button"
              role="tab"
              aria-selected={mode === 'interno'}
              onClick={() => changeMode('interno')}
              className={modeClass('interno')}
            >
              <span className={styles.modeIcon} aria-hidden="true">
                🏢
              </span>
              <span className={styles.modeTitle}>Acceso Interno</span>
              <span className={styles.modeDesc}>Microsoft 365</span>
            </button>

            <button
              type="button"
              role="tab"
              aria-selected={mode === 'proveedor'}
              onClick={() => changeMode('proveedor')}
              className={modeClass('proveedor')}
            >
              <span className={styles.modeIcon} aria-hidden="true">
                📄
              </span>
              <span className={styles.modeTitle}>Proveedor</span>
              <span className={styles.modeDesc}>Correo / Clave</span>
            </button>
          </div>

          {error && (
            <Alert
              message="No se pudo iniciar sesión"
              description={error}
              type="error"
              showIcon
              closable
              onClose={() => setError(null)}
              className={styles.errorAlert}
            />
          )}

          {mode === 'interno' && (
            <>
              <Button
                type="primary"
                size="large"
                block
                htmlType="button"
                onClick={handleMicrosoftLogin}
                loading={isLoading}
                className={styles.loginButton}
              >
                <div className={styles.buttonContent}>
                  {!isLoading && (
                    <img
                      src={mailLogo}
                      alt=""
                      className={styles.buttonIcon}
                    />
                  )}
                  <span>
                    {isLoading
                      ? 'Iniciando sesión...'
                      : 'Continuar con Microsoft'}
                  </span>
                </div>
              </Button>
              <p className={styles.hint}>
                Microsoft Entra ID valida tu identidad. Tu rol y área se
                definen en el portal.
              </p>
            </>
          )}

          {mode === 'proveedor' && (
            <form onSubmit={handleProveedorLogin}>
              <Input
                size="large"
                prefix={<MailOutlined />}
                placeholder="correo@empresa.cl"
                type="email"
                value={email}
                onChange={e => setEmail(e.target.value)}
                autoComplete="username"
                style={{ marginBottom: 16 }}
                required
              />
              <Input.Password
                size="large"
                prefix={<LockOutlined />}
                placeholder="••••••••"
                value={password}
                onChange={e => setPassword(e.target.value)}
                autoComplete="current-password"
                style={{ marginBottom: 24 }}
                required
              />
              <Button
                type="primary"
                size="large"
                block
                htmlType="submit"
                loading={isLoading}
                className={styles.loginButton}
              >
                {isLoading ? 'Verificando...' : 'Ingresar al Portal'}
              </Button>
              <p className={styles.hint}>
                ¿Primera vez? Usa el enlace de activación que recibiste por
                correo.
              </p>
            </form>
          )}

          <div className={styles.securityNote}>
            <Text className={styles.securityText}>
              🔒 Conexión segura protegida
            </Text>
          </div>
        </Card>

        <div className={styles.footer}>
          <Text className={styles.footerText}>
            © {new Date().getFullYear()} Dimerc S.A.
          </Text>
        </div>
      </div>
    </div>
  );
};

export default LoginPage;
