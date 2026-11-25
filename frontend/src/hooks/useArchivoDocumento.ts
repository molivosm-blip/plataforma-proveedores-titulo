import { useEffect, useState } from 'react';
import { obtenerArchivoDocumento } from '../infrastructure/auth/api/documentosArchivoService';

/*
 * Devuelve una URL temporal (blob:) con el archivo del documento, para usarla
 * en <img> o <iframe> sin exponer el archivo por una URL pública.
 * Si documentoId es null no hace nada.
 */
export const useArchivoDocumento = (documentoId: string | null) => {
  const [resultado, setResultado] = useState<{
    id: string | null;
    url: string | null;
    error: string | null;
  }>({ id: null, url: null, error: null });

  useEffect(() => {
    if (!documentoId) {
      return undefined;
    }

    let cancelado = false;
    let objectUrl: string | null = null;

    obtenerArchivoDocumento(documentoId)
      .then((blob) => {
        if (cancelado) return;
        objectUrl = URL.createObjectURL(blob);
        setResultado({ id: documentoId, url: objectUrl, error: null });
      })
      .catch((error: Error) => {
        if (cancelado) return;
        setResultado({ id: documentoId, url: null, error: error.message });
      });

    return () => {
      cancelado = true;
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [documentoId]);

  // Mientras llega la respuesta de otro documento no se muestra el anterior.
  const vigente = documentoId !== null && resultado.id === documentoId;

  return {
    url: vigente ? resultado.url : null,
    error: vigente ? resultado.error : null,
    cargando: documentoId !== null && !vigente,
  };
};
