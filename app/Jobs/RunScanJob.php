<?php

namespace App\Jobs;

use App\Models\Connection;
use App\OQLike\Scanning\ScanRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $connectionId,
        private readonly string $mode,
        private readonly array $classes,
        private readonly int $thresholdDays,
        private readonly bool $forceSelectedClasses = false,
    ) {
    }

    public function handle(ScanRunner $scanRunner): void
    {
        $connection = Connection::findOrFail($this->connectionId);
        $scanRunner->run($connection, $this->mode, $this->classes, $this->thresholdDays, $this->forceSelectedClasses);
    }
}
