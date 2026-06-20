<?php

namespace App\Service\Flow\Handlers\Trigger;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class ScheduleCronHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $firstInput = array_values($input)[0] ?? [];
        $data = $firstInput instanceof NodeOutput ? $firstInput->data : (is_array($firstInput) ? $firstInput : []);
        return new NodeOutput(data: $data);
    }

    public static function getCategory(): string { return 'trigger'; }
    public static function getType(): string { return 'schedule.cron'; }
    public static function getFullType(): string { return 'trigger.schedule.cron'; }
    public static function getLabel(): string { return 'Planifié (cron)'; }
    public static function getDescription(): string { return "Se déclenche selon une expression cron"; }
    public static function getIcon(): string { return 'Clock'; }

    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['schedule'],
            'properties' => [
                'schedule' => [
                    'type' => 'string',
                    'format' => 'cron',
                    'title' => 'Expression cron',
                    'description' => 'Minute Heure Jour Mois JourSemaine',
                    'default' => '0 9 * * *',
                ],
            ],
        ];
    }

    public static function getOutputPorts(): array { return ['default']; }
}
