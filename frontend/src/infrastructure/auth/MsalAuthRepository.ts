import type { AuthRepository } from '../../domain/auth/AuthRepository';
import type { User } from '../../domain/auth/AuthEntity';
import { ApiUserService } from './ApiUserService';
import { AuthService } from './api/authService';
import {
  InteractionRequiredAuthError,
  type AccountInfo,
  type AuthenticationResult,
  type IPublicClientApplication,
} from '@azure/msal-browser';
import { Client } from '@microsoft/microsoft-graph-client';
import {
  LOCAL_DEMO_ENABLED,
  apiTokenRequest,
  graphRequest,
  loginRequest,
} from '../../config/msalConfig';

/**
 * Repositorio de autenticación mediante Microsoft Entra ID.
 *
 * Responsabilidades:
 * - Microsoft Entra ID autentica la identidad.
 * - El backend del portal define JWT, rol, área y estado activo.
 * - Microsoft Graph y la API Dimerc solo aportan datos de presentación.
 *
 * Tokens:
 * - Token de API (access_as_user): se envía al backend del portal.
 * - Token de Graph (User.Read): solo se usa para perfil y foto.
 */
export class MsalAuthRepository implements AuthRepository {
  private readonly msalInstance: IPublicClientApplication;

  constructor(msalInstance: IPublicClientApplication) {
    this.msalInstance = msalInstance;
  }

  /*
  |--------------------------------------------------------------------------
  | Helpers
  |--------------------------------------------------------------------------
  */

  private async redirectToMicrosoft(): Promise<never> {
    await this.msalInstance.loginRedirect(loginRequest);
    throw new Error('REDIRECT_INITIATED');
  }

  /**
   * Obtiene la cuenta autenticada. Si no existe, inicia el redirect.
   */
  private async resolveAccount(): Promise<{
    account: AccountInfo;
    redirectResult: AuthenticationResult | null;
  }> {
    let redirectResult: AuthenticationResult | null = null;

    try {
      redirectResult =
        await this.msalInstance.handleRedirectPromise();
    } catch (error) {
      console.error(
        'Error procesando el redirect de Microsoft:',
        error
      );
    }

    const account =
      redirectResult?.account ??
      this.msalInstance.getActiveAccount() ??
      this.msalInstance.getAllAccounts()[0];

    if (!account) {
      return this.redirectToMicrosoft();
    }

    this.msalInstance.setActiveAccount(account);

    return { account, redirectResult };
  }

  /**
   * Token silencioso; si requiere interacción, vuelve a Microsoft.
   */
  private async acquireSilent(
    scopes: string[],
    account: AccountInfo,
    interactiveFallback: boolean
  ): Promise<AuthenticationResult | null> {
    try {
      return await this.msalInstance.acquireTokenSilent({
        scopes,
        account,
      });
    } catch (error) {
      if (
        interactiveFallback &&
        error instanceof InteractionRequiredAuthError
      ) {
        await this.msalInstance.acquireTokenRedirect({
          scopes,
          account,
        });
        throw new Error('REDIRECT_INITIATED');
      }

      console.warn(
        `No se pudo obtener el token silencioso (${scopes.join(
          ', '
        )}):`,
        error
      );

      return null;
    }
  }

  /*
  |--------------------------------------------------------------------------
  | Login
  |--------------------------------------------------------------------------
  */

  async login(): Promise<User> {
    const { account, redirectResult } =
      await this.resolveAccount();

    /*
    | 1. Token de la API del portal (obligatorio si hay scope configurado)
    */
    let apiAccessToken: string | undefined;

    if (apiTokenRequest) {
      const apiResult = await this.acquireSilent(
        apiTokenRequest.scopes,
        account,
        true
      );

      apiAccessToken = apiResult?.accessToken;

      if (!apiAccessToken) {
        throw new Error(
          'No se pudo obtener el token de acceso a la API del portal.'
        );
      }
    }

    const idToken =
      redirectResult?.idToken ?? undefined;

    /*
    | 2. Perfil y foto desde Microsoft Graph (opcionales)
    */
    const userEmail = (
      account.username || ''
    )
      .trim()
      .toLowerCase();

    let displayName =
      account.name || account.username;
    let jobTitle: string | undefined;
    let photoUrl: string | undefined;

    const graphResult = await this.acquireSilent(
      graphRequest.scopes,
      account,
      false
    );

    if (graphResult?.accessToken) {
      const graphToken = graphResult.accessToken;

      const graphClient = Client.init({
        authProvider: done => {
          done(null, graphToken);
        },
      });

      try {
        const profile = await graphClient
          .api('/me')
          .get();

        displayName =
          profile?.displayName || displayName;
        jobTitle = profile?.jobTitle || undefined;
      } catch (error) {
        console.warn(
          'No se pudo obtener el perfil de Microsoft Graph:',
          error
        );
      }

      try {
        const photo = await graphClient
          .api('/me/photo/$value')
          .get();

        photoUrl = URL.createObjectURL(
          new Blob([photo], { type: 'image/jpeg' })
        );
      } catch {
        photoUrl = undefined;
      }
    }

    /*
    | 3. Datos corporativos Dimerc (solo fuera del modo local)
    */
    let apiData;

    if (!LOCAL_DEMO_ENABLED) {
      try {
        apiData = await ApiUserService.getUserData(
          userEmail,
          true
        );
      } catch (error) {
        console.warn(
          'No se pudieron obtener los datos corporativos:',
          error
        );
      }
    }

    /*
    | 4. Sesión del portal (rol, área, activo y JWT vienen del backend)
    */
    const backendResponse = await AuthService.loginMsal(
      userEmail,
      displayName,
      {
        accessToken: apiAccessToken,
        idToken,
      }
    );

    const backendUser = backendResponse.session.user;
    const jwtToken = backendResponse.session.token;

    if (!backendUser) {
      throw new Error(
        'El backend no devolvió información del usuario.'
      );
    }

    if (!jwtToken) {
      throw new Error(
        'El backend no devolvió el JWT de sesión.'
      );
    }

    return {
      ...backendUser,
      id: String(backendUser.id),
      email: backendUser.email || userEmail,
      token: jwtToken,
      role: backendUser.role,
      area: backendUser.area ?? null,
      active: backendUser.active ?? 1,
      authType: 'entra',
      auth_type: 'entra',
      displayName:
        backendUser.displayName ??
        backendUser.display_name ??
        displayName,
      display_name:
        backendUser.display_name ??
        backendUser.displayName ??
        displayName,
      jobTitle,
      photoUrl,
      apiUserData: apiData,
    };
  }

  /*
  |--------------------------------------------------------------------------
  | Logout
  |--------------------------------------------------------------------------
  */

  async logout(): Promise<void> {
    const hasMsalAccount =
      this.msalInstance.getAllAccounts().length > 0;

    ['auth_user', 'dimerc_user_data', 'dimerc_user_email', 'dimerc_last_validation'].forEach(
      key => localStorage.removeItem(key)
    );
    sessionStorage.removeItem('hasShownWelcome');

    /*
     * Los proveedores (login con correo y clave) no tienen sesión
     * MSAL: no hay nada que cerrar en Microsoft.
     */
    if (!hasMsalAccount) {
      return;
    }

    /*
     * Solo se limpia la caché local de MSAL, sin redirigir a Microsoft.
     * Así el siguiente "Continuar con Microsoft" no reutiliza la cuenta
     * anterior: loginRequest usa prompt 'select_account' y Microsoft
     * muestra el selector, lo que permite entrar con otra cuenta.
     */
    this.msalInstance.setActiveAccount(null);
    await this.msalInstance.clearCache();
  }
}

export default MsalAuthRepository;
