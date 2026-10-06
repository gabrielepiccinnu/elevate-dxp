# SSO

The security-critical core of single sign-on for the OpenDXP admin: deny-by-default mapping of IdP groups to OpenDXP roles, native user provisioning, and one login decision service. The module does not ship the OIDC authorization-code flow itself; see [Hooking an OIDC login flow into the admin](#hooking-an-oidc-login-flow-into-the-admin).

Config key: `elevate_dxp.sso` · Permission: `elevate_dxp_sso` · Admin menu: Elevate DXP → Security

See also: [Configuration](../configuration.md), [Architecture](../architecture.md), [Security and privacy](../security-and-privacy.md).

## Components

| Class | Role |
|---|---|
| `ElevateDxp\Sso\Identity\RoleMappingIdentityMapper` | Maps claims to an `IdentityDescriptor`. Only groups listed in `role_mapping` grant a role; matching is exact and case-sensitive. |
| `ElevateDxp\Sso\Identity\IdentityDescriptor` | Identifier, email, mapped roles. `isAuthorized()` requires a non-empty identifier and at least one role. |
| `ElevateDxp\Sso\Provisioning\RolePlan` | Splits mapped roles into the admin flag (`admin` or `ROLE_OPENDXP_ADMIN`, case-insensitive) and role names. |
| `ElevateDxp\Sso\Provisioning\OpenDxpUserProvisioner` | Upserts an `OpenDxp\Model\User` (name = identifier), sets the email, activates it, and replaces its admin flag and roles. Role names are resolved with `OpenDxp\Model\User\Role::getByName()`; unknown roles are ignored, never created. |
| `ElevateDxp\Sso\Provisioning\UserDirectory` | User and role lookups by name. |
| `ElevateDxp\Sso\Login\SsoLoginService` | `decide(array $claims): SsoDecision` and `login(array $claims): User` (throws `SsoDeniedException`). Public service, so an app authenticator can use it. |

## Configuration

```yaml
elevate_dxp:
    sso:
        enabled: false              # default: every SSO login is denied
        groups_claim: groups        # claim holding the IdP groups (string or list)
        identifier_claim: sub       # OpenDXP user name; falls back to "sub", then "email"
        jit_provisioning: false     # true = create unknown users on first login
        providers:
            keycloak:
                type: oidc          # only "oidc" is accepted
                issuer: 'https://idp.example.com/realms/dxp'
                client_id: 'opendxp'
                client_secret: '%env(SSO_CLIENT_SECRET)%'
                scopes: [openid, email, profile, groups]   # default
                role_mapping:
                    dxp_admins: admin      # "admin" / "ROLE_OPENDXP_ADMIN" sets the admin flag
                    dxp_editors: Editor    # an existing OpenDXP role name
```

`providers` defaults to empty, so nothing is mapped until you configure it. `issuer`, `client_id`, `client_secret` and `scopes` are not used by the module itself (it has no OIDC client); they are shown in the admin resource and available to your integration through the `elevate_dxp_sso.providers` container parameter.

The `role_mapping` of all providers is merged into the single lookup table used at login; when two providers map the same group, the later one wins.

Caveat: Symfony config key normalisation rewrites a `role_mapping` key that contains `-` but no `_` (for example `dxp-admins` becomes `dxp_admins`), so such groups never match the claim. Use group names without hyphens, or include an underscore, until the node disables key normalisation.

## Decision rules

`SsoLoginService::decide()` checks in order; the first match decides:

1. SSO disabled → deny.
2. No identifier → deny.
3. No group maps to a role → deny.
4. The mapped roles grant nothing: none of them exists in OpenDXP and none is an admin alias → deny.
5. User unknown and `jit_provisioning` false → deny.
6. User exists but is deactivated → deny (SSO never re-activates an account).
7. Otherwise → allow. `login()` provisions the user and syncs the admin flag and roles on every login, so IdP group changes propagate.

Role names that do not exist in OpenDXP are ignored (never created). A login whose groups map only to such roles is denied (rule 4); with at least one existing role, or the admin flag, it is allowed and the missing roles are skipped. The provisioner enforces the same rule.

## Admin resource `sso_mapping`

Read-only ("SSO mapping"); the configuration is the source of truth. One row per `provider / IdP group → OpenDXP role`, with its effect: sets the admin flag, assigns role #id, or "does not exist in OpenDXP — mapping is ignored".

Actions (global):

- **Simulate mapping.** Params: claims (JSON object) and the mapping to use (one provider, or all providers merged, as at login). Shows identifier, email, groups, unmapped groups, admin flag, roles (flagging missing ones), whether the user exists and is active, and the ALLOW/DENY decision with its reason. It applies `jit_provisioning` but not `enabled` (a note is added when SSO is disabled). Nothing is changed.
- **Show settings.** The effective settings; client secrets are masked.

## Hooking an OIDC login flow into the admin

The admin firewall is built from the container parameter `opendxp_admin_bundle.firewall_settings`, set from the `opendxp_admin.security_firewall` variable node. Its defaults are in `vendor/open-dxp/admin-bundle/config/opendxp/default.yaml` and already include `custom_authenticators`.

1. **Choose an OIDC client library** that validates the ID token: signature (JWKS), `iss`, `aud`, `exp`, and the `state`/`nonce` you issued.

2. **Add two routes under `/admin` and make them public:**

   ```yaml
   # config/routes.yaml
   app_sso_start:    { path: /admin/login/sso/start,    controller: App\Controller\SsoController::start }
   app_sso_callback: { path: /admin/login/sso/callback, controller: App\Controller\SsoController::callback }
   ```

   ```yaml
   # config/packages/security.yaml, access_control, before "^/admin"
   - { path: ^/admin/login/sso/, roles: PUBLIC_ACCESS }
   ```

   `start` stores `state` and `nonce` in the session and redirects to the IdP. The callback request is handled by the authenticator below.

3. **Write the authenticator**, delegating every authorisation decision to `SsoLoginService`:

   ```php
   use ElevateDxp\Sso\Login\SsoDeniedException;
   use ElevateDxp\Sso\Login\SsoLoginService;
   use OpenDxp\Security\User\User as SecurityUser;
   // + Symfony Security / HttpFoundation / Routing imports

   final class OidcAdminAuthenticator extends AbstractAuthenticator
   {
       public function __construct(private SsoLoginService $sso, private YourOidcClient $oidc, private RouterInterface $router) {}

       public function supports(Request $request): ?bool
       {
           return $request->attributes->get('_route') === 'app_sso_callback';
       }

       public function authenticate(Request $request): Passport
       {
           $claims = $this->oidc->exchangeAndValidate($request); // code -> tokens; verify signature/iss/aud/exp/nonce/state
           try {
               $user = $this->sso->login($claims);              // deny-by-default mapping + provisioning
           } catch (SsoDeniedException) {
               throw new CustomUserMessageAuthenticationException('SSO login denied.');
           }

           return new SelfValidatingPassport(new UserBadge($user->getName(), fn () => new SecurityUser($user)));
       }

       public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
       {
           return new RedirectResponse($this->router->generate('opendxp_admin_index'));
       }

       public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
       {
           return new RedirectResponse($this->router->generate('opendxp_admin_login'));
       }
   }
   ```

4. **Register it on the admin firewall.** `security_firewall` is a variable node, so overriding it replaces the whole default block: copy every key from `default.yaml` and append your authenticator.

   ```yaml
   # config/config.yaml
   opendxp_admin:
       security_firewall:
           # copy pattern, user_checker, provider, login_throttling, logout, form_login, two_factor
           # from vendor/open-dxp/admin-bundle/config/opendxp/default.yaml
           custom_authenticators:
               - OpenDxp\Bundle\AdminBundle\Security\Authenticator\AdminTokenAuthenticator
               - App\Security\OidcAdminAuthenticator
   ```

5. **Add a login button (optional).** Listen to `OpenDxp\Bundle\AdminBundle\Event\AdminEvents::LOGIN_BEFORE_RENDER` (a `GenericEvent`): read `$event->getArgument('parameters')`, add `$parameters['includeTemplates']['App'] = 'admin/sso_button.html.twig'`, and set the argument back. The template links to `path('app_sso_start')`.

6. **Reduce password-login exposure (optional).** Form login stays available. OpenDXP supports a custom admin entry point (`opendxp_admin.custom_admin_path_identifier`, at least 20 characters), documented in `vendor/open-dxp/admin-bundle/docs/10_Extension_Points/07_Custom_Admin_Login.md`: `/admin` then requires a cookie set by your secret entry route. Give that route only to break-glass administrators, and make sure `app_sso_start` and the callback remain usable for everyone else in that setup.

Two-factor authentication: OpenDXP 2FA still applies to users who enrolled it. If the IdP already enforces MFA, avoid enrolling OpenDXP 2FA for SSO accounts.
