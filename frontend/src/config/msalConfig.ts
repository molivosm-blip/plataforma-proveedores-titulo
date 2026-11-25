import {
  BrowserCacheLocation,
  LogLevel,
  type Configuration,
  type EndSessionRequest,
  type RedirectRequest,
  type SilentRequest,
} from '@azure/msal-browser';

/*
|--------------------------------------------------------------------------
| Variables de entorno
|--------------------------------------------------------------------------
*/

const tenantId =
  import.meta.env.VITE_AZURE_TENANT_ID?.trim();

const clientId =
  import.meta.env.VITE_AZURE_CLIENT_ID?.trim();

if (!tenantId) {
  throw new Error(
    'Falta definir VITE_AZURE_TENANT_ID.'
  );
}

if (!clientId) {
  throw new Error(
    'Falta definir VITE_AZURE_CLIENT_ID.'
  );
}

/*
 * Scope de la API del portal (access_as_user).
 * Es opcional: si no se define, el frontend solo envía el email al backend
 * (válido únicamente en modo local).
 */
export const API_SCOPE: string | undefined =
  import.meta.env.VITE_AZURE_API_SCOPE?.trim() ||
  undefined;

/*
 * Modo local / demo.
 */
export const LOCAL_DEMO_ENABLED: boolean =
  import.meta.env.VITE_LOCAL_DEMO === 'true';

/*
|--------------------------------------------------------------------------
| URLs de autenticación
|--------------------------------------------------------------------------
*/

const redirectUri =
  typeof window !== 'undefined'
    ? window.location.origin
    : '/';

const authority =
  `https://login.microsoftonline.com/${tenantId}`;

/*
|--------------------------------------------------------------------------
| Configuración principal de MSAL
|--------------------------------------------------------------------------
*/

export const msalConfig: Configuration = {
  auth: {
    clientId,
    authority,
    redirectUri,
    postLogoutRedirectUri: redirectUri,

    /*
     * Después del redirect, MSAL regresa a la ruta
     * desde la que se inició el login.
     */
    navigateToLoginRequestUrl: true,
  },

  cache: {
    /*
     * LocalStorage permite compartir la sesión MSAL
     * entre pestañas del mismo navegador.
     *
     * Si se desea limitar la sesión a una sola pestaña,
     * se puede usar BrowserCacheLocation.SessionStorage.
     */
    cacheLocation:
      BrowserCacheLocation.LocalStorage,

    storeAuthStateInCookie: false,
  },

  system: {
    windowHashTimeout: 60000,
    iframeHashTimeout: 6000,
    loadFrameTimeout: 0,

    loggerOptions: {
      loggerCallback: (
        level,
        message,
        containsPii
      ): void => {
        if (containsPii) {
          return;
        }

        switch (level) {
          case LogLevel.Error:
            console.error(
              '[MSAL Error]',
              message
            );
            break;

          case LogLevel.Warning:
            if (import.meta.env.DEV) {
              console.warn(
                '[MSAL Warning]',
                message
              );
            }
            break;

          case LogLevel.Info:
            if (import.meta.env.DEV) {
              console.info(
                '[MSAL Info]',
                message
              );
            }
            break;

          case LogLevel.Verbose:
            if (import.meta.env.DEV) {
              console.debug(
                '[MSAL Verbose]',
                message
              );
            }
            break;

          default:
            break;
        }
      },

      piiLoggingEnabled: false,

      logLevel:
        import.meta.env.DEV
          ? LogLevel.Verbose
          : LogLevel.Warning,
    },
  },
};

/*
|--------------------------------------------------------------------------
| Solicitud de login
|--------------------------------------------------------------------------
|
| User.Read permite consultar:
| - GET /me
| - GET /me/photo/$value
|
| Los permisos role y area del portal no deben obtenerse desde
| estos scopes. Deben provenir del backend y la tabla Users.
|--------------------------------------------------------------------------
*/

export const loginRequest: RedirectRequest = {
  /*
   * Un access token pertenece a un único recurso. Por eso el login pide
   * el scope de la API del portal y deja User.Read (Microsoft Graph)
   * como consentimiento adicional; el token de Graph se obtiene
   * después, de forma silenciosa, con graphRequest.
   */
  scopes: API_SCOPE
    ? ['openid', 'profile', API_SCOPE]
    : ['openid', 'profile', 'User.Read'],
  extraScopesToConsent: API_SCOPE
    ? ['User.Read']
    : undefined,
  prompt: 'select_account',
};

/*
|--------------------------------------------------------------------------
| Solicitudes silenciosas
|--------------------------------------------------------------------------
*/
export const apiTokenRequest: SilentRequest | null =
  API_SCOPE
    ? { scopes: [API_SCOPE], forceRefresh: false }
    : null;

export const graphRequest: SilentRequest = {
  scopes: ['User.Read'],
  forceRefresh: false,
};

/*
 * Se mantiene por compatibilidad con código existente.
 */
export const silentRequest: SilentRequest =
  graphRequest;

/*
|--------------------------------------------------------------------------
| Solicitud de logout
|--------------------------------------------------------------------------
*/

export const logoutRequest: EndSessionRequest = {
  postLogoutRedirectUri: redirectUri,
};

/*
|--------------------------------------------------------------------------
| Scopes disponibles
|--------------------------------------------------------------------------
*/

export const apiScopes = {
  graph: ['User.Read'],
  custom: API_SCOPE ? [API_SCOPE] : [],
} as const;
