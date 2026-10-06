<?php

declare(strict_types=1);

namespace ElevateDxp\Translation\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Translation\Installer\TranslationInstaller;
use ElevateDxp\Translation\Provider\LibreTranslateProvider;
use ElevateDxp\Translation\Provider\ProviderRegistry;
use ElevateDxp\Translation\Provider\TranslationProviderInterface;
use ElevateDxp\Translation\Xliff\XliffService;

/**
 * Machine-translation providers (read-only, configured in YAML) with the translation tools:
 * translate text, build an XLIFF file, auto-fill XLIFF.
 */
final class TranslationProvidersResource extends AbstractAdminResource
{
    public const MAX_TEXT = 5000;
    public const MAX_XLIFF_BYTES = 2_000_000;
    public const MAX_UNITS = 500;

    /**
     * @param list<string>                       $languages
     * @param array{url?:string,api_key?:string} $libreTranslate
     */
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly XliffService $xliff,
        private readonly array $languages = ['en', 'it', 'de', 'fr', 'es', 'pt', 'nl'],
        private readonly array $libreTranslate = [],
    ) {
    }

    public function getKey(): string
    {
        return 'translation_providers';
    }

    public function getLabel(): string
    {
        return 'Translation';
    }

    public function getGroup(): string
    {
        return 'Content';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_translation';
    }

    public function getPermission(): string
    {
        return TranslationInstaller::PERMISSION;
    }

    public function getSchema(): array
    {
        $providers = array_combine($this->providers->names(), $this->providers->names());
        $langs = array_combine($this->languages, $this->languages);
        $default = $this->providers->defaultName();
        $from = Field::select('from', 'Source language', $langs, ['required' => true, 'default' => $this->languages[0] ?? 'en']);
        $to = Field::select('to', 'Target language', $langs, ['required' => true, 'default' => $this->languages[1] ?? 'it']);
        $provider = Field::select('provider', 'Provider', $providers, ['required' => true, 'default' => $default]);

        return [
            'panel' => 'crud',
            'idProperty' => 'id',
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
            'fields' => [
                Field::text('id', 'Provider', ['readOnly' => true, 'width' => 180]),
                Field::bool('default', 'Default', ['readOnly' => true]),
                Field::text('endpoint', 'Endpoint', ['readOnly' => true, 'flex' => 1]),
                Field::bool('api_key_set', 'API key set', ['readOnly' => true, 'width' => 110]),
                Field::text('description', 'Description', ['readOnly' => true, 'flex' => 2]),
            ],
            'actions' => [
                Action::record('test', 'Test provider', ['iconCls' => 'opendxp_icon_play']),
                Action::global('translate', 'Translate text', [
                    'iconCls' => 'opendxp_icon_translations',
                    'params' => [$provider, $from, $to, Field::textarea('text', 'Text', ['required' => true])],
                ]),
                Action::global('xliff_build', 'Build XLIFF', [
                    'iconCls' => 'opendxp_icon_export',
                    'params' => [$from, $to, Field::keyvalue('units', 'Units (key = source text)', ['required' => true])],
                ]),
                Action::global('xliff_fill', 'Auto-translate XLIFF', [
                    'iconCls' => 'opendxp_icon_translations',
                    'params' => [$provider, $from, $to, Field::code('xliff', 'XLIFF 1.2 (e.g. a native XLIFF export)', ['required' => true,
                        'help' => 'Empty <target> elements are filled. Languages declared on each <file> take precedence over the dialog values.'])],
                ]),
            ],
        ];
    }

    public function list(array $query): array
    {
        $default = $this->providers->defaultName();
        $rows = [];
        foreach ($this->providers->names() as $name) {
            $rows[] = [
                'id' => $name,
                'default' => $name === $default,
                'endpoint' => $name === 'libretranslate' ? (string) ($this->libreTranslate['url'] ?? '') : '',
                'api_key_set' => $name === 'libretranslate' && ($this->libreTranslate['api_key'] ?? '') !== '',
                'description' => match ($name) {
                    'pseudo' => 'Offline pseudo-translation ("[DE] text"), for testing the XLIFF flow.',
                    'libretranslate' => 'Self-hosted LibreTranslate machine translation (falls back to the source text on error).',
                    default => 'Custom provider',
                },
            ];
        }

        return $this->paginate($rows, $query, ['id', 'description']);
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
        return match ($action) {
            'test' => $this->test($id ?? throw new \InvalidArgumentException('Select a provider first.')),
            'translate' => $this->translate($params),
            'xliff_build' => $this->build($params),
            'xliff_fill' => $this->fill($params),
            default => parent::runAction($action, $id, $params),
        };
    }

    /** @return array<string, mixed> */
    private function test(string $name): array
    {
        $provider = $this->providers->getOrFail($name);
        $sample = 'Hello world';
        $translated = $provider->translate($sample, 'en', 'de');
        $error = $provider instanceof LibreTranslateProvider ? $provider->lastError() : null;
        if ($error !== null) {
            throw new \InvalidArgumentException(\sprintf('Provider "%s" failed: %s', $name, $error));
        }

        return Action::message(\sprintf('Provider "%s": "%s" -> "%s"', $name, $sample, $translated));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function translate(array $params): array
    {
        $provider = $this->provider($params);
        [$from, $to] = $this->languagePair($params);
        $text = (string) ($params['text'] ?? '');
        if (trim($text) === '') {
            throw new \InvalidArgumentException('Text is required.');
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new \InvalidArgumentException(\sprintf('Text is limited to %d characters.', self::MAX_TEXT));
        }
        $translated = $provider->translate($text, $from, $to);
        $row = ['provider' => $provider->name(), 'from' => $from, 'to' => $to, 'source' => $text, 'translated' => $translated];
        if ($provider instanceof LibreTranslateProvider && $provider->lastError() !== null) {
            $row['warning'] = 'Source returned unchanged: '.$provider->lastError();
        }

        return Action::table([$row], 'Translation');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function build(array $params): array
    {
        [$from, $to] = $this->languagePair($params);
        $units = $params['units'] ?? [];
        if (\is_string($units)) {
            $units = json_decode($units, true) ?? [];
        }
        if (!\is_array($units) || $units === []) {
            throw new \InvalidArgumentException('At least one unit (key = source text) is required.');
        }
        if (\count($units) > self::MAX_UNITS) {
            throw new \InvalidArgumentException(\sprintf('At most %d units are allowed.', self::MAX_UNITS));
        }
        $clean = [];
        foreach ($units as $key => $source) {
            if (!\is_scalar($source)) {
                throw new \InvalidArgumentException(\sprintf('Unit "%s" must be a text.', $key));
            }
            $clean[(string) $key] = (string) $source;
        }

        return Action::text($this->xliff->toXliff($clean, $from, $to), 'XLIFF '.$from.' -> '.$to);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function fill(array $params): array
    {
        $provider = $this->provider($params);
        [$from, $to] = $this->languagePair($params);
        $xml = (string) ($params['xliff'] ?? '');
        if (\strlen($xml) > self::MAX_XLIFF_BYTES) {
            throw new \InvalidArgumentException('XLIFF document is too large.');
        }
        if (!$this->xliff->isValid($xml)) {
            throw new \InvalidArgumentException('Invalid XLIFF document (DTDs are not allowed).');
        }
        if ($this->xliff->countUnits($xml) > self::MAX_UNITS) {
            throw new \InvalidArgumentException(\sprintf('At most %d trans-units can be auto-translated at once.', self::MAX_UNITS));
        }
        $filled = $this->xliff->fillTargetsPerFile($xml, static fn (string $text, ?string $f, ?string $t): string => $provider->translate($text, $f ?? $from, $t ?? $to));

        return Action::text($filled, 'Auto-translated XLIFF ('.$provider->name().')');
    }

    /** @param array<string, mixed> $params */
    private function provider(array $params): TranslationProviderInterface
    {
        $name = trim((string) ($params['provider'] ?? ''));

        return $name === '' ? $this->providers->get() : $this->providers->getOrFail($name);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{0:string,1:string}
     */
    private function languagePair(array $params): array
    {
        $pair = [];
        foreach (['from', 'to'] as $k) {
            $v = trim((string) ($params[$k] ?? ''));
            if (!preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/', $v)) {
                throw new \InvalidArgumentException(\sprintf('Invalid %s language "%s".', $k === 'from' ? 'source' : 'target', $v));
            }
            $pair[] = $v;
        }

        return [$pair[0], $pair[1]];
    }
}
