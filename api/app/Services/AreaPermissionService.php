<?php

namespace App\Services;

use App\Models\User;
use App\Models\Proveedores;
use App\Models\ProveedorWorkflowStep;
use App\Models\ProveedorValidacion;
use App\Models\ProveedorDocumento;

class AreaPermissionService
{
    /*
    |--------------------------------------------------------------------------
    | Roles base
    |--------------------------------------------------------------------------
    */

    public function isAdminGlobal(User $user): bool
    {
        return in_array($user->role, [
            'pm_admin',
            'admin_portal',
        ], true);
    }

    public function isInternalUser(User $user): bool
    {
        return in_array($user->role, [
            'pm_admin',
            'pm_user',
            'admin_portal',
            'admin_area',
            'usuario_area',
            'auditor',
        ], true);
    }

    /**
     * Auditor: ve todo el proceso pero no puede modificar nada.
     * La restricción de solo lectura la aplica RoleMiddleware (solo GET).
     */
    public function isAuditor(User $user): bool
    {
        return $user->role === 'auditor';
    }

    public function isAreaUser(User $user): bool
    {
        return in_array($user->role, [
            'admin_area',
            'usuario_area',
        ], true);
    }

    public function isAreaAdmin(User $user): bool
    {
        return $user->role === 'admin_area';
    }

    public function isProveedor(User $user): bool
    {
        return $user->role === 'proveedor';
    }

    /*
    |--------------------------------------------------------------------------
    | Compatibilidad legacy PM
    |--------------------------------------------------------------------------
    */

    public function isLegacyPmUser(User $user): bool
    {
        return $user->role === 'pm_user';
    }

    public function isPmArea(User $user): bool
    {
        return $user->area === 'pm';
    }

    public function canInviteProveedor(User $user): bool
    {
        if ($this->isAdminGlobal($user)) {
            return true;
        }

        if ($this->isLegacyPmUser($user)) {
            return true;
        }

        if ($this->isAreaUser($user) && $user->area === 'pm') {
            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Administración de usuarios internos
    |--------------------------------------------------------------------------
    */

    public function canManageInternalUsers(User $user): bool
    {
        return in_array($user->role, [
            'pm_admin',
            'admin_portal',
            'admin_area',
        ], true);
    }

    public function canCreateUserInArea(
        User $authUser,
        string $roleToCreate,
        ?string $areaToCreate
    ): bool {
        if ($this->isAdminGlobal($authUser)) {
            return true;
        }

        if ($this->isAreaAdmin($authUser)) {
            return $roleToCreate === 'usuario_area'
                && !empty($areaToCreate)
                && $areaToCreate === $authUser->area;
        }

        return false;
    }

    public function canDeactivateUser(User $authUser, User $targetUser): bool
    {
        if ($targetUser->role === 'proveedor') {
            return false;
        }

        if ($this->isAdminGlobal($authUser)) {
            return true;
        }

        if ($this->isAreaAdmin($authUser)) {
            return $targetUser->role === 'usuario_area'
                && $targetUser->area === $authUser->area;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Visibilidad de proveedores
    |--------------------------------------------------------------------------
    */

    public function canViewProveedor(User $user, Proveedores $proveedor): bool
    {
        if ($this->isAdminGlobal($user) || $this->isAuditor($user)) {
            return true;
        }

        if ($this->isProveedor($user)) {
            return (int) $proveedor->proveedor_user_id === (int) $user->id;
        }

        /*
         * PM legacy:
         * Ve proveedores creados por él.
         */
        if ($this->isLegacyPmUser($user)) {
            return (int) $proveedor->created_by_user_id === (int) $user->id;
        }

        /*
         * Usuarios por área:
         * Ven proveedores que tengan documentos asignados a su área.
         */
        if ($this->isAreaUser($user)) {
            if (empty($user->area)) {
                return false;
            }

            if ($user->area === 'pm') {
                return (int) $proveedor->created_by_user_id === (int) $user->id
                    || $this->proveedorHasDocumentoArea($proveedor, 'pm');
            }

            return $this->proveedorHasDocumentoArea($proveedor, $user->area);
        }

        return false;
    }

    /**
     * Indica si el usuario puede ejecutar acciones propias de un área
     * (evaluación crediticia, envío a SAP, etc.). Cada área actúa solo
     * sobre lo suyo; el admin global puede actuar por cualquiera.
     */
    public function actsForArea(User $user, string $area): bool
    {
        if ($this->isAdminGlobal($user)) {
            return true;
        }

        if ($this->isLegacyPmUser($user)) {
            return $area === 'pm';
        }

        return $this->isAreaUser($user)
            && !empty($user->area)
            && $user->area === $area;
    }

    public function proveedorHasDocumentoArea(Proveedores $proveedor, string $area): bool
    {
        if (empty($area)) {
            return false;
        }

        if ($proveedor->relationLoaded('documentos')) {
            return $proveedor->documentos
                ->where('visible_para_internos', 1)
                ->where('area_responsable', $area)
                ->count() > 0;
        }

        return ProveedorDocumento::where('proveedor_id', $proveedor->id)
            ->where('visible_para_internos', 1)
            ->where('area_responsable', $area)
            ->exists();
    }

    /*
     * Compatibilidad legacy.
     * Antes se usaba workflow/validaciones para saber si un proveedor pertenecía a un área.
     * Ahora se prioriza documento.area_responsable.
     */
    public function proveedorHasArea(Proveedores $proveedor, string $area): bool
    {
        return $this->proveedorHasDocumentoArea($proveedor, $area);
    }

    public function canUpdateProveedorEstado(User $user, Proveedores $proveedor): bool
    {
        if ($this->isAdminGlobal($user)) {
            return true;
        }

        /*
         * PM legacy puede cambiar estado general solo de proveedores creados por él.
         */
        if ($this->isLegacyPmUser($user)) {
            return (int) $proveedor->created_by_user_id === (int) $user->id;
        }

        /*
         * Usuario área PM puede cambiar estado general solo si creó el proveedor.
         */
        if ($this->isAreaUser($user) && $user->area === 'pm') {
            return (int) $proveedor->created_by_user_id === (int) $user->id;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Documentos
    |--------------------------------------------------------------------------
    */

    public function canViewDocumento(User $user, ProveedorDocumento $documento): bool
    {
        $proveedor = $documento->proveedor;

        if (!$proveedor) {
            return false;
        }

        if ($this->isAdminGlobal($user) || $this->isAuditor($user)) {
            return (int) $documento->visible_para_internos === 1;
        }

        if ($this->isProveedor($user)) {
            return (int) $proveedor->proveedor_user_id === (int) $user->id
                && (int) $documento->visible_para_proveedor === 1
                && $documento->responsable_carga !== 'pm';
        }

        if (!$this->isInternalUser($user)) {
            return false;
        }

        if ((int) $documento->visible_para_internos !== 1) {
            return false;
        }

        if ($this->isLegacyPmUser($user)) {
            return (int) $proveedor->created_by_user_id === (int) $user->id
                || $documento->area_responsable === 'pm'
                || $documento->responsable_carga === 'pm';
        }

        if ($this->isAreaUser($user)) {
            if (empty($user->area)) {
                return false;
            }

            if ($user->area === 'pm') {
                return (int) $proveedor->created_by_user_id === (int) $user->id
                    || $documento->area_responsable === 'pm'
                    || $documento->responsable_carga === 'pm';
            }

            return $documento->area_responsable === $user->area;
        }

        return false;
    }

    public function canUploadDocumento(User $user, ProveedorDocumento $documento): bool
    {
        $proveedor = $documento->proveedor;

        if (!$proveedor) {
            return false;
        }

        /*
         * La ficha se genera automáticamente.
         * Nadie debe subirla manualmente.
         */
        if ($documento->nombre === 'Ficha de incorporación proveedor') {
            return false;
        }

        if ($this->isAdminGlobal($user)) {
            return true;
        }

        /*
         * Proveedor:
         * Solo sube documentos de su propio proceso y solo si el documento
         * está marcado como responsable_carga = proveedor.
         */
        if ($this->isProveedor($user)) {
            return (int) $proveedor->proveedor_user_id === (int) $user->id
                && $documento->responsable_carga === 'proveedor'
                && (int) $documento->visible_para_proveedor === 1;
        }

        /*
         * PM legacy:
         * Puede subir documentos PM de proveedores creados por él.
         */
        if ($this->isLegacyPmUser($user)) {
            return (int) $proveedor->created_by_user_id === (int) $user->id
                && (
                    $documento->responsable_carga === 'pm'
                    || $documento->area_responsable === 'pm'
                );
        }

        /*
         * Usuarios de área:
         * Solo pueden subir si el documento corresponde a su área.
         */
        if ($this->isAreaUser($user)) {
            if (empty($user->area)) {
                return false;
            }

            if ($user->area === 'pm') {
                return (int) $proveedor->created_by_user_id === (int) $user->id
                    && (
                        $documento->responsable_carga === 'pm'
                        || $documento->area_responsable === 'pm'
                    );
            }

            return $documento->area_responsable === $user->area;
        }

        return false;
    }

    public function canReviewDocumento(User $user, ProveedorDocumento $documento): bool
    {
        $proveedor = $documento->proveedor;

        if (!$proveedor) {
            return false;
        }

        if (!$this->isInternalUser($user)) {
            return false;
        }

        if ((int) $documento->visible_para_internos !== 1) {
            return false;
        }

        if ($this->isAdminGlobal($user)) {
            return true;
        }

        /*
         * PM legacy:
         * Revisa documentos PM de proveedores creados por él.
         */
        if ($this->isLegacyPmUser($user)) {
            return (int) $proveedor->created_by_user_id === (int) $user->id
                && (
                    $documento->area_responsable === 'pm'
                    || $documento->responsable_carga === 'pm'
                );
        }

        /*
         * Usuarios de área:
         * Solo revisan documentos de su área.
         */
        if ($this->isAreaUser($user)) {
            if (empty($user->area)) {
                return false;
            }

            if ($user->area === 'pm') {
                return (int) $proveedor->created_by_user_id === (int) $user->id
                    || $documento->area_responsable === 'pm'
                    || $documento->responsable_carga === 'pm';
            }

            return $documento->area_responsable === $user->area;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Workflow - compatibilidad legacy
    |--------------------------------------------------------------------------
    |
    | Aunque la UI principal ahora usa documentos, se mantienen estos métodos
    | para no romper endpoints antiguos.
    |
    */

    public function canViewWorkflowStep(
        User $user,
        Proveedores $proveedor,
        ProveedorWorkflowStep $step
    ): bool {
        if ($this->isAdminGlobal($user) || $this->isAuditor($user)) {
            return true;
        }

        if ($this->isLegacyPmUser($user)) {
            return $step->area === 'pm'
                && (int) $proveedor->created_by_user_id === (int) $user->id;
        }

        if ($this->isAreaUser($user)) {
            if (empty($user->area)) {
                return false;
            }

            return $step->area === $user->area;
        }

        return false;
    }

    public function canUpdateWorkflowStep(
        User $user,
        Proveedores $proveedor,
        ProveedorWorkflowStep $step
    ): bool {
        if ($this->isAdminGlobal($user)) {
            return true;
        }

        if ($this->isLegacyPmUser($user)) {
            return $step->area === 'pm'
                && (int) $proveedor->created_by_user_id === (int) $user->id;
        }

        if ($this->isAreaUser($user)) {
            if (empty($user->area)) {
                return false;
            }

            return $step->area === $user->area;
        }

        return false;
    }

    public function canReopenWorkflowStep(User $user): bool
    {
        return $this->isAdminGlobal($user);
    }

    /*
    |--------------------------------------------------------------------------
    | Validaciones - compatibilidad legacy
    |--------------------------------------------------------------------------
    */

    public function canViewValidation(
        User $user,
        Proveedores $proveedor,
        ProveedorValidacion $validacion
    ): bool {
        if ($this->isAdminGlobal($user) || $this->isAuditor($user)) {
            return true;
        }

        if ($this->isLegacyPmUser($user)) {
            return $validacion->area === 'pm'
                && (int) $proveedor->created_by_user_id === (int) $user->id;
        }

        if ($this->isAreaUser($user)) {
            if (empty($user->area)) {
                return false;
            }

            return $validacion->area === $user->area;
        }

        return false;
    }

    public function canUpdateValidation(
        User $user,
        Proveedores $proveedor,
        ProveedorValidacion $validacion
    ): bool {
        if ($this->isAdminGlobal($user)) {
            return true;
        }

        if ($this->isLegacyPmUser($user)) {
            return $validacion->area === 'pm'
                && (int) $proveedor->created_by_user_id === (int) $user->id;
        }

        if ($this->isAreaUser($user)) {
            if (empty($user->area)) {
                return false;
            }

            return $validacion->area === $user->area;
        }

        return false;
    }

    public function canReopenValidation(User $user): bool
    {
        return $this->isAdminGlobal($user);
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard / queries de visibilidad
    |--------------------------------------------------------------------------
    */

    public function applyProveedorVisibilityQuery($query, User $user): void
    {
        if ($this->isAdminGlobal($user) || $this->isAuditor($user)) {
            return;
        }

        if ($this->isProveedor($user)) {
            $query->where('proveedor_user_id', $user->id);
            return;
        }

        if ($this->isLegacyPmUser($user)) {
            $query->where('created_by_user_id', $user->id);
            return;
        }

        if ($this->isAreaUser($user)) {
            if (empty($user->area)) {
                $query->whereRaw('1 = 0');
                return;
            }

            if ($user->area === 'pm') {
                $query->where(function ($q) use ($user) {
                    $q->where('created_by_user_id', $user->id)
                        ->orWhereHas('documentos', function ($d) {
                            $d->where('visible_para_internos', 1)
                                ->where(function ($sub) {
                                    $sub->where('area_responsable', 'pm')
                                        ->orWhere('responsable_carga', 'pm');
                                });
                        });
                });

                return;
            }

            $query->whereHas('documentos', function ($d) use ($user) {
                $d->where('visible_para_internos', 1)
                    ->where('area_responsable', $user->area);
            });
        }
    }
}