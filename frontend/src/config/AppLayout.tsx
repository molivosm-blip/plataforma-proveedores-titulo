import React from 'react';
import { Outlet } from 'react-router-dom';
import Layout from '../ui/components/layout/Layout';
import WelcomePopup from '../ui/components/modals/WelcomePopup';
import { useWelcomePopup } from '../hooks/useWelcomePopup';
import { useAuth } from './useAuth';
const AppLayout: React.FC = () => {
  const { isAuthenticated } = useAuth();
  const { showWelcomePopup, handleCloseWelcome } = useWelcomePopup();

  return (
    <Layout>
      {isAuthenticated && (
        <WelcomePopup
          visible={showWelcomePopup}
          onClose={handleCloseWelcome}
        />
      )}

      <Outlet />
    </Layout>
  );
};

export default AppLayout;
