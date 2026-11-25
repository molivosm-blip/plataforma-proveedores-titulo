import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';

import { useAuth } from '../../../../config/useAuth';
import type {
  User,
  UserRole,
} from '../../../../domain/auth/AuthEntity';

import {
  cleanRut,
  formatRut,
  validateRut,
} from '../../../../utils/rutUtils';

import type {
  TipoProveedorDetalle,
} from '../../../../utils/proveedorHelpers';

import {
  ProveedorActivationService,
  type ProveedorInvitationPublic,
} from '../../../../infrastructure/auth/api/proveedorActivationService';

import {
  comunasDeRegion,
  esChile,
  nombresRegiones,
} from '../../../../utils/chileGeo';

import { ui } from '../../../theme/commonStyles';
import {
  colors,
  radius,
  spacing,
} from '../../../theme/tokens';
import type { ApiError, RawApiData } from '../../../../utils/apiTypes';

type TipoProveedorIncorporacion =
  TipoProveedorDetalle;

type Step = 'auth' | 'form' | 'done';

const getDocumentosRequeridos = (
  tipoProveedor: TipoProveedorIncorporacion | ''
): string[] => {
  const documentos = [
    'Correo de aprobación de Gerencia',
    'Declaración Conflicto de Interés Solicitante',
    'Referencia comercial',
    'Ficha de incorporación proveedor',
  ];

  if (tipoProveedor === 'Proveedor de servicios') {
    documentos.push(
      'Poder de Representante Legal'
    );
  }

  if (
    tipoProveedor ===
    'Proveedor de productos nacional'
  ) {
    documentos.push(
      'Declaración Relacionados y PEP'
    );

    documentos.push(
      'Poder de Representante Legal'
    );
  }

  if (
    tipoProveedor ===
    'Proveedor de productos internacional'
  ) {
    documentos.push(
      'Declaración Relacionados y PEP'
    );

    documentos.push(
      'Poder de Representante Legal'
    );
  }

  return Array.from(new Set(documentos));
};

const isUserRole = (
  value: unknown
): value is UserRole =>
  value === 'pm_admin' ||
  value === 'pm_user' ||
  value === 'proveedor' ||
  value === 'admin_portal' ||
  value === 'admin_area' ||
  value === 'usuario_area';

const normalizeSwift = (
  value: string
): string =>
  value
    .replace(/\s+/g, '')
    .toUpperCase()
    .slice(0, 11);

const ActivarProveedor: React.FC = () => {
  const navigate = useNavigate();
  const [params] = useSearchParams();

  const token = params.get('token') || '';

  const { setUser } = useAuth();

  const [step, setStep] =
    useState<Step>('auth');

  const [error, setError] =
    useState<string | null>(null);

  const [success, setSuccess] =
    useState<string | null>(null);

  const [
    loadingInvitation,
    setLoadingInvitation,
  ] = useState(true);

  const [submitting, setSubmitting] =
    useState(false);

  const [invitation, setInvitation] =
    useState<ProveedorInvitationPublic | null>(
      null
    );

  const [password, setPassword] =
    useState('');

  const [
    passwordConfirm,
    setPasswordConfirm,
  ] = useState('');

  /*
  |--------------------------------------------------------------------------
  | Identificación
  |--------------------------------------------------------------------------
  */

  const [razonSocial, setRazonSocial] =
    useState('');

  const [rut, setRut] = useState('');
  const [giro, setGiro] = useState('');

  const [tipo, setTipo] = useState<
    'Nacional' | 'Internacional'
  >('Nacional');

  const [
    tipoProveedor,
    setTipoProveedor,
  ] = useState<
    TipoProveedorIncorporacion | ''
  >('');

  const [direccion, setDireccion] =
    useState('');

  const [comuna, setComuna] =
    useState('');

  const [region, setRegion] =
    useState('');

  const [
    codigoPostal,
    setCodigoPostal,
  ] = useState('');

  const [telefono, setTelefono] =
    useState('');

  const [moneda, setMoneda] =
    useState('CLP');

  const [pais, setPais] =
    useState('Chile');

  /*
  |--------------------------------------------------------------------------
  | Representante legal y contacto
  |--------------------------------------------------------------------------
  */

  const [
    representanteLegal,
    setRepresentanteLegal,
  ] = useState('');

  const [
    representanteCi,
    setRepresentanteCi,
  ] = useState('');

  const [
    representanteDireccion,
    setRepresentanteDireccion,
  ] = useState('');

  const [
    representanteEmail,
    setRepresentanteEmail,
  ] = useState('');

  const [
    representanteCargo,
    setRepresentanteCargo,
  ] = useState('');

  const [
    proveedorNombre,
    setProveedorNombre,
  ] = useState('');

  const [
    personaContactoCargo,
    setPersonaContactoCargo,
  ] = useState('');

  const [
    personaContactoTelefono,
    setPersonaContactoTelefono,
  ] = useState('');

  const [
    personaContactoEmail,
    setPersonaContactoEmail,
  ] = useState('');

  /*
  |--------------------------------------------------------------------------
  | Personería jurídica
  |--------------------------------------------------------------------------
  */

  const [
    fechaEscrituraPublica,
    setFechaEscrituraPublica,
  ] = useState('');

  const [notaria, setNotaria] =
    useState('');

  /*
  |--------------------------------------------------------------------------
  | Condiciones comerciales y bancarias
  |--------------------------------------------------------------------------
  */

  const [plazoPago, setPlazoPago] =
    useState('30 días');

  const [formaPago, setFormaPago] =
    useState('Transferencia');

  const [
    diasDespacho,
    setDiasDespacho,
  ] = useState('');

  const [bancoPago, setBancoPago] =
    useState('');

  const [
    cuentaCorriente,
    setCuentaCorriente,
  ] = useState('');

  const [
    codigoSwift,
    setCodigoSwift,
  ] = useState('');

  const [
    valorizadoDimensionNegocio,
    setValorizadoDimensionNegocio,
  ] = useState('');

  /*
  |--------------------------------------------------------------------------
  | Cargar invitación y datos preliminares
  |--------------------------------------------------------------------------
  */

  useEffect(() => {
    const loadInvitation = async () => {
      if (!token) {
        setLoadingInvitation(false);
        return;
      }

      try {
        setLoadingInvitation(true);
        setError(null);

        const data =
          await ProveedorActivationService
            .getInvitationByToken(token);

        setInvitation(data);

        const invitationData = data as RawApiData;
        const proveedor =
          invitationData?.proveedor;

        setPersonaContactoEmail(
          data.email || ''
        );

        /*
         * Precargar los datos definidos por el PM.
         */
        if (proveedor) {
          setRazonSocial(
            proveedor.razonSocial || ''
          );

          setRut(
            proveedor.rut &&
            proveedor.rut !== 'Pendiente'
              ? formatRut(
                  cleanRut(proveedor.rut)
                )
              : ''
          );

          setGiro(
            proveedor.giro &&
            proveedor.giro !== 'Pendiente'
              ? proveedor.giro
              : ''
          );

          const tipoPreliminar =
            proveedor.tipo ===
            'Internacional'
              ? 'Internacional'
              : 'Nacional';

          setTipo(tipoPreliminar);

          setTipoProveedor(
            proveedor.tipoProveedorDetalle ||
              ''
          );

          if (
            tipoPreliminar === 'Nacional'
          ) {
            setMoneda('CLP');
            setPais('Chile');
          } else {
            setMoneda('');
            setPais('');
          }
        }
      } catch (err) {
        console.error(
          'Error cargando invitación:',
          err
        );

        setInvitation(null);

        setError(
          'El link no es válido o no se pudo cargar la invitación.'
        );
      } finally {
        setLoadingInvitation(false);
      }
    };

    loadInvitation();
  }, [token]);

  const normalizedEmail = (
    invitation?.email || ''
  )
    .trim()
    .toLowerCase();

  const isInviteUsable =
    Boolean(invitation) &&
    invitation?.status === 'pending' &&
    Boolean(normalizedEmail) &&
    Boolean(token);

  /*
  |--------------------------------------------------------------------------
  | Documentos informativos
  |--------------------------------------------------------------------------
  */

  const documentosEmpresa = useMemo(
    () =>
      getDocumentosRequeridos(
        tipoProveedor
      ),
    [tipoProveedor]
  );

  /*
  |--------------------------------------------------------------------------
  | Eventos
  |--------------------------------------------------------------------------
  */

  const handleTipoChange = (
    value: 'Nacional' | 'Internacional'
  ) => {
    setTipo(value);
    setRegion('');
    setComuna('');

    if (value === 'Nacional') {
      setMoneda('CLP');
      setPais('Chile');
      return;
    }

    setMoneda('');
    setPais('');
  };

  const paisEsChile = esChile(pais);

  const comunasDisponibles = useMemo(
    () => comunasDeRegion(region),
    [region]
  );

  const handlePaisChange = (value: string) => {
    const eraChile = esChile(pais);

    setPais(value);

    /*
     * Al cambiar entre Chile y otro país, la región y la comuna se
     * vuelven a ingresar: una lista no sirve para el otro formato.
     */
    if (eraChile !== esChile(value)) {
      setRegion('');
      setComuna('');
    }
  };

  const handleRegionChange = (value: string) => {
    setRegion(value);
    setComuna('');
  };

  const handleContinueAuth = () => {
    setError(null);
    setSuccess(null);

    if (!isInviteUsable) {
      setError(
        'El link no es válido o ya fue utilizado.'
      );
      return;
    }

    if (
      !password ||
      password.length < 10 ||
      !/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]/.test(password) ||
      !/\d/.test(password)
    ) {
      setError(
        'La contraseña debe tener al menos 10 caracteres, con letras y números.'
      );
      return;
    }

    if (password !== passwordConfirm) {
      setError(
        'Las contraseñas no coinciden.'
      );
      return;
    }

    setPersonaContactoEmail(
      normalizedEmail
    );

    setStep('form');
  };

  const validateForm = (): string | null => {
    if (
      !razonSocial.trim() ||
      !rut.trim()
    ) {
      return 'Completa la razón social y el RUT.';
    }

    if (
      !giro.trim() ||
      !direccion.trim() ||
      !comuna.trim() ||
      !moneda.trim() ||
      !pais.trim()
    ) {
      return 'Completa Giro, Dirección, Comuna, Moneda y País.';
    }

    if (paisEsChile && !region.trim()) {
      return 'Selecciona la región.';
    }

    if (
      paisEsChile &&
      !comunasDisponibles.includes(comuna.trim())
    ) {
      return 'Selecciona una comuna de la región elegida.';
    }

    if (
      !proveedorNombre.trim() ||
      !personaContactoTelefono.trim() ||
      !personaContactoEmail.trim()
    ) {
      return 'Completa los datos obligatorios de contacto del proveedor.';
    }

    if (
      !plazoPago.trim() ||
      !formaPago.trim() ||
      !bancoPago.trim() ||
      !cuentaCorriente.trim()
    ) {
      return 'Completa las condiciones obligatorias de pago.';
    }

    if (!validateRut(rut)) {
      return 'El RUT ingresado no es válido.';
    }

    const swiftNormalizado =
      normalizeSwift(codigoSwift);

    if (
      tipo === 'Internacional' &&
      !swiftNormalizado
    ) {
      return 'Ingresa el Código SWIFT/BIC para el proveedor internacional.';
    }

    if (
      swiftNormalizado &&
      !/^[A-Z0-9]{8}([A-Z0-9]{3})?$/.test(
        swiftNormalizado
      )
    ) {
      return 'El Código SWIFT/BIC debe tener 8 u 11 caracteres alfanuméricos.';
    }

    if (!tipoProveedor) {
      return 'La solicitud preliminar no tiene un tipo de proveedor configurado. Contacta al solicitante.';
    }

    return null;
  };

  const handleSubmit = async (
    event: React.FormEvent
  ) => {
    event.preventDefault();

    setError(null);
    setSuccess(null);

    if (
      !isInviteUsable ||
      !invitation
    ) {
      setError(
        'El link no es válido o ya fue utilizado.'
      );
      return;
    }

    const validationError =
      validateForm();

    if (validationError) {
      setError(validationError);
      return;
    }

    const nombreFinalProveedor =
      proveedorNombre.trim() ||
      razonSocial.trim();

    setSubmitting(true);

    try {
      const response =
        await ProveedorActivationService
          .activate({
            token,

            password,
            passwordConfirm,

            razonSocial:
              razonSocial.trim(),

            rut: formatRut(
              cleanRut(rut)
            ),

            giro: giro.trim(),
            tipo,

            direccion:
              direccion.trim(),

            comuna:
              comuna.trim(),

            region:
              region.trim(),

            codigoPostal:
              codigoPostal.trim(),

            telefono:
              telefono.trim(),

            moneda:
              moneda.trim(),

            pais:
              pais.trim(),

            representanteLegal:
              representanteLegal.trim(),

            representanteCi:
              representanteCi.trim(),

            representanteDireccion:
              representanteDireccion.trim(),

            representanteEmail:
              representanteEmail.trim(),

            representanteCargo:
              representanteCargo.trim(),

            proveedorNombre:
              nombreFinalProveedor,

            personaContactoCargo:
              personaContactoCargo.trim(),

            personaContactoTelefono:
              personaContactoTelefono.trim(),

            personaContactoEmail:
              personaContactoEmail.trim(),

            fechaEscrituraPublica:
              fechaEscrituraPublica.trim(),

            notaria:
              notaria.trim(),

            plazoPago:
              plazoPago.trim(),

            formaPago:
              formaPago.trim(),

            diasDespacho:
              diasDespacho.trim(),

            bancoPago:
              bancoPago.trim(),

            cuentaCorriente:
              cuentaCorriente.trim(),

            codigoSwift:
              codigoSwift ? normalizeSwift(codigoSwift) : '',

            dimensionNegocioTexto:
              valorizadoDimensionNegocio.trim(),
          });

      const backendUser =
        response.session.user || {};

      const backendRole = isUserRole(
        backendUser.role
      )
        ? backendUser.role
        : 'proveedor';

      const authUser: User = {
        id: String(
          backendUser.id ?? ''
        ),

        email: String(
          backendUser.email ??
            normalizedEmail
        ),

        token:
          response.session.token,

        role:
          backendRole,

        area:
          backendUser.area ?? null,

        active:
          backendUser.active ?? 1,

        authType:
          'local',

        auth_type:
          'local',

        displayName:
          backendUser.displayName ??
          backendUser.display_name ??
          nombreFinalProveedor,

        display_name:
          backendUser.display_name ??
          backendUser.displayName ??
          nombreFinalProveedor,
      };

      localStorage.setItem(
        'auth_user',
        JSON.stringify(authUser)
      );

      await setUser(authUser);

      setSuccess(
        'Registro completado. Redirigiendo...'
      );

      setStep('done');

      setTimeout(() => {
        navigate(
          '/proveedores/mis-solicitudes',
          {
            replace: true,
          }
        );
      }, 400);
    } catch (caught: unknown) {
      const err = caught as ApiError;
      console.error(
        'Error activando proveedor:',
        err
      );

      const message =
        err?.response?.data?.message ||
        err?.response?.data?.error ||
        'No se pudo completar la activación del proveedor.';

      setError(message);
    } finally {
      setSubmitting(false);
    }
  };

  /*
  |--------------------------------------------------------------------------
  | Estados de carga y validación de la invitación
  |--------------------------------------------------------------------------
  */

  if (loadingInvitation) {
    return (
      <div style={ui.page}>
        <div
          style={{
            ...ui.card,
            maxWidth: '720px',
          }}
        >
          <h1 style={ui.title}>
            Validando invitación
          </h1>

          <p style={ui.subtitle}>
            Estamos verificando el link
            de activación...
          </p>
        </div>
      </div>
    );
  }

  if (!token) {
    return (
      <div style={ui.page}>
        <div
          style={{
            ...ui.card,
            maxWidth: '720px',
          }}
        >
          <h1 style={ui.title}>
            Link inválido
          </h1>

          <p style={ui.subtitle}>
            Falta el token de activación.
          </p>

          <button
            type="button"
            style={ui.secondaryButton}
            onClick={() =>
              navigate('/login')
            }
          >
            Ir al login
          </button>
        </div>
      </div>
    );
  }

  if (!invitation) {
    return (
      <div style={ui.page}>
        <div
          style={{
            ...ui.card,
            maxWidth: '720px',
          }}
        >
          <h1 style={ui.title}>
            Link inválido
          </h1>

          <p style={ui.subtitle}>
            No se encontró una invitación
            asociada.
          </p>

          <button
            type="button"
            style={ui.secondaryButton}
            onClick={() =>
              navigate('/login')
            }
          >
            Ir al login
          </button>
        </div>
      </div>
    );
  }

  if (invitation.status !== 'pending') {
    return (
      <div style={ui.page}>
        <div
          style={{
            ...ui.card,
            maxWidth: '720px',
          }}
        >
          <h1 style={ui.title}>
            Link no disponible
          </h1>

          <p style={ui.subtitle}>
            Este link ya fue utilizado o
            fue revocado. Solicita uno nuevo
            al PM.
          </p>

          <button
            type="button"
            style={ui.secondaryButton}
            onClick={() =>
              navigate('/login')
            }
          >
            Ir al login
          </button>
        </div>
      </div>
    );
  }

  /*
  |--------------------------------------------------------------------------
  | Render
  |--------------------------------------------------------------------------
  */

  return (
    <div style={ui.page}>
      <div
        style={{
          ...ui.bannerCard,
          maxWidth: '1080px',
        }}
      >
        <div>
          <p style={styles.eyebrow}>
            Portal de proveedores
          </p>

          <h1 style={ui.title}>
            Activación de cuenta
          </h1>

          <p style={ui.subtitle}>
            Correo:{' '}
            <strong>
              {normalizedEmail}
            </strong>
          </p>
        </div>
      </div>

      {error && (
        <div
          style={{
            ...ui.errorBox,
            marginBottom: spacing.md,
          }}
        >
          {error}
        </div>
      )}

      {success && (
        <div
          style={{
            ...ui.successBox,
            marginBottom: spacing.md,
          }}
        >
          {success}
        </div>
      )}

      <div
        style={{
          ...ui.card,
          maxWidth: '1080px',
        }}
      >
        {step === 'auth' && (
          <div>
            <h2 style={ui.sectionTitle}>
              Definir contraseña
            </h2>

            <div style={styles.gridOne}>
              <Field label="Contraseña *">
                <input
                  type="password"
                  value={password}
                  onChange={event =>
                    setPassword(
                      event.target.value
                    )
                  }
                  style={ui.input}
                  placeholder="Mínimo 10 caracteres, con letras y números"
                />
              </Field>

              <Field label="Confirmar contraseña *">
                <input
                  type="password"
                  value={passwordConfirm}
                  onChange={event =>
                    setPasswordConfirm(
                      event.target.value
                    )
                  }
                  style={ui.input}
                  placeholder="Repite la contraseña"
                />
              </Field>
            </div>

            <div style={styles.actionsEnd}>
              <button
                type="button"
                style={ui.primaryButton}
                onClick={handleContinueAuth}
              >
                Continuar
              </button>
            </div>
          </div>
        )}

        {step === 'form' && (
          <form onSubmit={handleSubmit}>
            <Section title="1. Identificación de la empresa">
              <div style={styles.formGrid}>
                <Field label="Razón Social *">
                  <input
                    value={razonSocial}
                    onChange={event =>
                      setRazonSocial(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Ej: Comercial ABC Ltda."
                    disabled={submitting}
                  />
                </Field>

                <Field label="RUT *">
                  <input
                    value={rut}
                    onChange={event =>
                      setRut(
                        formatRut(
                          event.target.value
                        )
                      )
                    }
                    style={ui.input}
                    placeholder="Ej: 12.345.678-9"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Tipo *">
                  <select
                    value={tipo}
                    onChange={event =>
                      handleTipoChange(
                        event.target.value as
                          | 'Nacional'
                          | 'Internacional'
                      )
                    }
                    style={ui.input}
                    disabled={submitting}
                  >
                    <option value="Nacional">
                      Nacional
                    </option>

                    <option value="Internacional">
                      Internacional
                    </option>
                  </select>
                </Field>

                <Field label="Giro *">
                  <input
                    value={giro}
                    onChange={event =>
                      setGiro(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Ej: Servicios, insumos, logística..."
                    disabled={submitting}
                  />
                </Field>

                <Field label="Dirección *">
                  <input
                    value={direccion}
                    onChange={event =>
                      setDireccion(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Ej: Av. Principal 123"
                    disabled={submitting}
                  />
                </Field>

                <Field label="País *">
                  <input
                    value={pais}
                    onChange={event =>
                      handlePaisChange(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder={
                      tipo === 'Nacional'
                        ? 'Chile'
                        : 'Ej: Perú, Argentina, China...'
                    }
                    disabled={submitting}
                  />
                </Field>

                <Field
                  label={
                    paisEsChile
                      ? 'Región *'
                      : 'Región / Estado'
                  }
                >
                  {paisEsChile ? (
                    <select
                      value={region}
                      onChange={event =>
                        handleRegionChange(
                          event.target.value
                        )
                      }
                      style={ui.input}
                      disabled={submitting}
                    >
                      <option value="">
                        Selecciona una región
                      </option>

                      {nombresRegiones().map(
                        nombre => (
                          <option
                            key={nombre}
                            value={nombre}
                          >
                            {nombre}
                          </option>
                        )
                      )}
                    </select>
                  ) : (
                    <input
                      value={region}
                      onChange={event =>
                        setRegion(
                          event.target.value
                        )
                      }
                      style={ui.input}
                      placeholder="Ej: Lima, Buenos Aires..."
                      disabled={submitting}
                    />
                  )}
                </Field>

                <Field
                  label={
                    paisEsChile
                      ? 'Comuna *'
                      : 'Ciudad / Comuna *'
                  }
                >
                  {paisEsChile ? (
                    <select
                      value={comuna}
                      onChange={event =>
                        setComuna(
                          event.target.value
                        )
                      }
                      style={ui.input}
                      disabled={
                        submitting || !region
                      }
                    >
                      <option value="">
                        {region
                          ? 'Selecciona una comuna'
                          : 'Primero selecciona la región'}
                      </option>

                      {comunasDisponibles.map(
                        nombre => (
                          <option
                            key={nombre}
                            value={nombre}
                          >
                            {nombre}
                          </option>
                        )
                      )}
                    </select>
                  ) : (
                    <input
                      value={comuna}
                      onChange={event =>
                        setComuna(
                          event.target.value
                        )
                      }
                      style={ui.input}
                      placeholder="Ej: Lima"
                      disabled={submitting}
                    />
                  )}
                </Field>

                <Field label="Código Postal">
                  <input
                    value={codigoPostal}
                    onChange={event =>
                      setCodigoPostal(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Ej: 8320000"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Teléfono">
                  <input
                    value={telefono}
                    onChange={event =>
                      setTelefono(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Ej: +56 9 1234 5678"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Moneda *">
                  <input
                    value={moneda}
                    onChange={event =>
                      setMoneda(
                        event.target.value
                          .toUpperCase()
                      )
                    }
                    style={ui.input}
                    placeholder={
                      tipo === 'Nacional'
                        ? 'CLP'
                        : 'Ej: USD, EUR, PEN...'
                    }
                    disabled={submitting}
                  />
                </Field>
              </div>
            </Section>

            <Section title="2. Representante legal y contacto">
              <div style={styles.formGrid}>
                <Field label="Representante Legal">
                  <input
                    value={representanteLegal}
                    onChange={event =>
                      setRepresentanteLegal(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Nombre representante legal"
                    disabled={submitting}
                  />
                </Field>

                <Field label="CI">
                  <input
                    value={representanteCi}
                    onChange={event =>
                      setRepresentanteCi(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Cédula de identidad"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Dirección representante">
                  <input
                    value={
                      representanteDireccion
                    }
                    onChange={event =>
                      setRepresentanteDireccion(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Dirección representante"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Email representante">
                  <input
                    type="email"
                    value={representanteEmail}
                    onChange={event =>
                      setRepresentanteEmail(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="representante@empresa.cl"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Cargo representante">
                  <input
                    value={representanteCargo}
                    onChange={event =>
                      setRepresentanteCargo(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Cargo"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Persona de contacto *">
                  <input
                    value={proveedorNombre}
                    onChange={event =>
                      setProveedorNombre(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Nombre de contacto"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Cargo contacto">
                  <input
                    value={
                      personaContactoCargo
                    }
                    onChange={event =>
                      setPersonaContactoCargo(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Cargo"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Teléfono contacto *">
                  <input
                    value={
                      personaContactoTelefono
                    }
                    onChange={event =>
                      setPersonaContactoTelefono(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Teléfono contacto"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Email contacto *">
                  <input
                    type="email"
                    value={
                      personaContactoEmail
                    }
                    onChange={event =>
                      setPersonaContactoEmail(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="contacto@empresa.cl"
                    disabled={submitting}
                  />
                </Field>
              </div>
            </Section>

            <Section title="3. Personería jurídica representante legal firmante">
              <div style={styles.formGrid}>
                <Field label="Fecha Escritura Pública">
                  <input
                    type="date"
                    value={
                      fechaEscrituraPublica
                    }
                    onChange={event =>
                      setFechaEscrituraPublica(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    disabled={submitting}
                  />
                </Field>

                <Field label="Notaría / Notario">
                  <input
                    value={notaria}
                    onChange={event =>
                      setNotaria(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Nombre notaría o notario"
                    disabled={submitting}
                  />
                </Field>
              </div>
            </Section>

            <Section title="4. Condiciones comerciales y bancarias">
              <div style={styles.formGrid}>
                <Field label="Plazo de Pago *">
                  <input
                    value={plazoPago}
                    onChange={event =>
                      setPlazoPago(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Ej: 30 días"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Forma de Pago *">
                  <input
                    value={formaPago}
                    onChange={event =>
                      setFormaPago(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Ej: Transferencia"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Cuántos días se demora en despachar">
                  <input
                    value={diasDespacho}
                    onChange={event =>
                      setDiasDespacho(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Ej: 3 días"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Banco para Pago *">
                  <input
                    value={bancoPago}
                    onChange={event =>
                      setBancoPago(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Ej: Banco Estado"
                    disabled={submitting}
                  />
                </Field>

                <Field label="Cuenta Corriente *">
                  <input
                    value={cuentaCorriente}
                    onChange={event =>
                      setCuentaCorriente(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Número de cuenta"
                    disabled={submitting}
                  />
                </Field>

                <Field
                  label={
                    tipo === 'Internacional'
                      ? 'Código SWIFT/BIC *'
                      : 'Código SWIFT/BIC'
                  }
                >
                  <input
                    value={codigoSwift}
                    onChange={event =>
                      setCodigoSwift(
                        normalizeSwift(
                          event.target.value
                        )
                      )
                    }
                    style={ui.input}
                    placeholder="Ej: BCHICLRMXXX"
                    maxLength={11}
                    disabled={submitting}
                  />
                </Field>

                <Field label="Valorizado de la Dimensión del Negocio">
                  <input
                    value={
                      valorizadoDimensionNegocio
                    }
                    onChange={event =>
                      setValorizadoDimensionNegocio(
                        event.target.value
                      )
                    }
                    style={ui.input}
                    placeholder="Ej: $5.000.000 mensual"
                    disabled={submitting}
                  />
                </Field>
              </div>
            </Section>

            <Section title="5. Documentos requeridos">
              {documentosEmpresa.length > 0 ? (
                <div
                  style={styles.chipsGrid}
                >
                  {documentosEmpresa.map(
                    documento => (
                      <div
                        key={documento}
                        style={styles.chip}
                      >
                        {documento}
                      </div>
                    )
                  )}
                </div>
              ) : (
                <p style={styles.helpText}>
                  No se encontraron documentos
                  configurados para esta solicitud.
                </p>
              )}
            </Section>

            <div style={styles.actionsEnd}>
              <button
                type="submit"
                style={{
                  ...ui.primaryButton,
                  opacity: submitting
                    ? 0.7
                    : 1,
                  cursor: submitting
                    ? 'not-allowed'
                    : 'pointer',
                }}
                disabled={submitting}
              >
                {submitting
                  ? 'Creando proveedor...'
                  : 'Crear proveedor'}
              </button>
            </div>
          </form>
        )}

        {step === 'done' && (
          <div>
            <h2 style={ui.sectionTitle}>
              Listo
            </h2>

            <p style={ui.subtitle}>
              Redirigiendo al portal...
            </p>
          </div>
        )}
      </div>
    </div>
  );
};

interface FieldProps {
  label: string;
  children: React.ReactNode;
}

const Field: React.FC<FieldProps> = ({
  label,
  children,
}) => (
  <div>
    <label style={ui.label}>
      {label}
    </label>

    {children}
  </div>
);

interface SectionProps {
  title: string;
  children: React.ReactNode;
}

const Section: React.FC<SectionProps> = ({
  title,
  children,
}) => (
  <section style={styles.sectionCard}>
    <h2 style={ui.sectionTitle}>
      {title}
    </h2>

    {children}
  </section>
);

const styles: Record<
  string,
  React.CSSProperties
> = {
  eyebrow: {
    margin: '0 0 8px 0',
    fontSize: '12px',
    fontWeight: 700,
    color: colors.primary,
    textTransform: 'uppercase',
    letterSpacing: '0.08em',
  },

  gridOne: {
    display: 'grid',
    gridTemplateColumns: '1fr',
    gap: spacing.md,
  },

  formGrid: {
    display: 'grid',
    gridTemplateColumns:
      'repeat(auto-fit, minmax(280px, 1fr))',
    gap: spacing.md,
  },

  sectionCard: {
    marginTop: spacing.lg,
    padding: '18px',
    border: `1px solid ${colors.border}`,
    borderRadius: radius.xl,
    background:
      'linear-gradient(135deg, #ffffff 0%, #f8fafc 100%)',
  },

  chipsGrid: {
    display: 'grid',
    gridTemplateColumns:
      'repeat(auto-fit, minmax(220px, 1fr))',
    gap: '10px',
  },

  chip: {
    background: colors.bgSoft,
    border: `1px solid ${colors.border}`,
    color: '#374151',
    padding: '12px 14px',
    borderRadius: radius.lg,
    fontSize: '13px',
    fontWeight: 700,
  },

  helpText: {
    margin: 0,
    fontSize: '13px',
    color: colors.textSoft,
    lineHeight: 1.5,
  },

  actionsEnd: {
    marginTop: spacing.xl,
    display: 'flex',
    justifyContent: 'flex-end',
  },
};

export default ActivarProveedor;