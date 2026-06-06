<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Repare les champs texte corrompus par un mauvais encodage Latin-1 -> UTF-8.
 *
 * Contexte : des imports CSV en Latin-1 via l'API JSON ont insere des
 * caracteres accentues francais sous forme de bytes Latin-1 (0xE0-0xFC),
 * que MySQL a remplaces par U+FFFD (caractere de remplacement Unicode).
 * Resultat : "conquete" devient "conqu�te" (2129 entrees affectees).
 *
 * Approche : remplace U+FFFD par la lettre accentuee la plus probable
 * selon le contexte gauche/droite, en utilisant des regles de la langue
 * francaise (patterns de co-occurrence).
 */
#[AsCommand('app:fix-corrupted-utf8')]
class FixCorruptedUtf8Command extends Command
{
    public function __construct(
        private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Repare les caracteres corrompus (U+FFFD) dans les champs texte')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Affiche les corrections sans les appliquer')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limite le nombre de reparations', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = $input->getOption('dry-run');
        $limit  = (int) $input->getOption('limit');

        // Probleme : U+FFFD en UTF-8 = 0xEF 0xBF 0xBD
        $corruptedRows = $this->connection->fetchAllAssociative(
            "SELECT id, text_value FROM content_field_value WHERE text_value LIKE CONCAT('%', CHAR(0xEFBFBD USING utf8mb4), '%') ORDER BY id" .
            ($limit > 0 ? " LIMIT $limit" : "")
        );

        $totalRows   = count($corruptedRows);
        $fixedRows   = 0;
        $replacements = 0;

        foreach ($corruptedRows as $row) {
            $original = $row['text_value'];
            $fixed    = $this->fixFrenchText($original);

            if ($fixed !== $original) {
                $replacements += substr_count($original, "\u{FFFD}");

                if ($dryRun) {
                    // Montrer les 3 premieres corrections
                    if ($fixedRows < 3) {
                        $output->writeln(sprintf(
                            '  <fg=yellow>[%d]</> <fg=red>%s</> → <fg=green>%s</>',
                            $row['id'],
                            mb_substr($original, 0, 60),
                            mb_substr($fixed, 0, 60)
                        ));
                    }
                } else {
                    $this->connection->update('content_field_value',
                        ['text_value' => $fixed],
                        ['id' => $row['id']]
                    );
                }
                $fixedRows++;
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            'Total: <fg=yellow>%d</> entrees corrompues | Reparees: <fg=green>%d</> | Remplacements: <fg=cyan>%d</>',
            $totalRows, $fixedRows, $replacements
        ));

        if ($dryRun) {
            $output->writeln('<fg=yellow>Mode dry-run : aucune modification appliquee.</>');
            $output->writeln('Relancez sans --dry-run pour appliquer les corrections.');
        } else {
            $output->writeln('<fg=green>Corrections appliquees avec succes.</>');
        }

        return Command::SUCCESS;
    }

    /**
     * Repare le texte francais en remplacant U+FFFD par l'accent le plus probable.
     */
    private function fixFrenchText(string $text): string
    {
        if (!str_contains($text, "\u{FFFD}")) {
            return $text;
        }

        // Patterns de substitution bases sur la co-occurrence gauche/droite en francais.
        // Format : 'sequence_avant' => ['sequence_apres', 'caractere_accentue']
        // Le pattern U+FFFD est toujours represente par le placeholder dans la cle.
        $patterns = [
            // --- e aigu (le plus frequent en francais) ---
            ["conv", "cu"],  ["congr", "s"], ["d", "c"], ["d", "f"], ["d", "j"],
            ["d", "m"], ["d", "p"], ["d", "s"], ["d", "t"], ["d", "v"],
            ["l", "g"], ["m", "d"], ["pr", "f"], ["r", "gl"], ["r", "s"],
            ["s", "c"], ["t", "l"], ["contr", "l"], ["r", "v"],
            // indifférent, différente, etc.
            ["iff", "r"], ["iff", "r"], ["iff", "rent"], ["iff", "remment"],
            // "march" -> "marché"
            ["march", " "], ["march", ","], ["march", "."],
            // "déc" au debut
            ["d", "c"],  ["D", "c"],
            // "pré"
            ["pr", "v"], ["pr", "s"],
            // "ré"
            ["r", "p"], ["r", "f"], ["r", "u"], ["r", "a"],
            // terminaisons en "é" (participes passes)
            ["sign", " "], ["sign", ","], ["sign", "."], ["sign", "\n"],
            ["pass", " "], ["pass", ","], ["pass", "."],
            ["donn", " "], ["donn", ","], ["donn", "."],
            ["appel", " "], ["appel", ","], ["appel", "."],
            ["envoy", " "], ["envoy", ","], ["envoy", "."],
            ["chang", " "], ["chang", ","], ["chang", "."],

            // --- e accent grave ---
            ["tr", "s"], ["apr", "s"], ["probl", "me"],
            // "très" = tres commun
            ["tr", "s"],
            // "après"
            ["apr", "s"],
            // "procès"
            ["proc", "s"],

            // --- e accent circonflexe ---
            ["enqu", "te"], ["conqu", "te"], ["t", "te"], ["f", "te"],
            ["arr", "t"], ["for", "t"], ["b", "te"], ["cr", "te"],
            ["m", "me"], ["gr", "ce"], ["p", "che"],

            // --- a accent grave ---
            ["d", "j"], ["l", " "], ["l", ","], ["l", "."],

            // --- u accent circonflexe ---
            ["s", "r"],

            // --- i accent circonflexe ---
            ["conna", "tre"], ["na", "tre"], ["pla", "t"],

            // --- c cedille ---
            ["re", "u"], ["d", "u"], ["fa", "ade"],
        ];

        $result = $text;

        // Appliquer les patterns contextuels
        foreach ($patterns as [$before, $after]) {
            $search = $before . "\u{FFFD}" . $after;
            // Deduire la lettre accentuee la plus probable selon le contexte
            $accented = $this->inferAccent($before, $after);
            $replace  = $before . $accented . $after;
            if (str_contains($result, $search)) {
                $result = str_replace($search, $replace, $result);
            }
        }

        // Deuxieme passe : pour les U+FFFD restants, appliquer des regles
        // par frequence de lettres accentuees en francais :
        // e (47%) > a (15%) > e (13%) > u/c (5%) > autres
        // On privilegie "é" en contexte non-devinable (milieu/fin de mot)
        $remaining = substr_count($result, "\u{FFFD}");
        if ($remaining > 0) {
            $result = $this->heuristicReplaceRemaining($result);
        }

        return $result;
    }

    /**
     * Deduit la lettre accentuee la plus probable selon le contexte.
     */
    private function inferAccent(string $before, string $after): string
    {
        $context = $before . '_' . $after;

        // Regles par contexte droit (lettre qui suit le caractere accentue)
        $afterFirst = mb_substr($after, 0, 1);

        // "�" suivi de consonne → probablement "é"
        // "�" suivi de "me" → "ê" (même)
        // "�" suivi de "tre" → généralement "î" ou "ê" (connaître, fenêtre)
        // "�" suivi de espace → probablement "é" (participe passe)

        if ($afterFirst === '' || $afterFirst === ' ') {
            return 'é'; // fin de mot = participe passe probable
        }

        // Patterns specifiques
        $fullAfter2 = mb_substr($after, 0, 2);
        $fullAfter3 = mb_substr($after, 0, 3);

        // ...�te → enqu�te, conqu�te, f�te → "ê"
        if ($fullAfter3 === 'te ' || $fullAfter3 === 'te,' || $fullAfter3 === 'te.' || $after === 'te') {
            if (in_array($before, ['enqu', 'conqu', 'f', 't', 'b', 'cr', 'arr', 'honn'])) {
                return 'ê';
            }
            if (in_array($before, ['enqu', 'conqu'])) {
                return 'ê';
            }
        }

        // ...�me → m�me, probl�me → "ê"
        if ($fullAfter2 === 'me') {
            return 'ê';
        }

        // ...�s → apr�s, tr�s → "è"
        if ($after === 's' || str_starts_with($after, 's ')) {
            return 'è';
        }

        // ...�re → "è" (père, mère) ou "ê" (fenêtre)
        if ($fullAfter2 === 're') {
            return 'è';
        }

        // ...�ce → gr�ce → "â"
        if ($fullAfter2 === 'ce') {
            return 'â';
        }

        // ...�a → d�j� → "é"
        if ($afterFirst === 'a') {
            return 'é';
        }

        // ...�u → re�u → "ç"
        if ($afterFirst === 'u' && in_array(mb_substr($before, -1), ['e', 'a'])) {
            return 'ç';
        }

        // ...�g → "é" (l�gal, r�gler)
        if ($afterFirst === 'g') {
            return 'é';
        }

        // ...�c → "é" (d�cision, pr�cis)
        if ($afterFirst === 'c') {
            return 'é';
        }

        // ...�p → "é" (r�paration, d�pannage)
        if ($afterFirst === 'p') {
            return 'é';
        }

        // ...�t → "é" ou "ê"
        if ($afterFirst === 't') {
            return 'é'; // plus probable statistiquement
        }

        // ...�m → "é" (d�m�nager)
        if ($afterFirst === 'm') {
            return 'é';
        }

        // Default : "é" (le plus frequent)
        return 'é';
    }

    /**
     * Remplace les U+FFFD restants par le caractere le plus probable
     * selon la frequence des accents en francais.
     */
    private function heuristicReplaceRemaining(string $text): string
    {
        // Pour chaque U+FFFD restant, on choisit "é" (47% de frequence en francais)
        // sauf si le contexte indique clairement autre chose.
        $result = '';
        $len = mb_strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1);
            if ($char === "\u{FFFD}") {
                // Regarder le caractere suivant pour decider
                $next   = $i + 1 < $len ? mb_substr($text, $i + 1, 1) : '';
                $prev   = $i > 0 ? mb_substr($text, $i - 1, 1) : '';
                $next2  = $i + 2 < $len ? mb_substr($text, $i + 1, 2) : '';

                // "� " en fin de mot → "é" (participe passe)
                if ($next === ' ' || $next === ',' || $next === '.' || $next === "\n" || $next === '') {
                    $result .= 'é';
                } elseif ($next2 === 'me') {
                    $result .= 'ê'; // "m�me"
                } elseif ($next === 's' && ($i + 2 >= $len || mb_substr($text, $i + 2, 1) === ' ')) {
                    $result .= 'è'; // "tr�s", "apr�s"
                } elseif ($next === 'g') {
                    $result .= 'é'; // "l�gal", "r�gler"
                } elseif ($next === 'c') {
                    $result .= 'é'; // "d�cision", "d�c"
                } elseif ($prev === 'd' && $next === 'j') {
                    $result .= 'é'; // "d�j�"
                } elseif ($next2 === 'te') {
                    $result .= 'ê'; // "enqu�te"
                } elseif ($next2 === 're') {
                    $result .= 'è'; // "p�re"
                } elseif ($next2 === 'ce') {
                    $result .= 'â'; // "gr�ce"
                } elseif ($prev === 'r' && $next === 'u') {
                    $result .= 'e'; // essayer ?? → reçu
                } elseif ($next === 'u') {
                    $result .= 'ç'; // "re�u"
                } else {
                    $result .= 'é'; // fallback le plus frequent
                }
            } else {
                $result .= $char;
            }
        }
        return $result;
    }
}
