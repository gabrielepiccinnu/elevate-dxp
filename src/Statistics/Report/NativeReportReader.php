<?php

declare(strict_types=1);

namespace ElevateDxp\Statistics\Report;

use OpenDxp\Bundle\CustomReportsBundle\Tool\Config;
use OpenDxp\Model\User;

/**
 * Read access to native OpenDXP Custom Reports.
 * Uses the native adapter, so native permissions/sharing apply when a user is given.
 */
final class NativeReportReader
{
    /** @return list<array{name:string,label:string,chart:string,x:?string,y:?string,group:string}> */
    public function list(?User $user = null): array
    {
        $listing = new Config\Listing();
        $configs = $user !== null && !$user->isAdmin()
            ? $listing->getDao()->loadForGivenUser($user)
            : $listing->getDao()->loadList();

        $out = [];
        foreach ($configs as $cfg) {
            $out[] = $this->meta($cfg);
        }

        return $out;
    }

    public function exists(string $name): bool
    {
        return Config::getByName($name) !== null;
    }

    /**
     * @return array{columns:list<string>,rows:list<array<string,mixed>>}
     */
    public function run(string $name, int $limit, ?User $user = null): array
    {
        $cfg = Config::getByName($name) ?? throw new \InvalidArgumentException("Unknown native report '$name'.");
        if ($user !== null && !$user->isAdmin() && !$cfg->isAllowedForUser($user)) {
            throw new \InvalidArgumentException("Native report '$name' is not shared with you.");
        }
        $adapter = Config::getAdapter($cfg->getDataSourceConfig(), $cfg);
        $result = $adapter->getData(null, null, null, 0, $limit);
        $rows = array_values($result['data'] ?? []);
        $columns = $rows !== [] ? array_map('strval', array_keys($rows[0])) : $adapter->getColumns($cfg->getDataSourceConfig());

        return ['columns' => array_values($columns), 'rows' => $rows];
    }

    /** @return array{name:string,label:string,chart:string,x:?string,y:?string,group:string} */
    private function meta(Config $cfg): array
    {
        $y = $cfg->getYAxis();

        return [
            'name' => $cfg->getName(),
            'label' => $cfg->getNiceName() !== '' ? $cfg->getNiceName() : $cfg->getName(),
            'chart' => $cfg->getChartType() !== '' ? $cfg->getChartType() : 'none',
            'x' => $cfg->getXAxis(),
            'y' => \is_array($y) ? ($y[0] ?? null) : $y,
            'group' => $cfg->getGroup(),
        ];
    }
}
