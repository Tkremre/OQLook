<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;

interface CheckInterface
{
    public function issueCode(): string;

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool;

    public function run(string $className, array $classMeta, ScanContext $context): ?array;
}
