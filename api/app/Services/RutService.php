<?php

namespace App\Services;

/*
|--------------------------------------------------------------------------
| Validación de RUT chileno
|--------------------------------------------------------------------------
|
| El navegador ya valida el RUT, pero esa validación se puede saltar
| llamando a la API directamente. Este servicio repite la regla en el
| servidor (algoritmo módulo 11), para que un RUT inválido nunca llegue
| a la base de datos ni a las consultas externas (Gesintel, DICOM).
|
| Solo aplica a proveedores nacionales: los internacionales usan otro
| identificador tributario.
|
*/
class RutService
{
    /**
     * Deja el RUT como "12345678-K": sin puntos ni espacios, con guion y
     * el dígito verificador en mayúscula. Devuelve null si no tiene forma de RUT.
     */
    public function normalizar(?string $rut): ?string
    {
        $limpio = strtoupper(preg_replace('/[\.\s\-]/', '', (string) $rut));

        if (!preg_match('/^\d{6,8}[0-9K]$/', $limpio)) {
            return null;
        }

        return substr($limpio, 0, -1) . '-' . substr($limpio, -1);
    }

    public function digitoVerificador(string $cuerpo): string
    {
        $suma = 0;
        $multiplicador = 2;

        for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
            $suma += (int) $cuerpo[$i] * $multiplicador;
            $multiplicador = $multiplicador === 7 ? 2 : $multiplicador + 1;
        }

        $resto = 11 - ($suma % 11);

        if ($resto === 11) {
            return '0';
        }

        return $resto === 10 ? 'K' : (string) $resto;
    }

    public function esValido(?string $rut): bool
    {
        $normalizado = $this->normalizar($rut);

        if ($normalizado === null) {
            return false;
        }

        [$cuerpo, $dv] = explode('-', $normalizado);

        return $this->digitoVerificador($cuerpo) === $dv;
    }
}
