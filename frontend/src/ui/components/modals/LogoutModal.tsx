import React from 'react';
import { Modal } from 'antd';
import { ExclamationCircleOutlined } from '@ant-design/icons';

interface LogoutModalProps {
  show: boolean;
  onConfirm: () => void;
  onCancel: () => void;
  userName?: string;
}

const LogoutModal: React.FC<LogoutModalProps> = ({ show, onConfirm, onCancel, userName }) => {
  return (
    <Modal
      title={
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          <ExclamationCircleOutlined style={{ color: '#faad14' }} />
          <span>Cerrar Sesión</span>
        </div>
      }
      open={show}
      onOk={onConfirm}
      onCancel={onCancel}
      okText="Cerrar Sesión"
      cancelText="Cancelar"
      okButtonProps={{ danger: true }}
    >
      <p>¿Estás seguro de que deseas cerrar sesión{userName ? `, ${userName}` : ''}?</p>
      <p>Se cerrará tu sesión actual y deberás volver a ingresar para acceder al portal.</p>
    </Modal>
  );
};

export default LogoutModal;
