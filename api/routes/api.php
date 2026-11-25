<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
*/

$router->get('/', function () {
    return 'API OK';
});


$router->get('/health/database', function () {
    try {
        $result = app('db')->select('SELECT DATABASE() AS database_name');

        return response()->json([
            'ok' => true,
            'database' => $result[0]->database_name ?? null,
            'message' => 'Conexión con MySQL operativa',
        ], 200);
    } catch (\Throwable $error) {
        return response()->json([
            'ok' => false,
            'message' => 'No fue posible conectar con MySQL',
            'detail' => env('APP_DEBUG', false)
                ? $error->getMessage()
                : null,
        ], 500);
    }
});

/*
|--------------------------------------------------------------------------
| API V1
|--------------------------------------------------------------------------
*/

$router->group([
    'prefix' => 'v1',
], function () use ($router) {

    /*
    |--------------------------------------------------------------------------
    | Autenticación - Rutas públicas
    |--------------------------------------------------------------------------
    */

    $router->post(
        'auth/login',
        'AuthController@login'
    );

    $router->post(
        'auth/msal',
        'AuthController@msalLogin'
    );

    /*
    |--------------------------------------------------------------------------
    | Empresas y catálogos - Rutas públicas
    |--------------------------------------------------------------------------
    */

    $router->get(
        'empresas',
        'EmpresasController@index'
    );

    $router->get(
        'empresas/{id}/documentos-requeridos',
        'EmpresasController@documentosRequeridos'
    );

    $router->get(
        'catalogos/proveedores',
        'EmpresasController@catalogosProveedores'
    );

    $router->get(
        'catalogos/grupos-compra',
        'EmpresasController@gruposCompra'
    );

    /*
    |--------------------------------------------------------------------------
    | Activación de proveedor - Rutas públicas
    |--------------------------------------------------------------------------
    */

    $router->get(
        'proveedor/invitations/{token}',
        'ProveedorInvitationsController@showByToken'
    );

    $router->post(
        'proveedor/activar',
        'ProveedorInvitationsController@activate'
    );

    /*
    |--------------------------------------------------------------------------
    | Dashboard - Roles autenticados
    |--------------------------------------------------------------------------
    */

    $router->group([
        'middleware' => [
            'jwt',
            'role:proveedor,pm_user,pm_admin,admin_portal,admin_area,usuario_area',
        ],
    ], function () use ($router) {
        $router->get(
            'dashboard',
            'DashboardController@index'
        );

        $router->get(
            'dashboard/actividad-reciente',
            'DashboardController@actividadReciente'
        );

        /*
        |--------------------------------------------------------------------------
        | Notificaciones del usuario autenticado
        |--------------------------------------------------------------------------
        */

        $router->get(
            'notificaciones',
            'NotificacionesController@index'
        );

        $router->put(
            'notificaciones/leer-todas',
            'NotificacionesController@leerTodas'
        );

        $router->put(
            'notificaciones/{id}/leer',
            'NotificacionesController@leer'
        );

        /*
        |--------------------------------------------------------------------------
        | Mi cuenta (siempre sobre el usuario autenticado)
        |--------------------------------------------------------------------------
        */

        $router->get('cuenta', 'CuentaController@perfil');
        $router->put('cuenta/password', 'CuentaController@cambiarPassword');
        $router->get('cuenta/notificaciones', 'CuentaController@preferencias');
        $router->put('cuenta/notificaciones', 'CuentaController@guardarPreferencias');
    });

    /*
    |--------------------------------------------------------------------------
    | Documentos - Roles autenticados
    |--------------------------------------------------------------------------
    */

    $router->group([
        'middleware' => [
            'jwt',
            'role:proveedor,pm_user,pm_admin,admin_portal,admin_area,usuario_area',
        ],
    ], function () use ($router) {
        $router->post(
            'documentos/{id}/upload',
            'ProveedorDocumentosController@upload'
        );

        $router->get(
            'documentos/{id}/archivo',
            'ProveedorDocumentosController@descargar'
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Proveedor externo
    |--------------------------------------------------------------------------
    */

    $router->group([
        'middleware' => [
            'jwt',
            'role:proveedor',
        ],
    ], function () use ($router) {

        /*
        |--------------------------------------------------------------------------
        | Documentos propios
        |--------------------------------------------------------------------------
        */

        $router->post(
            'proveedor/documentos',
            'ProveedorDocumentosController@store'
        );

        $router->get(
            'proveedor/documentos',
            'ProveedorDocumentosController@mine'
        );

        /*
        |--------------------------------------------------------------------------
        | Solicitudes propias
        |--------------------------------------------------------------------------
        */

        $router->get(
            'proveedor/solicitudes',
            'ProveedoresController@misSolicitudes'
        );

        /*
        |--------------------------------------------------------------------------
        | Declaración digital de relacionados y PEP
        |--------------------------------------------------------------------------
        */

        $router->get(
            'proveedor/solicitudes/{id}/declaraciones',
            'DeclaracionesController@index'
        );

        $router->post(
            'proveedor/solicitudes/{id}/declaraciones/relacionados-pep',
            'DeclaracionesController@firmarPep'
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Usuarios internos operativos
    |--------------------------------------------------------------------------
    */

    $router->group([
        'middleware' => [
            'jwt',
            'role:pm_user,pm_admin,admin_portal,admin_area,usuario_area',
        ],
    ], function () use ($router) {

        /*
        |--------------------------------------------------------------------------
        | Documentos
        |--------------------------------------------------------------------------
        */

        $router->get(
            'interno/documentos',
            'ProveedorDocumentosController@index'
        );

        /*
         * Ruta anterior mantenida por compatibilidad.
         */
        $router->put(
            'interno/documentos/{id}',
            'ProveedorDocumentosController@review'
        );

        $router->put(
            'interno/documentos/{id}/estado',
            'ProveedorDocumentosController@updateEstado'
        );

        /*
        |--------------------------------------------------------------------------
        | Proveedores
        |--------------------------------------------------------------------------
        */

        $router->get(
            'interno/proveedores',
            'ProveedoresController@index'
        );

        /*
         * Crea una solicitud preliminar y genera los documentos
         * obligatorios del solicitante.
         */
        $router->post(
            'interno/proveedores/solicitud',
            'ProveedoresController@crearSolicitudPreliminar'
        );

        $router->put(
            'interno/proveedores/{id}/estado',
            'ProveedoresController@updateEstado'
        );

        /*
        |--------------------------------------------------------------------------
        | Ficha automática y estado documental
        |--------------------------------------------------------------------------
        */

        $router->post(
            'interno/proveedores/{id}/ficha-pdf',
            'ProveedoresController@generarFichaPdf'
        );

        $router->post(
            'interno/proveedores/{id}/recalcular-estado',
            'ProveedoresController@recalcularEstado'
        );

        /*
        |--------------------------------------------------------------------------
        | Declaraciones digitales
        |--------------------------------------------------------------------------
        */

        $router->get(
            'interno/proveedores/{id}/declaraciones',
            'DeclaracionesController@index'
        );

        $router->post(
            'interno/proveedores/{id}/declaraciones/conflicto-interes',
            'DeclaracionesController@firmarConflicto'
        );

        /*
        |--------------------------------------------------------------------------
        | Evaluación crediticia, informe y re-evaluación (Legal Crediticia)
        |--------------------------------------------------------------------------
        */

        $router->get(
            'interno/proveedores/{id}/evaluacion-crediticia',
            'EvaluacionCrediticiaController@index'
        );

        $router->post(
            'interno/proveedores/{id}/evaluacion-crediticia',
            'EvaluacionCrediticiaController@store'
        );

        $router->post(
            'interno/proveedores/{id}/evaluacion-crediticia/reevaluar',
            'EvaluacionCrediticiaController@reevaluar'
        );

        $router->post(
            'interno/proveedores/{id}/evaluacion-crediticia/{evaluacionId}/informe',
            'EvaluacionCrediticiaController@informe'
        );

        /*
        |--------------------------------------------------------------------------
        | Plazos por etapa
        |--------------------------------------------------------------------------
        */

        $router->get(
            'interno/proveedores/{id}/plazos',
            'EvaluacionCrediticiaController@plazos'
        );

        /*
        |--------------------------------------------------------------------------
        | Servicio simulado de SAP (Datos Maestros)
        |--------------------------------------------------------------------------
        */

        $router->get(
            'interno/proveedores/{id}/sap',
            'SapMockController@show'
        );

        $router->post(
            'interno/proveedores/{id}/sap/enviar',
            'SapMockController@enviar'
        );

        /*
        |--------------------------------------------------------------------------
        | AML / Gesintel / AMLUpdate
        |--------------------------------------------------------------------------
        |
        | La autorización por área se valida adicionalmente en
        | ProveedorAmlController.
        |
        | Áreas permitidas:
        | - contraloria
        | - legal_crediticia
        |--------------------------------------------------------------------------
        */

        $router->post(
            'interno/proveedores/{id}/aml/risk',
            'ProveedorAmlController@risk'
        );

        $router->post(
            'interno/proveedores/{id}/aml/result',
            'ProveedorAmlController@result'
        );

        $router->post(
            'interno/proveedores/{id}/aml/flags',
            'ProveedorAmlController@flags'
        );

        /*
         * Genera el reporte en Gesintel y guarda el pdfLink.
         */
        $router->post(
            'interno/proveedores/{id}/aml/report',
            'ProveedorAmlController@report'
        );

        /*
         * Descarga el PDF protegido.
         *
         * El frontend envía su JWT del portal.
         * El backend solicita el archivo a Gesintel agregando:
         *
         * Authorization: TOKEN_GESINTEL
         */
        $router->get(
            'interno/proveedores/{id}/aml/report-pdf',
            'ProveedorAmlController@downloadReportPdf'
        );

        /*
        |--------------------------------------------------------------------------
        | Registro AMLUpdate / UBOFinder
        |--------------------------------------------------------------------------
        */

        $router->post(
            'interno/proveedores/{id}/aml/register',
            'ProveedorAmlController@registerRecord'
        );

        $router->post(
            'interno/proveedores/{id}/aml/get-record',
            'ProveedorAmlController@getRecord'
        );

        $router->post(
            'interno/proveedores/{id}/aml/get-pdf-record',
            'ProveedorAmlController@getPdfRecord'
        );

        /*
        |--------------------------------------------------------------------------
        | Histórico AML local
        |--------------------------------------------------------------------------
        */

        $router->get(
            'interno/proveedores/{id}/aml/checks',
            'ProveedorAmlController@checks'
        );

        $router->get(
            'interno/proveedores/{id}/aml/records',
            'ProveedorAmlController@records'
        );

        /*
        |--------------------------------------------------------------------------
        | DICOM / Cobranza
        |--------------------------------------------------------------------------
        |
        | API externa:
        |
        | GET http://cobranza.dimerc.cl/v1/dicom/{segmento}/{rut}
        | GET http://cobranza.dimerc.cl/v1/dicom/historial/{rut}
        | GET http://cobranza.dimerc.cl/v1/dicom/informacion/{rut}
        | GET http://cobranza.dimerc.cl/v1/dicom/legado/{rut}/{fecha}
        |
        | Segmentos permitidos:
        | - empresa
        | - persona
        |
        | La autorización final por área se valida dentro de
        | ProveedorDicomController.
        |--------------------------------------------------------------------------
        */

        /*
         * Las rutas específicas deben declararse antes de
         * dicom/{segmento}.
         */

        $router->post(
            'interno/proveedores/{rut}/dicom/historial',
            'ProveedorDicomController@historial'
        );

        $router->post(
            'interno/proveedores/{rut}/dicom/informacion',
            'ProveedorDicomController@informacion'
        );

        $router->post(
            'interno/proveedores/{rut}/dicom/legado/{fecha}',
            'ProveedorDicomController@legado'
        );

        $router->get(
            'interno/proveedores/{rut}/dicom/checks',
            'ProveedorDicomController@checks'
        );

        $router->post(
            'interno/proveedores/{rut}/dicom/{segmento}',
            'ProveedorDicomController@consultar'
        );

        /*
        |--------------------------------------------------------------------------
        | Invitaciones de proveedores
        |--------------------------------------------------------------------------
        */

        $router->post(
            'interno/proveedor-invitations',
            'ProveedorInvitationsController@store'
        );

        $router->get(
            'interno/proveedor-invitations',
            'ProveedorInvitationsController@index'
        );

        $router->put(
            'interno/proveedor-invitations/{id}/revoke',
            'ProveedorInvitationsController@revoke'
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Administración interna - Usuarios y áreas
    |--------------------------------------------------------------------------
    */

    $router->group([
        'middleware' => [
            'jwt',
            'role:pm_admin,admin_portal,admin_area',
        ],
    ], function () use ($router) {
        $router->get(
            'admin/areas',
            'UsuariosInternosController@areas'
        );

        $router->get(
            'admin/usuarios',
            'UsuariosInternosController@index'
        );

        $router->post(
            'admin/usuarios',
            'UsuariosInternosController@store'
        );

        $router->delete(
            'admin/usuarios/{id}',
            'UsuariosInternosController@destroy'
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Workflow - Áreas internas
    |--------------------------------------------------------------------------
    |
    | Se mantiene por compatibilidad con el backend.
    |--------------------------------------------------------------------------
    */

    $router->group([
        'middleware' => [
            'jwt',
            'role:pm_admin,pm_user,admin_portal,admin_area,usuario_area',
        ],
    ], function () use ($router) {
        $router->get(
            'workflow/proveedores/{id}',
            'WorkflowController@show'
        );

        $router->get(
            'workflow/mis-pendientes',
            'WorkflowController@misPendientes'
        );

        $router->put(
            'workflow/proveedores/{id}/steps/{stepId}',
            'WorkflowController@updateStep'
        );

        $router->put(
            'workflow/proveedores/{id}/steps/{stepId}/reabrir',
            'WorkflowController@reabrirStep'
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Validaciones - Áreas internas
    |--------------------------------------------------------------------------
    |
    | Se mantiene por compatibilidad con el backend.
    |--------------------------------------------------------------------------
    */

    $router->group([
        'middleware' => [
            'jwt',
            'role:pm_admin,pm_user,admin_portal,admin_area,usuario_area',
        ],
    ], function () use ($router) {
        $router->get(
            'validaciones/proveedores/{id}',
            'ValidacionesController@show'
        );

        $router->get(
            'validaciones/mis-pendientes',
            'ValidacionesController@misPendientes'
        );

        $router->put(
            'validaciones/proveedores/{id}/areas/{area}',
            'ValidacionesController@updateArea'
        );

        $router->put(
            'validaciones/proveedores/{id}/areas/{area}/reabrir',
            'ValidacionesController@reabrirArea'
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Administración global
    |--------------------------------------------------------------------------
    */

    $router->group([
        'middleware' => [
            'jwt',
            'role:pm_admin,admin_portal',
        ],
    ], function () use ($router) {
        /*
         * Creación manual administrativa.
         *
         * El flujo principal recomendado es:
         * solicitud preliminar, documentos del solicitante,
         * invitación y activación.
         */
        $router->post(
            'interno/proveedores',
            'ProveedoresController@crear'
        );
    });
});