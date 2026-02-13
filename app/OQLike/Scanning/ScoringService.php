<?php

namespace App\OQLike\Scanning;

class ScoringService
{
    private const DOMAIN_WEIGHTS = [
        'completeness' => 1.3,
        'relations' => 1.2,
        'consistency' => 1.0,
        'obsolescence' => 0.9,
        'hygiene' => 0.8,
    ];

    public function compute(array $issues): array
    {
        $byDomain = [];

        foreach ($issues as $issue) {
            $domain = (string) ($issue['domain'] ?? 'hygiene');
            $impact = max(1, min(5, (int) ($issue['impact'] ?? 1)));
            $affected = max(0, (int) ($issue['affected_count'] ?? 0));

            $byDomain[$domain]['penalty'] = ($byDomain[$domain]['penalty'] ?? 0.0)
                + ($impact * log(1 + $affected));

            $byDomain[$domain]['issue_count'] = ($byDomain[$domain]['issue_count'] ?? 0) + 1;
        }

        return $this->computeFromDomainStats($byDomain);
    }

    public function computeFromDomainStats(array $byDomain): array
    {
        $domains = [];

        foreach (self::DOMAIN_WEIGHTS as $domain => $weight) {
            $penalty = (float) ($byDomain[$domain]['penalty'] ?? 0.0);
            $score = $this->scoreFromPenalty($penalty);

            $domains[$domain] = [
                'score' => $score,
                'penalty' => round($penalty, 4),
                'issue_count' => (int) ($byDomain[$domain]['issue_count'] ?? 0),
                'weight' => $weight,
            ];
        }

        $weightedScore = 0.0;
        $weightSum = 0.0;

        foreach ($domains as $domain => $payload) {
            $weight = (float) self::DOMAIN_WEIGHTS[$domain];
            $weightedScore += ((float) $payload['score']) * $weight;
            $weightSum += $weight;
        }

        return [
            'global' => round($weightSum > 0 ? $weightedScore / $weightSum : 100, 2),
            'domains' => $domains,
        ];
    }

    private function scoreFromPenalty(float $penalty): float
    {
        $normalizedPenalty = min(100.0, $penalty * 8.0);

        return round(max(0.0, 100.0 - $normalizedPenalty), 2);
    }
}
