import { useState, useEffect, useCallback } from 'react';
import { ApiUserService } from '../infrastructure/auth/ApiUserService';
import type { ApiUserResponse } from '../domain/auth/ApiUserResponse';

interface UseApiUserDataReturn {
    userData: ApiUserResponse | null;
    loading: boolean;
    error: string | null;
    refreshUserData: (forceRefresh?: boolean) => Promise<void>;
    clearUserData: () => void;
    hasStoredData: boolean;
}

/**
 * Hook personalizado para manejar datos del usuario desde API/localStorage
 * @param email - Email del usuario
 * @param autoLoad - Si debe cargar automáticamente al montar (default: false)
 * @returns Objeto con datos del usuario y funciones de control
 */
export const useApiUserData = (email?: string, autoLoad: boolean = false): UseApiUserDataReturn => {
    const [userData, setUserData] = useState<ApiUserResponse | null>(null);
    const [loading, setLoading] = useState<boolean>(false);
    const [error, setError] = useState<string | null>(null);
    const [hasStoredData, setHasStoredData] = useState<boolean>(false);

    // Verificar si hay datos almacenados al montar
    useEffect(() => {
        if (email) {
            const hasStored = ApiUserService.hasValidStoredData(email);
            setHasStoredData(hasStored);
            
            // Si hay datos almacenados, cargarlos inmediatamente
            if (hasStored) {
                const storedData = ApiUserService.getUserDataFromStorage(email);
                if (storedData) {
                    setUserData(storedData.userData);
                }
            }
        }
    }, [email]);

    // Función para refrescar datos del usuario
    const refreshUserData = useCallback(async (forceRefresh: boolean = false) => {
        if (!email) {
            setError('Email no proporcionado');
            return;
        }

        setLoading(true);
        setError(null);

        try {
            const data = await ApiUserService.getUserData(email, forceRefresh);
            setUserData(data);
            setHasStoredData(true);
        } catch (err: unknown) {
            const errorMessage =
                (err instanceof Error && err.message) ||
                'Error al obtener datos del usuario';
            setError(errorMessage);
            console.error('❌ Hook: Error al obtener datos del usuario:', err);
        } finally {
            setLoading(false);
        }
    }, [email]);

    // Función para limpiar datos del usuario
    const clearUserData = useCallback(() => {
        ApiUserService.clearUserDataFromStorage();
        setUserData(null);
        setHasStoredData(false);
        setError(null);
    }, []);

    // Cargar automáticamente si está habilitado
    useEffect(() => {
        if (autoLoad && email && !userData && !hasStoredData) {
            refreshUserData(false);
        }
    }, [autoLoad, email, userData, hasStoredData, refreshUserData]);

    return {
        userData,
        loading,
        error,
        refreshUserData,
        clearUserData,
        hasStoredData
    };
};

