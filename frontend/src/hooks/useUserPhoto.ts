import { useState } from "react";
import { useAuth } from "../config/useAuth";
import { useMsal } from "@azure/msal-react";
import type { AuthenticationProvider } from "@microsoft/microsoft-graph-client";
import { Client } from "@microsoft/microsoft-graph-client";

/**
 * Hook personalizado para manejar la foto de perfil del usuario
 * Intenta obtener la foto desde Microsoft Graph, con fallbacks a Gravatar y UI Avatars
 */
export const useUserPhoto = () => {
    const { user } = useAuth();
    const { instance, accounts } = useMsal();
    const [userPhoto, setUserPhoto] = useState<string | null>(null);
    const [photoLoading, setPhotoLoading] = useState(false);

    // Datos del usuario
    const userName = user?.displayName || user?.email?.split('@')[0] || 'Usuario';
    const userInitial = userName.charAt(0).toUpperCase();

    const loadUserPhoto = async () => {
        if (!user?.email || photoLoading || userPhoto) return;
        
        setPhotoLoading(true);
        try {
            // Solo intentar Graph si el usuario está autenticado con Entra ID
            if (accounts.length > 0 && user.authType === 'entra') {
                // Obtener token de acceso para Microsoft Graph
                const request = {
                    scopes: ["User.Read"],
                    account: accounts[0]
                };

                const response = await instance.acquireTokenSilent(request);
                
                // Crear cliente de Microsoft Graph
                const authProvider: AuthenticationProvider = {
                    getAccessToken: async () => {
                        return response.accessToken;
                    }
                };
                
                const graphClient = Client.initWithMiddleware({ authProvider });
                
                try {
                    // Intentar obtener la foto del usuario desde Microsoft Graph
                    const photo = await graphClient.api('/me/photo/$value').get();
                    
                    // Convertir la respuesta a blob y crear URL
                    const blob = new Blob([photo], { type: 'image/jpeg' });
                    const photoUrl = URL.createObjectURL(blob);
                    setUserPhoto(photoUrl);
                    return;
                } catch {
                    // Si falla Graph, continuamos con los fallbacks
                }
            }

            // Fallback 1: Gravatar
            const emailHash = btoa(user.email.toLowerCase().trim()).replace(/[^a-zA-Z0-9]/g, '');
            const gravatarUrl = `https://www.gravatar.com/avatar/${emailHash}?s=200&d=404`;
            
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                setUserPhoto(gravatarUrl);
            };
            img.onerror = () => {
                // Fallback 2: UI Avatars con iniciales
                const uiAvatarUrl = `https://ui-avatars.com/api/?name=${encodeURIComponent(userName)}&size=200&background=3b82f6&color=ffffff&rounded=true&bold=true`;
                setUserPhoto(uiAvatarUrl);
            };
            img.src = gravatarUrl;

        } catch {
            // Si falla todo el proceso, usar UI Avatars directamente
            const uiAvatarUrl = `https://ui-avatars.com/api/?name=${encodeURIComponent(userName)}&size=200&background=3b82f6&color=ffffff&rounded=true&bold=true`;
            setUserPhoto(uiAvatarUrl);
        } finally {
            setPhotoLoading(false);
        }
    };

    const clearUserPhoto = () => {
        if (userPhoto && userPhoto.startsWith('blob:')) {
            URL.revokeObjectURL(userPhoto);
        }
        setUserPhoto(null);
    };

    return {
        userPhoto,
        photoLoading,
        loadUserPhoto,
        clearUserPhoto,
        userName,
        userInitial
    };
};

