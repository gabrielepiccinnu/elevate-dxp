<?php

declare(strict_types=1);

namespace ElevateDxp\Insights\Copilot;

use OpenDxp\Model\DataObject;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\Document;

/**
 * Turns a data object or document into compact plain-text context for a prompt.
 * Only scalar values are included; relations are reduced to their paths.
 */
final class ElementContext
{
    public const MAX_CHARS = 8000;

    public function forPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (($object = DataObject::getByPath($path)) instanceof Concrete) {
            return $this->forObject($object);
        }
        if (($document = Document::getByPath($path)) instanceof Document\PageSnippet) {
            $lines = ['Document '.$document->getFullPath()];
            foreach ($document->getEditables() as $name => $editable) {
                $data = $editable->getData();
                if (\is_scalar($data) && trim(strip_tags((string) $data)) !== '') {
                    $lines[] = $name.': '.trim(strip_tags((string) $data));
                }
            }

            return self::truncate(implode("\n", $lines));
        }
        throw new \InvalidArgumentException('No data object or page found at '.$path);
    }

    public function forObject(Concrete $object): string
    {
        $lines = [\sprintf('%s %s', $object->getClassName(), $object->getFullPath())];
        foreach ($object->getClass()->getFieldDefinitions() as $name => $definition) {
            $getter = 'get'.ucfirst($name);
            if (!method_exists($object, $getter)) {
                continue;
            }
            $value = self::scalar($object->$getter());
            if ($value !== null && $value !== '') {
                $lines[] = ($definition->getTitle() ?: $name).': '.$value;
            }
        }

        return self::truncate(implode("\n", $lines));
    }

    private static function scalar(mixed $v): ?string
    {
        return match (true) {
            $v === null => null,
            \is_bool($v) => $v ? 'yes' : 'no',
            \is_scalar($v) => trim(strip_tags((string) $v)),
            $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
            \is_object($v) && method_exists($v, 'getFullPath') => $v->getFullPath(),
            \is_object($v) && method_exists($v, '__toString') => (string) $v,
            \is_array($v) => implode(', ', array_filter(array_map(self::scalar(...), $v))),
            default => null,
        };
    }

    private static function truncate(string $s): string
    {
        return mb_strlen($s) > self::MAX_CHARS ? mb_substr($s, 0, self::MAX_CHARS).'…' : $s;
    }
}
