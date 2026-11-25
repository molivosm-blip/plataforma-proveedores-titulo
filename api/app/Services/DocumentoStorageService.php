<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/*
|--------------------------------------------------------------------------
| Almacenamiento privado de documentos
|--------------------------------------------------------------------------
|
| Los archivos ya no quedan en public/, donde cualquiera con la URL podía
| descargarlos. Se guardan en storage/app/uploads/{carpeta}/{archivo}, que el
| servidor web no publica, y solo se entregan mediante
| GET /v1/documentos/{id}/archivo, que exige sesión y permiso.
|
| En la base de datos se mantiene la misma ruta lógica de antes
| (/uploads/{carpeta}/{archivo}); esta clase la traduce a la ruta real y
| rechaza cualquier valor que no tenga ese formato exacto, así una ruta
| manipulada ("../../.env") nunca llega al sistema de archivos.
|
*/
class DocumentoStorageService
{
    const CARPETAS = [
        'proveedor_documentos',
        'proveedor_fichas',
        'proveedor_informes',
        'proveedor_declaraciones',
    ];

    const EXTENSIONES_MIME = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    /** Carpeta raíz privada. */
    public function raiz(): string
    {
        return storage_path('app' . DIRECTORY_SEPARATOR . 'uploads');
    }

    /** Carpeta pública antigua, solo para leer y migrar archivos previos. */
    public function raizLegacy(): string
    {
        return base_path('public' . DIRECTORY_SEPARATOR . 'uploads');
    }

    public function directorio(string $carpeta): string
    {
        if (!in_array($carpeta, self::CARPETAS, true)) {
            throw new \InvalidArgumentException('Carpeta de documentos no permitida.');
        }

        $dir = $this->raiz() . DIRECTORY_SEPARATOR . $carpeta;

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** Ruta lógica que se guarda en proveedor_documentos.archivo_url. */
    public function rutaLogica(string $carpeta, string $nombre): string
    {
        return '/uploads/' . $carpeta . '/' . $nombre;
    }

    /** Guarda el contenido y devuelve la ruta lógica. */
    public function guardarContenido(string $carpeta, string $nombre, string $contenido): string
    {
        $this->nombreSeguro($nombre);

        file_put_contents($this->directorio($carpeta) . DIRECTORY_SEPARATOR . $nombre, $contenido);

        return $this->rutaLogica($carpeta, $nombre);
    }

    /** Mueve un archivo subido y devuelve la ruta lógica. */
    public function guardarSubida(UploadedFile $file, string $carpeta, string $nombre): string
    {
        $this->nombreSeguro($nombre);

        $file->move($this->directorio($carpeta), $nombre);

        return $this->rutaLogica($carpeta, $nombre);
    }

    /**
     * Separa carpeta y nombre de una ruta lógica. Devuelve null si el valor
     * no cumple exactamente el formato /uploads/{carpeta permitida}/{nombre}.
     */
    public function parsear(?string $archivoUrl): ?array
    {
        if (!is_string($archivoUrl)) {
            return null;
        }

        $carpetas = implode('|', self::CARPETAS);

        if (!preg_match('#^/uploads/(' . $carpetas . ')/([A-Za-z0-9][A-Za-z0-9._-]{0,150})$#', $archivoUrl, $m)) {
            return null;
        }

        if (strpos($m[2], '..') !== false) {
            return null;
        }

        return ['carpeta' => $m[1], 'nombre' => $m[2]];
    }

    public function esRutaValida(?string $archivoUrl): bool
    {
        return $this->parsear($archivoUrl) !== null;
    }

    /**
     * Ruta real del archivo, o null si no existe o la ruta no es válida.
     * Busca primero en el almacenamiento privado y, por compatibilidad,
     * en public/uploads (archivos que aún no se han migrado).
     */
    public function resolver(?string $archivoUrl): ?string
    {
        $partes = $this->parsear($archivoUrl);

        if (!$partes) {
            return null;
        }

        $candidatos = [
            $this->raiz() . DIRECTORY_SEPARATOR . $partes['carpeta'] . DIRECTORY_SEPARATOR . $partes['nombre'],
            $this->raizLegacy() . DIRECTORY_SEPARATOR . $partes['carpeta'] . DIRECTORY_SEPARATOR . $partes['nombre'],
        ];

        foreach ($candidatos as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return null;
    }

    /** Tipo MIME según la extensión, limitado a los formatos permitidos. */
    public function mimePorExtension(string $ruta): string
    {
        $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        return self::EXTENSIONES_MIME[$ext] ?? 'application/octet-stream';
    }

    /**
     * Mueve a storage/app/uploads los archivos que siguen en public/uploads.
     * Devuelve [movidos, omitidos].
     */
    public function migrarLegacy(): array
    {
        $movidos = 0;
        $omitidos = 0;

        foreach (self::CARPETAS as $carpeta) {
            $origen = $this->raizLegacy() . DIRECTORY_SEPARATOR . $carpeta;

            if (!is_dir($origen)) {
                continue;
            }

            foreach (scandir($origen) as $nombre) {
                $ruta = $origen . DIRECTORY_SEPARATOR . $nombre;

                if ($nombre === '.' || $nombre === '..' || !is_file($ruta)) {
                    continue;
                }

                if (!$this->esRutaValida($this->rutaLogica($carpeta, $nombre))) {
                    $omitidos++;
                    continue;
                }

                $destino = $this->directorio($carpeta) . DIRECTORY_SEPARATOR . $nombre;

                if (is_file($destino)) {
                    @unlink($ruta);
                    $movidos++;
                    continue;
                }

                if (@rename($ruta, $destino) || (@copy($ruta, $destino) && @unlink($ruta))) {
                    $movidos++;
                } else {
                    $omitidos++;
                }
            }
        }

        return [$movidos, $omitidos];
    }

    private function nombreSeguro(string $nombre): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}$/', $nombre) || strpos($nombre, '..') !== false) {
            throw new \InvalidArgumentException('Nombre de archivo no permitido.');
        }
    }
}
