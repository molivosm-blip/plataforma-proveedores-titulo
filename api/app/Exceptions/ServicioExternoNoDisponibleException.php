<?php

namespace App\Exceptions;

/*
| Un servicio externo (Gesintel/AMLUpdate, DICOM) no respondió: no hay
| conexión, venció el tiempo de espera o contestó con un error del servidor.
| Los controladores la convierten en un 503 con un mensaje claro, sin
| mostrar direcciones internas ni detalles técnicos.
*/
class ServicioExternoNoDisponibleException extends \RuntimeException
{
    /** Nombre del servicio, para el mensaje al usuario. */
    private $servicio;

    public function __construct(string $servicio, string $detalleTecnico = '', ?\Throwable $previo = null)
    {
        $this->servicio = $servicio;

        parent::__construct($detalleTecnico !== '' ? $detalleTecnico : "{$servicio} no disponible", 0, $previo);
    }

    public function mensajeParaUsuario(): string
    {
        return "El servicio {$this->servicio} no está disponible en este momento. "
            . 'Intenta nuevamente en unos minutos; el resto del portal sigue funcionando.';
    }
}
