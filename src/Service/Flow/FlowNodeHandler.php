<?php

namespace App\Service\Flow;

interface FlowNodeHandler
{
    /**
     * Exécute le node avec les données entrantes.
     * @param array $input Outputs des nodes connectés en entrée, indexés par nodeId
     */
    public function execute(array $input, FlowContext $ctx): NodeOutput;

    /** Retourne le préfixe type (ex: 'trigger', 'logic', 'action', 'http', 'ai', 'db', 'file', 'transform', 'util') */
    public static function getCategory(): string;

    /** Retourne le suffixe type (ex: 'send_email', 'condition', 'content_created') */
    public static function getType(): string;

    /** Nom complet du type (category.type), ex: 'action.send_email' */
    public static function getFullType(): string;

    /** Label humain affiché dans l'UI */
    public static function getLabel(): string;

    /** Description pour tooltip */
    public static function getDescription(): string;

    /** Icône lucide (string) */
    public static function getIcon(): string;

    /** JSON Schema de la configuration attendue */
    public static function getConfigSchema(): array;

    /** Noms des ports de sortie. ['default'] pour linéaire, ['true','false'] pour binaire, ['branch_1',...] pour switch */
    public static function getOutputPorts(): array;
}
