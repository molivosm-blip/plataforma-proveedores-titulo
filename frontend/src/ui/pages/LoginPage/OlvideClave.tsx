import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert, Button, Card, Input, Typography } from 'antd';
import { MailOutlined } from '@ant-design/icons';
import { AuthService } from '../../../infrastructure/auth/api/authService';
import logo from '../../../assets/logodimerc.png';
import styles from './LoginPage.module.css';

const { Title, Text } = Typography;

/**
 * Paso 1 de la recuperación de clave: el proveedor indica su correo y el
 * portal responde siempre lo mismo (exista o no la cuenta).
 */
const OlvideClave: React.FC = () => {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [mensaje, setMensaje] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const enviar = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setLoading(true);

    try {
      setMensaje(await AuthService.solicitarRecuperacion(email.trim()));
    } catch {
      setError('No fue posible procesar la solicitud. Intenta de nuevo en unos minutos.');
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
              Recuperar clave
            </Title>
            <Text className={styles.welcomeText}>
              Escribe el correo con el que te registraste y te enviaremos un
              enlace para elegir una clave nueva.
            </Text>
          </div>

          {error && <Alert type="error" message={error} showIcon style={{ marginBottom: 16 }} />}

          {mensaje ? (
            <Alert type="success" message={mensaje} showIcon />
          ) : (
            <form onSubmit={enviar}>
              <Input
                size="large"
                prefix={<MailOutlined />}
                placeholder="correo@empresa.cl"
                type="email"
                value={email}
                onChange={e => setEmail(e.target.value)}
                autoComplete="username"
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
                Enviar enlace
              </Button>
            </form>
          )}

          <p className={styles.hint}>
            <Link to="/login">Volver al inicio de sesión</Link>
          </p>
        </Card>
      </div>
    </div>
  );
};

export default OlvideClave;
