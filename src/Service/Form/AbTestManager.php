<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Form;

/**
 * Gère les tests A/B sur les formulaires.
 *
 * Chaque test A/B est stocké dans `Form::$settings['ab_test']` avec :
 * - `enabled` (bool)
 * - `variants` (array de 2 définitions alternatives de champs)
 * - `weights` (array de 2 floats, somme = 1.0)
 * - `impressions` (array de 2 ints, compteurs)
 * - `submissions` (array de 2 ints, compteurs)
 */
class AbTestManager
{
    private const DEFAULT_WEIGHTS = [0.5, 0.5];

    /**
     * Active le test A/B sur un formulaire avec 2 variantes.
     *
     * @param Form $form
     * @param array $variantA Champs de la variante A
     * @param array $variantB Champs de la variante B
     * @param array{float, float}|null $weights Poids de distribution [A, B] (défaut: 50/50)
     */
    public function enable(Form $form, array $variantA, array $variantB, ?array $weights = null): void
    {
        $weights ??= self::DEFAULT_WEIGHTS;

        $form->settings['ab_test'] = [
            'enabled' => true,
            'variants' => [$variantA, $variantB],
            'weights' => $weights,
            'impressions' => [0, 0],
            'submissions' => [0, 0],
        ];
    }

    public function disable(Form $form): void
    {
        if (isset($form->settings['ab_test'])) {
            $ab = $form->settings['ab_test'];
            $ab['enabled'] = false;
            $form->settings['ab_test'] = $ab;
        }
    }

    /**
     * Détermine quelle variante servir à un visiteur.
     *
     * @param Form $form
     * @return array{fields: array, variantIndex: int}|null Les champs de la variante choisie, ou null si pas de test
     */
    public function resolveVariant(Form $form): ?array
    {
        $ab = $form->settings['ab_test'] ?? null;
        if (!$ab || !($ab['enabled'] ?? false)) {
            return null;
        }

        $weights = $ab['weights'] ?? self::DEFAULT_WEIGHTS;
        $rand = mt_rand() / mt_getrandmax(); // 0.0 - 1.0
        $index = $rand < $weights[0] ? 0 : 1;

        return [
            'fields' => $ab['variants'][$index] ?? $form->fields,
            'variantIndex' => $index,
        ];
    }

    /**
     * Enregistre une impression (affichage) pour une variante.
     */
    public function trackImpression(Form $form, int $variantIndex): void
    {
        if (!isset($form->settings['ab_test'])) {
            return;
        }

        $ab = $form->settings['ab_test'];
        $ab['impressions'][$variantIndex] = ($ab['impressions'][$variantIndex] ?? 0) + 1;
        $form->settings['ab_test'] = $ab;
    }

    /**
     * Enregistre une soumission pour une variante.
     */
    public function trackSubmission(Form $form, int $variantIndex): void
    {
        if (!isset($form->settings['ab_test'])) {
            return;
        }

        $ab = $form->settings['ab_test'];
        $ab['submissions'][$variantIndex] = ($ab['submissions'][$variantIndex] ?? 0) + 1;
        $form->settings['ab_test'] = $ab;
    }

    /**
     * Calcule les statistiques du test A/B.
     *
     * @return array{enabled: bool, variants: array{index: int, impressions: int, submissions: int, conversionRate: float, isWinner: bool}[]}|null
     */
    public function getStats(Form $form): ?array
    {
        $ab = $form->settings['ab_test'] ?? null;
        if (!$ab) {
            return null;
        }

        $variants = [];
        $bestRate = -1.0;
        $bestIndex = -1;

        for ($i = 0; $i < 2; ++$i) {
            $impressions = (int) ($ab['impressions'][$i] ?? 0);
            $submissions = (int) ($ab['submissions'][$i] ?? 0);
            $rate = $impressions > 0 ? round($submissions / $impressions * 100, 2) : 0.0;

            if ($rate > $bestRate) {
                $bestRate = $rate;
                $bestIndex = $i;
            }

            $variants[] = [
                'index' => $i,
                'impressions' => $impressions,
                'submissions' => $submissions,
                'conversionRate' => $rate,
                'isWinner' => false,
            ];
        }

        // Marquer le gagnant s'il y a assez de données (≥ 100 impressions par variante)
        $totalImpressions = ($ab['impressions'][0] ?? 0) + ($ab['impressions'][1] ?? 0);
        if ($totalImpressions >= 200 && $bestIndex >= 0) {
            $variants[$bestIndex]['isWinner'] = true;
        }

        return [
            'enabled' => $ab['enabled'] ?? false,
            'variants' => $variants,
        ];
    }
}
