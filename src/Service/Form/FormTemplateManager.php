<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * Gère les templates de formulaire prédéfinis (contact, newsletter, etc.).
 *
 * Chaque template est un tableau de champs pré-remplis avec des
 * valeurs par défaut qui peuvent être personnalisées.
 */
class FormTemplateManager
{
    /** @var array<string, array{name: string, description: string, fields: array}> */
    private const TEMPLATES = [
        'contact' => [
            'name' => 'Formulaire de contact',
            'description' => 'Formulaire simple avec nom, email et message.',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Nom', 'required' => true, 'placeholder' => 'Votre nom'],
                ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true, 'placeholder' => 'vous@exemple.com'],
                ['name' => 'subject', 'type' => 'select', 'label' => 'Sujet', 'required' => false,
                    'options' => [
                        ['value' => 'info', 'label' => 'Demande d\'information'],
                        ['value' => 'support', 'label' => 'Support technique'],
                        ['value' => 'other', 'label' => 'Autre'],
                    ],
                ],
                ['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true, 'placeholder' => 'Votre message…', 'maxLength' => 2000],
            ],
        ],
        'newsletter' => [
            'name' => 'Inscription newsletter',
            'description' => 'Formulaire minimal pour capturer des emails.',
            'fields' => [
                ['name' => 'email', 'type' => 'email', 'label' => 'Adresse email', 'required' => true, 'placeholder' => 'vous@exemple.com'],
                ['name' => 'first_name', 'type' => 'text', 'label' => 'Prénom', 'required' => false],
            ],
        ],
        'survey' => [
            'name' => 'Sondage / Feedback',
            'description' => 'Collecter des retours utilisateurs.',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Nom', 'required' => false],
                ['name' => 'rating', 'type' => 'number', 'label' => 'Note (1-5)', 'required' => true, 'min' => 1, 'max' => 5],
                ['name' => 'feedback', 'type' => 'textarea', 'label' => 'Votre avis', 'required' => false, 'maxLength' => 1000],
            ],
        ],
        'job_application' => [
            'name' => 'Candidature',
            'description' => 'Formulaire de candidature avec upload de CV.',
            'fields' => [
                ['name' => 'full_name', 'type' => 'text', 'label' => 'Nom complet', 'required' => true],
                ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                ['name' => 'phone', 'type' => 'tel', 'label' => 'Téléphone', 'required' => false],
                ['name' => 'cover_letter', 'type' => 'textarea', 'label' => 'Lettre de motivation', 'required' => false, 'maxLength' => 3000],
                ['name' => 'cv', 'type' => 'file', 'label' => 'CV (PDF)', 'required' => true],
            ],
        ],
    ];

    /** @return list<string> noms des templates disponibles */
    public function getAvailableTemplates(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /**
     * Récupère un template par son nom.
     *
     * @return array{name: string, description: string, fields: array}|null
     */
    public function getTemplate(string $name): ?array
    {
        return self::TEMPLATES[$name] ?? null;
    }

    /**
     * Liste tous les templates avec leurs métadonnées (sans les champs complets).
     *
     * @return list<array{id: string, name: string, description: string, fields_count: int}>
     */
    public function listTemplates(): array
    {
        $result = [];
        foreach (self::TEMPLATES as $id => $template) {
            $result[] = [
                'id' => $id,
                'name' => $template['name'],
                'description' => $template['description'],
                'fields_count' => count($template['fields']),
            ];
        }
        return $result;
    }

    /**
     * Applique un template à un tableau de champs existant (merge).
     * Les champs du template écrasent ceux qui ont le même `name`.
     *
     * @param array $existingFields Champs existants
     * @param string $templateName Nom du template
     * @return array{fields: array, name: string, description: string}|null
     */
    public function applyTemplate(array $existingFields, string $templateName): ?array
    {
        $template = $this->getTemplate($templateName);
        if ($template === null) {
            return null;
        }

        $existingByName = [];
        foreach ($existingFields as $field) {
            $name = $field['name'] ?? '';
            if ($name !== '') {
                $existingByName[$name] = $field;
            }
        }

        $merged = $template['fields'];
        foreach ($merged as &$field) {
            $name = $field['name'] ?? '';
            if ($name !== '' && isset($existingByName[$name])) {
                $field = array_merge($field, $existingByName[$name]);
            }
        }
        unset($field);

        return [
            'fields' => $merged,
            'name' => $template['name'],
            'description' => $template['description'],
        ];
    }
}
