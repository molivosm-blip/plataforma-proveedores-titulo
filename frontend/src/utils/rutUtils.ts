/** 
  * Utilidades para formatear y validar RUT chileno 
  */ 
 
 /** 
  * Formatea un RUT a la forma estándar chilena (XX.XXX.XXX-X) 
  * @param rut - RUT sin formato o con formato parcial 
  * @returns RUT formateado o el valor original si no es válido 
  */ 
 export const formatRut = (rut: string): string => { 
   if (!rut) return ''; 
   
   // Limpiar el RUT de caracteres no numéricos excepto K/k 
   const cleanRutStr = rut.replace(/[^0-9kK]/g, '').toUpperCase(); 
   
   if (cleanRutStr.length < 2) return rut; 
   
   // Separar cuerpo y dígito verificador 
   const body = cleanRutStr.slice(0, -1); 
   const dv = cleanRutStr.slice(-1); 
   
   // Formatear el cuerpo con puntos 
   const formattedBody = body.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); 
   
   return `${formattedBody}-${dv}`; 
 }; 
 
 /** 
  * Valida si un RUT chileno es válido 
  * @param rut - RUT a validar 
  * @returns true si el RUT es válido, false en caso contrario 
  */ 
 export const validateRut = (rut: string): boolean => { 
   if (!rut) return false; 
   
   // Limpiar el RUT 
   const cleanRutStr = rut.replace(/[^0-9kK]/g, '').toUpperCase(); 
   
   if (cleanRutStr.length < 2) return false; 
   
   const body = cleanRutStr.slice(0, -1); 
   const dv = cleanRutStr.slice(-1); 
   
   // Calcular dígito verificador 
   let sum = 0; 
   let multiplier = 2; 
   
   for (let i = body.length - 1; i >= 0; i--) { 
     sum += parseInt(body[i]) * multiplier; 
     multiplier = multiplier === 7 ? 2 : multiplier + 1; 
   } 
   
   const remainder = sum % 11; 
   const calculatedDv = remainder === 0 ? '0' : remainder === 1 ? 'K' : (11 - remainder).toString(); 
   
   return dv === calculatedDv; 
 }; 
 
 /** 
  * Limpia un RUT de caracteres especiales 
  * @param rut - RUT a limpiar 
  * @returns RUT limpio 
  */ 
 export const cleanRut = (rut: string): string => { 
   return rut.replace(/[^0-9kK]/g, '').toUpperCase(); 
 }; 
 
 /** 
  * Obtiene solo el número del RUT (sin dígito verificador) 
  * @param rut - RUT completo 
  * @returns Número del RUT sin dígito verificador 
  */ 
 export const getRutNumber = (rut: string): string => { 
   const cleanedRut = cleanRut(rut); 
   return cleanedRut.slice(0, -1); 
 }; 
 
 /** 
  * Obtiene solo el dígito verificador del RUT 
  * @param rut - RUT completo 
  * @returns Dígito verificador 
  */ 
 export const getRutDv = (rut: string): string => { 
   const cleanedRut = cleanRut(rut); 
   return cleanedRut.slice(-1); 
 };
