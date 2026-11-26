export interface ApiUserResponse {
  /*
  |--------------------------------------------------------------------------
  | Campos principales legacy / sistema interno
  |--------------------------------------------------------------------------
  */

  rutusu?: string;
  digusu?: string;
  apepat?: string;
  apemat?: string;
  nombre?: string;
  userid?: string;
  mail01?: string;
  id_cliente_sap?: string;
  id_rol?: string;
  codest?: string;

  /*
  |--------------------------------------------------------------------------
  | Campos actuales del portal proveedores
  |--------------------------------------------------------------------------
  |
  | Estos campos vienen desde el backend nuevo/local.
  |
  */

  id?: string | number;
  email?: string;
  display_name?: string;
  displayName?: string;
  role?:
    | 'pm_admin'
    | 'pm_user'
    | 'proveedor'
    | 'admin_portal'
    | 'admin_area'
    | 'usuario_area'
    | 'auditor'
    | string;
  area?: string | null;
  active?: number | boolean | null;
  auth_type?: 'entra' | 'local' | string;
  authType?: 'entra' | 'local' | string;
  created_at?: string;
  createdAt?: string;
  updated_at?: string;
  updatedAt?: string;

  /*
  |--------------------------------------------------------------------------
  | Información del rol legacy
  |--------------------------------------------------------------------------
  */

  en_rol_usuario?: {
    id: string;
    rol: string;
    codest: string;
    fecha_creac: string;
    usr_creac: string;
    usr_modif: string;
    descripcion: string | null;
    de_rol_usuario: Array<{
      id: string;
      id_rol: string;
      codder: string;
      fecha_creac: string;
      usr_creac: string;
      usr_modif: string;
    }>;
  };

  /*
  |--------------------------------------------------------------------------
  | Claves del sistema legacy
  |--------------------------------------------------------------------------
  */

  re_clav_sis?: {
    rutusu: string;
    clave1: string;
    clave2: string | null;
    clave3: string | null;
    clavta1: string | null;
    clavta2: string | null;
    clavta3: string | null;
  };

  /*
  |--------------------------------------------------------------------------
  | Derechos del usuario legacy
  |--------------------------------------------------------------------------
  */

  re_usuader?: Array<{
    codusu: string;
    codder: string;
    ma_derecho: {
      codder: string;
      descri: string;
    } | null;
  }>;

  /*
  |--------------------------------------------------------------------------
  | Campos adicionales dinámicos
  |--------------------------------------------------------------------------
  */

  [key: string]: unknown;
}