import { useEffect } from 'react'; 
import { useAuth } from '../config/useAuth'; 
import { shouldShowWelcomePopup, markWelcomeAsShown } from '../utils/welcomePopupUtils'; 

/** 
 * Hook para manejar la lógica del welcome popup 
 */ 
export const useWelcomePopup = () => { 
  const { user, showWelcomePopup, setShowWelcomePopup } = useAuth(); 

  // Efecto para mostrar el popup cuando corresponde 
  useEffect(() => { 
    if (user && showWelcomePopup) { 
      // El popup ya está configurado para mostrarse desde AuthContext 
      return; 
    } 
    
    // Verificación adicional por si acaso 
    if (user && shouldShowWelcomePopup()) { 
      const timer = setTimeout(() => { 
        setShowWelcomePopup(true); 
      }, 800); 
      
      return () => clearTimeout(timer); 
    } 
  }, [user, showWelcomePopup, setShowWelcomePopup]); 

  const handleCloseWelcome = () => { 
    setShowWelcomePopup(false); 
    markWelcomeAsShown(); 
  }; 

  const forceShow = () => { 
    sessionStorage.removeItem('hasShownWelcome'); 
    setShowWelcomePopup(true); 
  }; 

  return { 
    showWelcomePopup, 
    handleCloseWelcome, 
    forceShow, 
    user 
  }; 
};
