<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Bpmn;

/** XML namespaces used by the constrained BPMN 2.0 mapping. */
final class BpmnNamespaces
{
    public const BPMN = 'http://www.omg.org/spec/BPMN/20100524/MODEL';
    public const BPMNDI = 'http://www.omg.org/spec/BPMN/20100524/DI';
    public const DC = 'http://www.omg.org/spec/DD/20100524/DC';
    public const DI = 'http://www.omg.org/spec/DD/20100524/DI';

    /** Extension attributes (type, subject, guard, notes, place meta). */
    public const DXPP = 'http://elevate-dxp/bpmn';
    public const DXPP_PREFIX = 'elevatedxp';
}
