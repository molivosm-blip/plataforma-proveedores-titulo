<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Proveedores;

class User extends Model
{
    protected $table = 'users';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'email',
        'password_hash',
        'display_name',
        'role',
        'auth_type',
        'id_empresa',
        'area',
        'active',
        'created_at',
        'updated_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relaciones
    |--------------------------------------------------------------------------
    */

    /**
     * Relación PROVEEDOR.
     *
     * Solo aplica cuando role = proveedor.
     *
     * proveedores.proveedor_user_id → users.id
     */
    public function proveedor()
    {
        return $this->hasOne(
            Proveedores::class,
            'proveedor_user_id',
            'id'
        );
    }

    /**
     * Proveedores creados por este usuario interno.
     *
     * proveedores.created_by_user_id → users.id
     */
    public function proveedoresCreados()
    {
        return $this->hasMany(
            Proveedores::class,
            'created_by_user_id',
            'id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de compatibilidad con roles antiguos
    |--------------------------------------------------------------------------
    */

    /**
     * Rol antiguo PM Admin.
     *
     * Se mantiene por compatibilidad temporal.
     */
    public function isPmAdmin(): bool
    {
        return $this->role === 'pm_admin';
    }

    /**
     * Rol antiguo PM User.
     *
     * Se mantiene por compatibilidad temporal.
     */
    public function isPmUser(): bool
    {
        return $this->role === 'pm_user';
    }

    /**
     * Usuario proveedor externo.
     */
    public function isProveedor(): bool
    {
        return $this->role === 'proveedor';
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de nuevos roles
    |--------------------------------------------------------------------------
    */

    /**
     * Administrador global del portal.
     *
     * Nuevo rol recomendado.
     */
    public function isAdminPortal(): bool
    {
        return $this->role === 'admin_portal';
    }

    /**
     * Administrador de área.
     */
    public function isAdminArea(): bool
    {
        return $this->role === 'admin_area';
    }

    /**
     * Usuario operativo de área.
     */
    public function isUsuarioArea(): bool
    {
        return $this->role === 'usuario_area';
    }

    /**
     * Usuario interno del sistema.
     *
     * Incluye roles antiguos y nuevos.
     */
    public function isInterno(): bool
    {
        return in_array($this->role, [
            'pm_admin',
            'pm_user',
            'admin_portal',
            'admin_area',
            'usuario_area',
            'auditor',
        ]);
    }

    /**
     * Usuario con permisos de administración global.
     *
     * Compatibilidad:
     * - pm_admin se interpreta como admin_portal temporalmente.
     */
    public function isAdminGlobal(): bool
    {
        return in_array($this->role, [
            'pm_admin',
            'admin_portal',
        ]);
    }

    /**
     * Usuario que puede administrar usuarios internos.
     *
     * - admin_portal / pm_admin: cualquier área.
     * - admin_area: solo su área.
     */
    public function canManageInternalUsers(): bool
    {
        return in_array($this->role, [
            'pm_admin',
            'admin_portal',
            'admin_area',
        ]);
    }

    /**
     * Usuario que puede iniciar procesos/invitar proveedores.
     *
     * Compatibilidad:
     * - pm_user antiguo.
     * - admin_portal / pm_admin.
     * - usuarios del área PM.
     */
    public function canInviteProveedores(): bool
    {
        if (in_array($this->role, ['pm_admin', 'admin_portal'])) {
            return true;
        }

        if ($this->role === 'pm_user') {
            return true;
        }

        if (
            in_array($this->role, ['admin_area', 'usuario_area']) &&
            $this->area === 'pm'
        ) {
            return true;
        }

        return false;
    }

    /**
     * Usuario activo.
     */
    public function isActive(): bool
    {
        return (int) $this->active === 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de área
    |--------------------------------------------------------------------------
    */

    public function hasArea(): bool
    {
        return !empty($this->area);
    }

    public function isArea(string $area): bool
    {
        return $this->area === $area;
    }

    public function isAreaPm(): bool
    {
        return $this->area === 'pm';
    }

    public function isAreaContraloria(): bool
    {
        return $this->area === 'contraloria';
    }

    public function isAreaLegalCrediticia(): bool
    {
        return $this->area === 'legal_crediticia';
    }

    public function isAreaLegalJudicial(): bool
    {
        return $this->area === 'legal_judicial';
    }

    public function isAreaDatosMaestros(): bool
    {
        return $this->area === 'datos_maestros';
    }
}