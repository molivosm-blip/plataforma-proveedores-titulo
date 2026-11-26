import React, { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Alert, Button, Card, Input, Typography } from 'antd';
import { LockOutlined } from '@ant-design/icons';
import { AuthService } from '../../../infrastructure/auth/api/authService';
import logo from '../../../assets/logodimerc.png';
import styles from './LoginPage.module.css';

const { Title, Text } = Typography;

/**
 * Paso 2: el proveedor llega desde el enlace del correo (?token=...) y
 * define su clave nueva. El enlace sirve una sola vez y vence en 60 minutos.
 */
const RestablecerClave: React.FC = () => {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';

  const [password, setPassword] = useState('');
  const [repetir, setRepetir] = useState('');
  const [loading, setLoading] = useState(false);
  const [listo, setListo] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const enviar = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);

    if (password !== repetir) {
      setError('Las claves no coinciden.');
      return;
    }

    setLoading(true);

    try {
      setListo(await AuthService.restablecerClave(token, password));
    } catch (caught: unknown) {
      const data = (caught as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        ?.response?.data;
      const detalle = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
      setError(data?.message || detalle || 'No fue posible cambiar la clave.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className={styles.container}>
      <div className={styles.contentContainer}>
        <div className={styles.logoContainer}>
          <img src={logo} alt="Logo Dimerc" className={styles.logo} />
        </div>

        <Card className={styles.loginCard}>
          <div className={styles.welcomeSection}>
            <p className={styles.eyebrow}>Portal Documental Proveedores</p>
            <Title level={3} className={styles.welcomeTitle}>
              Elige una clave nueva
            </Title>
            <Text className={styles.welcomeText}>
              Mínimo 10 caracteres, con al menos una letra y un número.
            </Text>
          </div>

          {!token && (
            <Alert
              type="warning"
              showIcon
              message="El enlace está incompleto. Solicita uno nuevo desde el inicio de sesión."
            />
          )}

          {error && <Alert type="error" message={error} showIcon style={{ marginBottom: 16 }} />}

          {listo ? (
            <Alert type="success" message={listo} showIcon />
          ) : (
            token && (
              <form onSubmit={enviar}>
                <Input.Password
                  size="large"
                  prefix={<LockOutlined />}
                  placeholder="Clave nueva"
                  value={password}
                  onChange={e => setPassword(e.target.value)}
                  autoComplete="new-password"
                  style={{ marginBottom: 16 }}
                  required
                />
                <Input.Password
                  size="large"
                  prefix={<LockOutlined />}
                  placeholder="Repite la clave"
                  value={repetir}
                  onChange={e => setRepetir(e.target.value)}
                  autoComplete="new-password"
                  style={{ marginBottom: 24 }}
                  required
                />
                <Button
                  type="primary"
                  size="large"
                  block
                  htmlType="submit"
                  loading={loading}
                  className={styles.loginButton}
                >
                  Cambiar clave
                </Button>
              </form>
            )
          )}

          <p className={styles.hint}>
            <Link to="/login">Ir al inicio de sesión</Link>
          </p>
        </Card>
      </div>
    </div>
  );
};

export default RestablecerClave;
