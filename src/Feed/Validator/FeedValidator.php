<?php

declare(strict_types=1);

namespace ElevateDxp\Feed\Validator;

use ElevateDxp\Feed\Template\FeedTemplateInterface;

/** Checks that each row has all of the template's required fields present and non-empty. */
final class FeedValidator
{
    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return list<array{index:int,missing:list<string>}> issues (empty = valid)
     */
    public function validate(FeedTemplateInterface $template, array $rows): array
    {
        $required = $template->requiredFields();
        $issues = [];
        foreach ($rows as $i => $row) {
            $missing = [];
            foreach ($required as $field) {
                $val = $row[$field] ?? null;
                if ($val === null || $val === '' || (\is_array($val) && $val === [])) {
                    $missing[] = $field;
                }
            }
            if ($missing !== []) {
                $issues[] = ['index' => $i, 'missing' => $missing];
            }
        }

        return $issues;
    }
}
