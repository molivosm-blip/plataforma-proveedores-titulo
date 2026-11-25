import { PublicClientApplication } from '@azure/msal-browser';
import { MsalAuthRepository } from '../infrastructure/auth/MsalAuthRepository';
import { LoginWithMsalUseCase } from '../application/auth/loginWithMsalUseCase';
import { LogoutUseCase } from '../application/auth/logoutUseCase';
import { msalConfig } from './msalConfig';

export const msalInstance = new PublicClientApplication(msalConfig);

const authRepository = new MsalAuthRepository(msalInstance);

export const loginUseCase = new LoginWithMsalUseCase(authRepository);

/**
 * Alias mantenido por compatibilidad con código existente.
 */
export const loginWithMsalUseCase = loginUseCase;

export const logoutUseCase = new LogoutUseCase(authRepository);