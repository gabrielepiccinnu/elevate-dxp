<?php

declare(strict_types=1);

namespace ElevateDxp\Insights\DataQuality;

/**
 * Computes data-quality (completeness) scores for OpenDXP DataObjects: per-field fill rate,
 * average completeness, and the worst-offending records. The pure scoring logic (scoreRows)
 * is separated from DataObject fetching so it can be unit-tested without a database.
 */
final class QualityService
{
    /** @param array<string,array<int,string>> $profiles class name => required field list */
    public function __construct(
        private readonly array $profiles = [],
        private readonly int $sampleLimit = 500,
    ) {
    }

    /** @return array<int,string> configured class names */
    public function classes(): array
    {
        return array_keys($this->profiles);
    }

    /**
     * Pure scoring over already-extracted rows. Each row: {id, key, values:{field=>value}}.
     *
     * @param array<int,array{id:int|string,key:string,values:array<string,mixed>}> $rows
     * @param array<int,string>                                                     $fields
     *
     * @return array{total:int,fields:array<int,array{field:string,filled:int,rate:float}>,averageCompleteness:float,worst:array<int,array{id:int|string,key:string,missing:array<int,string>}>}
     */
    public function scoreRows(array $rows, array $fields): array
    {
        $total = \count($rows);
        $filled = array_fill_keys($fields, 0);
        $worst = [];
        $completenessSum = 0.0;

        foreach ($rows as $row) {
            $missing = [];
            foreach ($fields as $field) {
                if ($this->isFilled($row['values'][$field] ?? null)) {
                    ++$filled[$field];
                } else {
                    $missing[] = $field;
                }
            }
            $completenessSum += $fields === [] ? 1.0 : (\count($fields) - \count($missing)) / \count($fields);
            if ($missing !== []) {
                $worst[] = ['id' => $row['id'], 'key' => $row['key'], 'missing' => $missing];
            }
        }

        $fieldStats = [];
        foreach ($fields as $field) {
            $fieldStats[] = [
                'field' => $field,
                'filled' => $filled[$field],
                'rate' => $total > 0 ? round($filled[$field] / $total * 100, 1) : 0.0,
            ];
        }

        return [
            'total' => $total,
            'fields' => $fieldStats,
            'averageCompleteness' => $total > 0 ? round($completenessSum / $total * 100, 1) : 0.0,
            'worst' => \array_slice($worst, 0, 20),
        ];
    }

    /**
     * Builds the report for a configured class by reading its DataObjects.
     *
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException for an unknown class
     */
    public function report(string $class): array
    {
        if (!isset($this->profiles[$class])) {
            throw new \InvalidArgumentException("No data-quality profile for class '$class'.");
        }
        $fields = $this->profiles[$class];
        $rows = $this->fetchRows($class, $fields);

        return ['class' => $class, 'fieldsConfigured' => $fields] + $this->scoreRows($rows, $fields);
    }

    /**
     * @param array<int,string> $fields
     *
     * @return array<int,array{id:int|string,key:string,values:array<string,mixed>}>
     */
    private function fetchRows(string $class, array $fields): array
    {
        $listingClass = 'OpenDxp\\Model\\DataObject\\'.$class.'\\Listing';
        if (!class_exists($listingClass)) {
            return [];
        }
        /** @var \OpenDxp\Model\Listing\AbstractListing $list */
        $list = new $listingClass();
        if (method_exists($list, 'setUnpublished')) {
            $list->setUnpublished(true);
        }
        $list->setLimit($this->sampleLimit);

        $rows = [];
        foreach ($list as $obj) {
            $values = [];
            foreach ($fields as $field) {
                $getter = 'get'.ucfirst($field);
                $values[$field] = method_exists($obj, $getter) ? $obj->{$getter}() : null;
            }
            $rows[] = [
                'id' => method_exists($obj, 'getId') ? $obj->getId() : 0,
                'key' => method_exists($obj, 'getKey') ? (string) $obj->getKey() : '',
                'values' => $values,
            ];
        }

        return $rows;
    }

    private function isFilled(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return false;
        }
        if (\is_object($value)) {
            // relations/images: treat as filled if present.
            return true;
        }

        return true;
    }
}
