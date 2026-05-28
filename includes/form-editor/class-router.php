<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses ?page=isf-form&id=N&task=… URL params into a resolved view name
 * and instance id, enforcing mode-based task visibility.
 *
 * Output `resolved_view()` returns one of:
 *   - 'overview'   — render the task grid
 *   - '<task-slug>' — render that task's view
 *   - 'no-task'    — render a 404-ish stub
 */
class Router {

    private array $query;
    private string $mode;

    public function __construct(array $query_params, string $mode) {
        $this->query = $query_params;
        $this->mode  = $mode;
    }

    public function instance_id(): int {
        return (int) ($this->query['id'] ?? 0);
    }

    public function resolved_view(): string {
        $task = (string) ($this->query['task'] ?? '');
        if ($task === '') {
            return 'overview';
        }
        $allowed = TaskRegistry::tasks_for_mode($this->mode);
        if (!isset($allowed[$task])) {
            return 'no-task';
        }
        return $task;
    }

    public function sub_item(): string {
        // Two-pane sub-rail selection — e.g., &dest=sftp-1 or &field=f3
        // The active key depends on the task. We just return the first match.
        foreach (['dest','field','slot','date','rule'] as $k) {
            if (isset($this->query[$k])) {
                return (string) $this->query[$k];
            }
        }
        return '';
    }
}
