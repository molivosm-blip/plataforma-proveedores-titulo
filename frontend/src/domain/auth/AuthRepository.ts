import type { User } from './AuthEntity';

export interface AuthRepository {
  login(): Promise<User>;
  logout(): Promise<void>;
}