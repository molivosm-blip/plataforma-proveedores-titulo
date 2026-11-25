import React from 'react';
import ReactDOM from 'react-dom/client';
import { MsalProvider } from '@azure/msal-react';

import { AppRouter } from './config/router';
import { AuthProvider } from './config/AuthContext';
import { msalInstance } from './config/di';
import MsalInitializer from './components/MsalInitializer';
import { iniciarPreferenciasUI } from './ui/preferencias/preferenciasUI';

iniciarPreferenciasUI();

const rootElement = document.getElementById('root');

if (!rootElement) {
  throw new Error('No se encontró el elemento root en index.html');
}

const root = ReactDOM.createRoot(rootElement);

root.render(
  <React.StrictMode>
    <MsalProvider instance={msalInstance}>
      <MsalInitializer>
        <AuthProvider>
          <AppRouter />
        </AuthProvider>
      </MsalInitializer>
    </MsalProvider>
  </React.StrictMode>
);