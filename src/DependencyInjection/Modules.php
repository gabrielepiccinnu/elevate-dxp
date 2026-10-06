<?php

declare(strict_types=1);

namespace ElevateDxp\DependencyInjection;

use ElevateDxp\Automation\DependencyInjection\AutomationModule;
use ElevateDxp\Core\DependencyInjection\CoreModule;
use ElevateDxp\Core\Module\ModuleInterface;
use ElevateDxp\DamMetadata\DependencyInjection\DamMetadataModule;
use ElevateDxp\Datahub\DependencyInjection\DatahubModule;
use ElevateDxp\Experiments\DependencyInjection\ExperimentsModule;
use ElevateDxp\Export\DependencyInjection\ExportModule;
use ElevateDxp\Feed\DependencyInjection\FeedModule;
use ElevateDxp\Insights\DependencyInjection\InsightsModule;
use ElevateDxp\Portal\DependencyInjection\PortalModule;
use ElevateDxp\Sso\DependencyInjection\SsoModule;
use ElevateDxp\Statistics\DependencyInjection\StatisticsModule;
use ElevateDxp\Translation\DependencyInjection\TranslationModule;
use ElevateDxp\Webhook\DependencyInjection\WebhookModule;
use ElevateDxp\Workflow\DependencyInjection\WorkflowModule;

/**
 * The module registry. Order matters only for readability of `config:dump-reference`.
 */
final class Modules
{
    /** @return list<ModuleInterface> */
    public static function all(): array
    {
        return [
            new CoreModule(),
            new ExperimentsModule(),
            new InsightsModule(),
            new FeedModule(),
            new ExportModule(),
            new DatahubModule(),
            new WebhookModule(),
            new AutomationModule(),
            new StatisticsModule(),
            new DamMetadataModule(),
            new TranslationModule(),
            new PortalModule(),
            new WorkflowModule(),
            new SsoModule(),
        ];
    }
}
