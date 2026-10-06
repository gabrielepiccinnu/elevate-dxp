<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Mermaid;

use ElevateDxp\Workflow\Model\WorkflowDefinition;

/** Renders a workflow definition as a Mermaid stateDiagram-v2 (the "designer view"). */
final class MermaidRenderer
{
    public function render(WorkflowDefinition $def): string
    {
        $lines = ['stateDiagram-v2'];
        if ($def->initialPlace !== '') {
            $lines[] = '    [*] --> '.$this->id($def->initialPlace);
        }
        foreach ($def->transitions as $name => $t) {
            foreach ($t['from'] as $from) {
                $lines[] = \sprintf('    %s --> %s: %s', $this->id($from), $this->id($t['to']), $this->label((string) $name));
            }
        }
        // Orphan places (not referenced) still appear.
        foreach ($def->places as $place) {
            $lines[] = '    '.$this->id($place);
        }

        return implode("\n", array_values(array_unique($lines)));
    }

    /** URL of a server-side rendering (e.g. https://mermaid.ink/svg/<base64url>). */
    public function renderUrl(string $baseUrl, string $mermaid): string
    {
        return rtrim($baseUrl, '/').'/'.rtrim(strtr(base64_encode($mermaid), '+/', '-_'), '=');
    }

    /** Link opening the diagram in the Mermaid Live Editor (state kept in the URL fragment). */
    public function liveEditorUrl(string $mermaid): string
    {
        $state = json_encode(['code' => $mermaid, 'mermaid' => '{"theme":"default"}'], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        return 'https://mermaid.live/edit#base64:'.base64_encode($state);
    }

    private function id(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $s) ?: 'p';
    }

    private function label(string $s): string
    {
        return str_replace([':', "\n", "\r"], [' ', ' ', ' '], $s);
    }
}
