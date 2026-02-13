<?php

namespace Tests\Unit;

use App\OQLike\Scanning\ScoringService;
use PHPUnit\Framework\TestCase;

class ScoringServiceTest extends TestCase
{
    public function test_it_returns_100_when_no_issues_exist(): void
    {
        $scores = (new ScoringService())->compute([]);

        $this->assertSame(100.0, $scores['global']);
        $this->assertSame(100.0, $scores['domains']['completeness']['score']);
    }

    public function test_it_penalizes_high_impact_issues(): void
    {
        $service = new ScoringService();

        $scores = $service->compute([
            [
                'domain' => 'completeness',
                'impact' => 5,
                'affected_count' => 120,
            ],
            [
                'domain' => 'relations',
                'impact' => 4,
                'affected_count' => 80,
            ],
        ]);

        $this->assertLessThan(100, $scores['global']);
        $this->assertLessThan(100, $scores['domains']['completeness']['score']);
        $this->assertLessThan(100, $scores['domains']['relations']['score']);
    }
}
