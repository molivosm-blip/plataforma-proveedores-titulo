import apiClient from './apiClient';

import type { RawApiData } from '../../../utils/apiTypes';

export interface EmpresaOption {
  id: string;
  nombre: string;
}

const unwrapResponse = (data: RawApiData): RawApiData => {
  return data?.data ?? data?.items ?? data;
};

const normalizeEmpresa = (raw: RawApiData): EmpresaOption => ({
  id: String(raw?.id ?? ''),
  nombre: String(raw?.nombre ?? raw?.name ?? raw?.id ?? ''),
});

export class EmpresasService {
  static async getAll(): Promise<EmpresaOption[]> {
    const response = await apiClient.get('/empresas');
    const payload = unwrapResponse(response.data);

    if (!Array.isArray(payload)) {
      return [];
    }

    return payload
      .map(item => normalizeEmpresa(item))
      .filter(empresa => empresa.id && empresa.nombre)
      .sort((a, b) =>
        a.nombre.localeCompare(b.nombre, 'es', {
          sensitivity: 'base',
        })
      );
  }
}

export default EmpresasService;