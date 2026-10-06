<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Admin;

use ElevateDxp\Core\Admin\AbstractDbalResource;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Experiments\Installer\ExperimentsInstaller;
use ElevateDxp\Experiments\Tracking\EventRecorder;

final class EventResource extends AbstractDbalResource
{
    public function getKey(): string
    {
        return 'tracking-events';
    }

    public function getLabel(): string
    {
        return 'Tracking Events';
    }

    public function getGroup(): string
    {
        return 'Marketing';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_audit';
    }

    public function getPermission(): string
    {
        return ExperimentsInstaller::PERMISSION;
    }

    protected function getTable(): string
    {
        return 'edxp_event';
    }

    protected function getSearchColumns(): array
    {
        return ['event_name', 'visitor_id', 'experiment_key', 'url'];
    }

    public function getSchema(): array
    {
        return [
            'panel' => 'crud',
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
            'fields' => [
                Field::id(),
                Field::datetime('created_at', 'Time', ['width' => 150]),
                Field::text('event_name', 'Event', ['width' => 130]),
                Field::select('event_type', 'Type', array_combine(EventRecorder::TYPES, EventRecorder::TYPES), ['width' => 100]),
                Field::text('visitor_id', 'Visitor', ['width' => 250]),
                Field::text('experiment_key', 'Experiment', ['width' => 120]),
                Field::text('variant_key', 'Variant', ['width' => 80]),
                Field::number('event_value', 'Value', ['width' => 80]),
                Field::text('url', 'URL'),
                Field::json('metadata_json', 'Metadata'),
            ],
        ];
    }
}
