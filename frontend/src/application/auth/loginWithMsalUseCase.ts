import type { AuthRepository } from '../../domain/auth/AuthRepository';
import type { User } from '../../domain/auth/AuthEntity';

export class LoginWithMsalUseCase {
  private readonly authRepository: AuthRepository;

  constructor(authRepository: AuthRepository) {
    this.authRepository = authRepository;
  }

  async execute(): Promise<User> {
        return this.authRepository.login();
  }
}
