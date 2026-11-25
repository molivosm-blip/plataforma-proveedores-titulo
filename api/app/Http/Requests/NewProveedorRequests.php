<?php

namespace App\Http\Requests;

use Anik\Form\FormRequest;

class NewProveedorRequests extends FormRequest{
    public function authorize(): bool{
        return true;
    }

    public function rules(): array{
        return [
            'razon_social' => 'required|string',
            'rut' => 'required|string',
            'giro' => 'required|string',
            'tipo' => 'required|string|in:Nacional,Internacional',
            'empresa_id' => 'required|string',
            'usuario' => 'required|array|min:1',
            'usuario.correo' => 'required|string',
            'usuario.clave' => 'required|string',
            'usuario.nombre' => 'required|string',
            'usuario.role' => 'required|string',
            'usuario.auth_type' => 'required|string',
        ];
    }

    public function messages(): array{
        return [
            'razon_social.required' => 'El campo razon_social es requerido.',
            'razon_social.string'   => 'El campo razon_social debe ser una cadena de texto.',

            'rut.required' => 'El campo rut es requerido.',
            'rut.string'   => 'El campo rut debe ser una cadena de texto.',

            'giro.required' => 'El campo giro es requerido.',
            'giro.string'   => 'El campo giro debe ser una cadena de texto.',

            'tipo.required' => 'El campo tipo es requerido.',
            'tipo.string'   => 'El campo tipo debe ser una cadena de texto.',
            'tipo.in'       => 'El campo tipo debe ser uno de los siguientes valores: Nacional, Internacional.',

            'empresa_id.required' => 'El campo empresa_id es requerido.',
            'empresa_id.string'   => 'El campo empresa_id debe ser una cadena de texto.',

            'usuario.required' => 'El campo usuario es requerido.',
            'usuario.array'    => 'El campo usuario debe ser un arreglo.',
            'usuario.min'      => 'El campo usuario debe contener al menos un elemento.',

            'usuario.correo.required' => 'El campo correo es requerido en los usuarios.',
            'usuario.correo.string'   => 'El campo correo debe ser una cadena de texto en los usuarios.',

            'usuario.clave.required' => 'El campo clave es requerido en los usuarios.',
            'usuario.clave.string'   => 'El campo clave debe ser una cadena de texto en los usuarios.',

            'usuario.nombre.required' => 'El campo nombre es requerido en los usuarios.',
            'usuario.nombre.string'   => 'El campo nombre debe ser una cadena de texto en los usuarios.',

            'usuario.role.required' => 'El campo role es requerido en los usuarios.',
            'usuario.role.string'   => 'El campo role debe ser una cadena de texto en los usuarios.',

            'usuario.auth_type.required' => 'El campo auth_type es requerido en los usuarios.',
            'usuario.auth_type.string'   => 'El campo auth_type debe ser una cadena de texto en los usuarios.',
        ];
    }

    protected function errorMessage(): string{
        return 'La información enviada es inválida.';
    }
}
