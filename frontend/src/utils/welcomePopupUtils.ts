// Utilidades para el manejo del Welcome Popup 
 
 /** 
  * Verifica si se debe mostrar el welcome popup 
  */ 
 export const shouldShowWelcomePopup = (): boolean => { 
   const hasShownWelcome = sessionStorage.getItem('hasShownWelcome'); 
   return !hasShownWelcome; 
 }; 
 
 /** 
  * Marca que el welcome popup ya se mostró en esta sesión 
  */ 
 export const markWelcomeAsShown = (): void => { 
   sessionStorage.setItem('hasShownWelcome', 'true'); 
 }; 
 
 /** 
  * Resetea el estado del welcome popup (para testing) 
  */ 
 export const resetWelcomePopup = (): void => { 
   sessionStorage.removeItem('hasShownWelcome'); 
 }; 
 
 /** 
  * Obtiene información de debug del welcome popup 
  */ 
 export const getWelcomeDebugInfo = () => { 
   return { 
     hasShownWelcome: sessionStorage.getItem('hasShownWelcome'), 
     authUser: localStorage.getItem('auth_user'), 
     timestamp: new Date().toISOString() 
   }; 
 }; 
 
 /** 
  * Fuerza mostrar el welcome popup (útil para testing) 
  */ 
 export const forceShowWelcome = (setShowWelcomePopup: (show: boolean) => void): void => { 
   resetWelcomePopup(); 
   setShowWelcomePopup(true); 
 };
