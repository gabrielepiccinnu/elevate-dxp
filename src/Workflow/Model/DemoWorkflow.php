<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Model;

/** The demo "product_review" workflow used by the seed command / admin action. */
final class DemoWorkflow
{
    public static function create(): WorkflowDefinition
    {
        return WorkflowDefinition::fromArray([
            'name' => 'product_review',
            'label' => 'Product review',
            'subject' => 'Product',
            'type' => 'state_machine',
            'initial_place' => 'draft',
            'places' => ['draft', 'in_review', 'approved', 'rejected'],
            'transitions' => [
                'submit' => ['from' => ['draft'], 'to' => 'in_review'],
                'approve' => ['from' => ['in_review'], 'to' => 'approved'],
                'reject' => ['from' => ['in_review'], 'to' => 'rejected', 'commentRequired' => true],
                'rework' => ['from' => ['rejected'], 'to' => 'draft'],
            ],
            'placeMeta' => [
                'approved' => ['title' => 'Approved', 'color' => '#52c41a', 'lockEditing' => true],
                'rejected' => ['color' => '#f5222d'],
            ],
        ]);
    }
}
