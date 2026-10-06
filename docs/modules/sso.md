# Elevate DXP SSO Bundle

`elevate-dxp/sso-bundle` (`ElevateDxp\Sso`), GPL-3.0-or-later.

This bundle is the security-critical, reusable core of single sign-on for OpenDXP:

- **Deny-by-default claim → role mapping** (`RoleMappingIdentityMapper`): only IdP groups listed in `role_mapping` grant anything.
- **Identity descriptor** (`IdentityDescriptor`): an identifier, an email and roles. With no identifier or no roles, the identity is unauthorised.
- **Native user provisioning** (`OpenDxpUserProvisioner`): it upserts an `OpenDxp\Model\User` and syncs its admin flag and roles. Roles are matched by name against `OpenDxp\Model\User\Role`. Unknown roles are ignored and never created.
- **Login decision service** (`SsoLoginService`): validated claims go in; the result is an OpenDXP user or a denial with a reason.

The bundle does not ship the OIDC authorization-code flow itself. See [Hooking a login flow](#hooking-an-oidc-login-flow-into-the-admin).

It is a port of the legacy `OpenPimcore\SsoBundle`.

## Installation

```bash
bin/console opendxp:bundle:install ElevateDxpSsoBundle   # registers permission elevate_dxp_sso
```

## Configuration (`elevate_dxp_sso`)

```yaml
elevate_dxp_sso:
    enabled: false              # deny every SSO login until explicitly enabled
    groups_claim: groups
    identifier_claim: sub       # OpenDXP user name; falls back to sub, then email
    jit_provisioning: false     # true = create unknown users on first login
    providers:
        keycloak:
            type: oidc
            issuer: 'https://idp.example.com/realms/dxp'
            client_id: 'opendxp'
            client_secret: '%env(SSO_CLIENT_SECRET)%'
            scopes: [openid, email, profile, groups]
            role_mapping:
                dxp-admins: admin          # "admin" / ROLE_OPENDXP_ADMIN = admin flag
                dxp-editors: Editor        # an existing OpenDXP role name
```

The `role_mapping` of all providers is merged into the lookup table used at login. If two providers map the same group, the later provider wins. Group matching is exact and case-sensitive.

### Decision rules (`SsoLoginService::decide()`)

The rules are checked in order. The first that applies decides.

1. SSO is disabled → **deny**.
2. There is no identifier, or no group maps to a role → **deny** (deny-by-default).
3. The user is unknown and `jit_provisioning` is false → **deny**.
4. The user exists but an administrator deactivated it → **deny**. SSO never re-activates an account.
5. Otherwise → **allow**. `login()` upserts the user and syncs the admin flag and roles on every login, so an IdP group change propagates.

## Admin resource `sso_mapping`

The resource is in the **Security** group and requires `elevate_dxp_sso`.

It is read-only (the config is the source of truth). Each row is a `provider / IdP group → OpenDXP role` mapping, with its effect:

- "Sets the admin flag";
- "Assigns role #id";
- "Role does not exist — mapping is ignored".

Actions:

- **Simulate mapping** (global). Params: claims (JSON) and provider. The provider can be one provider's mapping, or all providers merged, which is what login uses. It shows:
  - the identifier and email;
  - the groups, including unmapped groups;
  - the admin flag and roles, flagging roles that are missing in OpenDXP;
  - whether a user exists;
  - the final **ALLOW/DENY** decision and its reason.

  Simulation changes nothing.
- **Show settings** (global): the effective settings. Client secrets are always masked.

## Hooking an OIDC login flow into the admin

The admin firewall is built from the parameter `opendxp_admin_bundle.firewall_settings`. That parameter comes from the `opendxp_admin.security_firewall` variable node, whose defaults are in `vendor/open-dxp/admin-bundle/config/opendxp/default.yaml`. It already uses `custom_authenticators`, and you add your OIDC authenticator there.

1. **Pick an OIDC client library.** Examples: `knpuniversity/oauth2-client-bundle` with a generic or Keycloak provider, `jumbojett/openid-connect-php`, or `web-token/jwt-library` for your own validation. The library must validate the ID token: signature (JWKS), `iss`, `aud`, `exp` and the `nonce`/`state` you issued.

2. **Add two routes inside the admin firewall** and make them public:

   ```yaml
   # config/routes.yaml
   app_sso_start:    { path: /admin/login/sso/start,    controller: App\Controller\SsoController::start }
   app_sso_callback: { path: /admin/login/sso/callback, controller: App\Controller\SsoController::callback }
   ```
   ```yaml
   # config/packages/security.yaml, access_control, BEFORE "^/admin"
   - { path: ^/admin/login/sso/, roles: PUBLIC_ACCESS }
   ```

   `start` stores `state` and `nonce` in the session and redirects to the IdP. `callback` is never executed when authentication succeeds, because the authenticator below handles the request first.

3. **Write the authenticator.** It delegates every authorisation decision to `SsoLoginService`:

   ```php
   final class OidcAdminAuthenticator extends AbstractAuthenticator
   {
       public function __construct(private SsoLoginService $sso, private YourOidcClient $oidc, private RouterInterface $router) {}

       public function supports(Request $request): ?bool
       {
           return $request->attributes->get('_route') === 'app_sso_callback';
       }

       public function authenticate(Request $request): Passport
       {
           $claims = $this->oidc->exchangeAndValidate($request); // code → tokens, verify signature/iss/aud/exp/nonce/state
           try {
               $user = $this->sso->login($claims);              // deny-by-default mapping + provisioning
           } catch (SsoDeniedException $e) {
               throw new CustomUserMessageAuthenticationException('SSO login denied.');
           }

           return new SelfValidatingPassport(new UserBadge($user->getName(), fn () => new \OpenDxp\Security\User\User($user)));
       }

       public function onAuthenticationSuccess(Request $r, TokenInterface $t, string $fw): ?Response
       {
           return new RedirectResponse($this->router->generate('opendxp_admin_index'));
       }

       public function onAuthenticationFailure(Request $r, AuthenticationException $e): ?Response
       {
           return new RedirectResponse($this->router->generate('opendxp_admin_login', ['auth_failed' => 'true']));
       }
   }
   ```

4. **Register the authenticator on the admin firewall.** `security_firewall` is a variable node, so you must repeat the full default block, then append your class:

   ```yaml
   # config/config.yaml
   opendxp_admin:
       security_firewall:
           # ... copy pattern, user_checker, provider, login_throttling, logout, form_login, two_factor
           #     from vendor/open-dxp/admin-bundle/config/opendxp/default.yaml ...
           custom_authenticators:
               - OpenDxp\Bundle\AdminBundle\Security\Authenticator\AdminTokenAuthenticator
               - App\Security\OidcAdminAuthenticator
   ```

5. **Add a login button (optional).** Listen to `OpenDxp\Bundle\AdminBundle\Event\AdminEvents::LOGIN_BEFORE_RENDER` and add `$parameters['includeTemplates']['App'] = 'admin/sso_button.html.twig'`. The template is a link to `path('app_sso_start')`.

6. **Hide the password form (optional).** Admin form login stays available. To reduce exposure, combine SSO with the *custom admin login entry point* described in `vendor/open-dxp/admin-bundle/docs/10_Extension_Points/07_Custom_Admin_Login.md`. With `opendxp_admin.custom_admin_path_identifier` set, `/admin` only accepts browsers that first visited your secret entry route. Give that route only to break-glass administrators. Everybody else signs in through `app_sso_start`, which must be reachable without the admin cookie, so add it to the custom entry point flow or exclude it accordingly.

Two factor: OpenDXP 2FA still applies to users who enabled it. If your IdP enforces MFA, tell users not to enrol OpenDXP 2FA as well, or disable 2FA for SSO accounts.

## Porting notes

- The Pimcore roles are replaced by OpenDXP roles. The admin aliases are `admin` and `ROLE_OPENDXP_ADMIN`. `ROLE_PIMCORE_ADMIN` is still accepted.
- `PimcoreUserProvisioner` is renamed `OpenDxpUserProvisioner`. User and role lookups go through `UserDirectory`.
- New pieces:
  - `identifier_claim`;
  - `SsoLoginService`, which owns the decision rules (disabled, JIT, deactivated users) that were previously left to the integrator;
  - `RolePlan`;
  - `IdentityDescriptor::isAuthorized()` now also requires an identifier.
- The live authorization-code flow is still out of scope, as in the legacy MVP. It is documented above.

## Tests

```bash
docker compose exec -T php vendor/bin/phpunit -c /var/www/packages/phpunit.xml.dist --filter SsoBundle
```
