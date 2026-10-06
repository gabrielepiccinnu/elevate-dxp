<?php

declare(strict_types=1);

namespace ElevateDxp\Insights\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Insights\Copilot\ClaudeClient;
use ElevateDxp\Insights\Copilot\ElementContext;
use ElevateDxp\Insights\Installer\InsightsInstaller;

/**
 * AI copilot for editors: presets (product copy, SEO metadata, summary) or free prompts,
 * optionally grounded on a data object or page selected in the tree.
 */
final class CopilotResource extends AbstractAdminResource
{
    public const PRESETS = [
        'product_description' => [
            'label' => 'Product description',
            'system' => 'You are a PIM copy assistant. Write a concise, factual product description (80-120 words) from the given attributes. Do not invent specifications. Output plain text only.',
        ],
        'seo_metadata' => [
            'label' => 'SEO metadata',
            'system' => 'You are an SEO assistant. Given a product or page, propose a "title" (max 60 chars) and a "description" (max 155 chars). Output JSON only.',
        ],
        'summary' => [
            'label' => 'Summary',
            'system' => 'You write a one-paragraph neutral summary of the given content. Output plain text only.',
        ],
        'ab_variant' => [
            'label' => 'A/B headline ideas',
            'system' => 'You are a conversion copywriter. Propose 5 alternative headlines to A/B test against the given one, each with a one-line hypothesis. Output a numbered list.',
        ],
        'free' => ['label' => 'Free prompt', 'system' => ''],
    ];

    public function __construct(private readonly ClaudeClient $claude, private readonly ElementContext $context)
    {
    }

    public function getKey(): string
    {
        return 'copilot';
    }

    public function getLabel(): string
    {
        return 'Copilot';
    }

    public function getGroup(): string
    {
        return 'Content';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_copilot';
    }

    public function getPermission(): string
    {
        return InsightsInstaller::COPILOT;
    }

    public function getSchema(): array
    {
        return [
            'panel' => 'crud',
            'idProperty' => 'key',
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
            'fields' => [
                Field::text('key', 'Preset', ['width' => 180]),
                Field::text('label', 'Label', ['width' => 200]),
                Field::text('system', 'Instructions'),
            ],
            'actions' => [
                Action::record('run', 'Run preset', ['params' => self::params()]),
                Action::global('status', 'Status', ['iconCls' => 'opendxp_icon_info']),
            ],
        ];
    }

    public function list(array $query): array
    {
        $rows = [];
        foreach (self::PRESETS as $key => $p) {
            $rows[] = ['key' => $key] + $p;
        }

        return $this->paginate($rows, $query, ['key', 'label']);
    }

    public function get(string $id): ?array
    {
        return isset(self::PRESETS[$id]) ? ['key' => $id] + self::PRESETS[$id] : null;
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        if ($action === 'status') {
            return Action::message($this->claude->isConfigured()
                ? 'Copilot is configured (model '.$this->claude->getModel().').'
                : 'Copilot is disabled: set ANTHROPIC_API_KEY in the environment.');
        }
        if ($action !== 'run' || $id === null || !isset(self::PRESETS[$id])) {
            return parent::runAction($action, $id, $params);
        }

        $prompt = trim((string) ($params['prompt'] ?? ''));
        $element = trim((string) ($params['element'] ?? ''));
        $context = $element !== '' ? $this->context->forPath($element) : '';
        if ($prompt === '' && $context === '') {
            throw new \InvalidArgumentException('Enter a prompt or choose an element.');
        }
        if (mb_strlen($prompt) > 4000) {
            throw new \InvalidArgumentException('Prompt too long (max 4000 characters).');
        }
        $userPrompt = trim($prompt.($context !== '' ? "\n\n<content>\n".$context."\n</content>" : ''));
        $answer = $this->claude->complete($userPrompt, self::PRESETS[$id]['system']);

        return Action::text($answer, self::PRESETS[$id]['label'].($element !== '' ? ' — '.$element : ''));
    }

    private static function params(): array
    {
        return [
            Field::element('element', 'Data object or page (optional)', 'object'),
            Field::textarea('prompt', 'Prompt / extra instructions'),
        ];
    }
}
