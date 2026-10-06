<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Sso\DependencyInjection\SsoModule;
use ElevateDxp\Sso\Identity\RoleMappingIdentityMapper;
use ElevateDxp\Sso\Installer\SsoInstaller;
use ElevateDxp\Sso\Login\SsoLoginService;
use ElevateDxp\Sso\Provisioning\RolePlan;
use ElevateDxp\Sso\Provisioning\UserDirectory;

/**
 * Read-only view of the SSO role mapping (config is the source of truth, GitOps-friendly) with a
 * "simulate mapping" action: paste ID-token claims and see the deny-by-default decision, without
 * creating or changing any user.
 */
final class SsoMappingResource extends AbstractAdminResource
{
    /** @param array<string,array<string,mixed>> $providers */
    public function __construct(
        private readonly array $providers,
        private readonly SsoLoginService $login,
        private readonly UserDirectory $directory,
        private readonly string $groupsClaim = 'groups',
        private readonly string $identifierClaim = 'sub',
        private readonly bool $enabled = false,
        private readonly bool $jitProvisioning = false,
    ) {
    }

    public function getKey(): string
    {
        return 'sso_mapping';
    }

    public function getLabel(): string
    {
        return 'SSO mapping';
    }

    public function getGroup(): string
    {
        return 'Security';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_sso';
    }

    public function getPermission(): string
    {
        return SsoInstaller::PERMISSION;
    }

    public function getSchema(): array
    {
        $providerOptions = ['' => 'All providers (merged, as used at login)'];
        foreach (array_keys($this->providers) as $name) {
            $providerOptions[(string) $name] = (string) $name;
        }

        return [
            'panel' => 'crud',
            'idProperty' => 'id',
            'fields' => [
                Field::text('id', 'ID', ['hidden' => true, 'grid' => false]),
                Field::text('provider', 'Provider', ['width' => 120]),
                Field::text('issuer', 'Issuer', ['flex' => 2]),
                Field::text('group', 'IdP group'),
                Field::text('role', 'OpenDXP role'),
                Field::text('target', 'Effect', ['flex' => 2]),
            ],
            'actions' => [
                Action::global('simulate', 'Simulate mapping', ['iconCls' => 'opendxp_icon_user', 'params' => [
                    Field::json('claims', 'ID-token claims (JSON)', [
                        'required' => true,
                        'default' => ['sub' => 'jane', 'email' => 'jane@example.com', $this->groupsClaim => ['editors']],
                    ]),
                    Field::select('provider', 'Mapping of', $providerOptions, ['default' => '']),
                ]]),
                Action::global('settings', 'Show settings', ['iconCls' => 'opendxp_icon_info']),
            ],
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
        ];
    }

    public function list(array $query): array
    {
        $rows = [];
        foreach ($this->providers as $name => $provider) {
            foreach ($provider['role_mapping'] ?? [] as $group => $role) {
                $rows[] = [
                    'id' => $name.':'.$group,
                    'provider' => (string) $name,
                    'issuer' => (string) ($provider['issuer'] ?? ''),
                    'group' => (string) $group,
                    'role' => (string) $role,
                    'target' => $this->effect((string) $role),
                ];
            }
        }

        return $this->paginate($rows, $query, ['provider', 'group', 'role']);
    }

    public function get(string $id): ?array
    {
        foreach ($this->list([])['data'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        if ($action === 'settings') {
            return Action::table($this->settingsRows(), 'SSO settings', ['setting', 'value']);
        }
        if ($action !== 'simulate') {
            return parent::runAction($action, $id, $params);
        }

        return Action::table($this->simulate($params['claims'] ?? null, (string) ($params['provider'] ?? '')), 'Simulated SSO login (nothing was changed)', ['check', 'result']);
    }

    /** @return list<array{check:string,result:string}> */
    public function simulate(mixed $claims, string $provider = ''): array
    {
        if (\is_string($claims)) {
            $claims = json_decode($claims, true, 32, \JSON_THROW_ON_ERROR);
        }
        if (!\is_array($claims) || array_is_list($claims) && $claims !== []) {
            throw new \InvalidArgumentException('Claims must be a JSON object, e.g. {"sub":"jane","groups":["editors"]}.');
        }
        if ($provider !== '' && !isset($this->providers[$provider])) {
            throw new \InvalidArgumentException(\sprintf('Unknown provider "%s".', $provider));
        }
        $mapping = $provider === '' ? SsoModule::mergeRoleMappings($this->providers) : (array) ($this->providers[$provider]['role_mapping'] ?? []);
        $identity = (new RoleMappingIdentityMapper($this->groupsClaim, $mapping, $this->identifierClaim))->mapClaims($claims);
        $existing = $identity->identifier !== '' ? $this->directory->findUser($identity->identifier) : null;
        $decision = $this->login->decideFor($identity, $existing, $this->jitProvisioning);
        $plan = RolePlan::fromIdentity($identity);

        $groups = $claims[$this->groupsClaim] ?? [];
        $groups = \is_array($groups) ? $groups : [$groups];
        $unmapped = array_values(array_filter(array_map(static fn ($g): string => \is_scalar($g) ? (string) $g : '', $groups), static fn (string $g): bool => $g !== '' && !isset($mapping[$g])));

        $roles = [];
        foreach ($plan->roleNames as $name) {
            $roles[] = $name.($this->directory->roleId($name) === null ? ' (missing in OpenDXP — ignored)' : '');
        }

        $rows = [
            ['check' => 'Identifier ('.$this->identifierClaim.')', 'result' => $identity->identifier !== '' ? $identity->identifier : '(none)'],
            ['check' => 'Email', 'result' => $identity->email ?? '(none)'],
            ['check' => 'Groups claim ('.$this->groupsClaim.')', 'result' => $groups === [] ? '(none)' : implode(', ', array_map(static fn ($g): string => \is_scalar($g) ? (string) $g : json_encode($g), $groups))],
            ['check' => 'Unmapped groups (grant nothing)', 'result' => $unmapped === [] ? '-' : implode(', ', $unmapped)],
            ['check' => 'Admin flag', 'result' => $plan->admin ? 'yes' : 'no'],
            ['check' => 'Roles', 'result' => $roles === [] ? '(none)' : implode(', ', $roles)],
            ['check' => 'Existing OpenDXP user', 'result' => $existing === null ? 'no' : ($existing->isActive() ? 'yes (active)' : 'yes (inactive)')],
            ['check' => 'Decision', 'result' => ($decision->allowed ? 'ALLOW — ' : 'DENY — ').$decision->reason],
        ];
        if (!$this->enabled) {
            $rows[] = ['check' => 'Note', 'result' => 'SSO is disabled (elevate_dxp_sso.enabled: false): real logins are denied.'];
        }

        return $rows;
    }

    /**
     * Secrets are never shown.
     *
     * @return list<array{setting: string, value: string}>
     */
    private function settingsRows(): array
    {
        $rows = [
            ['setting' => 'enabled', 'value' => $this->enabled ? 'true' : 'false'],
            ['setting' => 'jit_provisioning', 'value' => $this->jitProvisioning ? 'true' : 'false'],
            ['setting' => 'groups_claim', 'value' => $this->groupsClaim],
            ['setting' => 'identifier_claim', 'value' => $this->identifierClaim],
        ];
        foreach ($this->providers as $name => $p) {
            $rows[] = ['setting' => "providers.$name.issuer", 'value' => (string) ($p['issuer'] ?? '')];
            $rows[] = ['setting' => "providers.$name.client_id", 'value' => (string) ($p['client_id'] ?? '')];
            $rows[] = ['setting' => "providers.$name.client_secret", 'value' => ($p['client_secret'] ?? '') !== '' ? '•••••• (set)' : '(empty)'];
            $rows[] = ['setting' => "providers.$name.scopes", 'value' => implode(' ', (array) ($p['scopes'] ?? []))];
        }

        return $rows;
    }

    private function effect(string $role): string
    {
        if (RolePlan::isAdminAlias($role)) {
            return 'Sets the OpenDXP admin flag (full access)';
        }
        $id = $this->directory->roleId($role);

        return $id === null ? 'Role "'.$role.'" does not exist in OpenDXP — mapping is ignored' : 'Assigns role #'.$id;
    }
}
