import apiClient from './apiClient';

/*
 * Los archivos ya no se piden por una URL pública: el backend los entrega
 * solo con sesión y permiso (GET /documentos/{id}/archivo). Aquí se piden con
 * el token de la sesión y se convierten en un blob que el navegador puede
 * mostrar o descargar.
 */

const mensajeDesdeBlob = async (blob: Blob, porDefecto: string): Promise<string> => {
  try {
    const texto = await blob.text();
    const json = JSON.parse(texto);
    return json?.message || porDefecto;
  } catch {
    return porDefecto;
  }
};

export const obtenerArchivoDocumento = async (documentoId: string): Promise<Blob> => {
  try {
    const { data } = await apiClient.get<Blob>(
      `/documentos/${encodeURIComponent(documentoId)}/archivo`,
      { responseType: 'blob' }
    );
    return data;
  } catch (caught) {
    const err = caught as { response?: { data?: unknown } };
    const cuerpo = err?.response?.data;
    const mensaje =
      cuerpo instanceof Blob
        ? await mensajeDesdeBlob(cuerpo, 'No se pudo abrir el documento.')
        : 'No se pudo abrir el documento.';
    throw new Error(mensaje);
  }
};

export const descargarArchivoDocumento = async (
  documentoId: string,
  nombreArchivo: string
): Promise<void> => {
  const blob = await obtenerArchivoDocumento(documentoId);
  const url = URL.createObjectURL(blob);

  const link = document.createElement('a');
  link.href = url;
  link.download = nombreArchivo;
  document.body.appendChild(link);
  link.click();
  link.remove();

  setTimeout(() => URL.revokeObjectURL(url), 10000);
};

export const abrirArchivoDocumento = async (documentoId: string): Promise<void> => {
  // La pestaña se abre antes de la petición para que el navegador no la bloquee.
  const ventana = window.open('', '_blank');

  try {
    const blob = await obtenerArchivoDocumento(documentoId);
    const url = URL.createObjectURL(blob);

    if (ventana) {
      ventana.location.href = url;
    } else {
      window.open(url, '_blank');
    }

    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (error) {
    ventana?.close();
    throw error;
  }
};
