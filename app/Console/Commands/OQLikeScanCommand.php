<?php

namespace App\Console\Commands;

use App\Jobs\RunScanJob;
use App\Models\Connection;
use App\OQLike\Scanning\ScanRunner;
use Illuminate\Console\Command;

class OQLikeScanCommand extends Command
{
    protected $signature = 'oqlike:scan {connectionId} {--mode=delta} {--classes=} {--thresholdDays=365} {--forceSelectedClasses}';

    protected $description = 'Exécute un scan qualité OQLook sur une connexion iTop.';

    public function handle(ScanRunner $scanRunner): int
    {
        $connection = Connection::find($this->argument('connectionId'));

        if (! $connection instanceof Connection) {
            $this->error('Connexion introuvable.');

            return self::FAILURE;
        }

        $mode = in_array($this->option('mode'), ['delta', 'full'], true)
            ? (string) $this->option('mode')
            : 'delta';

        $classes = collect(explode(',', (string) $this->option('classes')))
            ->map(fn (string $className) => trim($className))
            ->filter()
            ->values()
            ->all();

        $thresholdDays = max(1, (int) $this->option('thresholdDays'));
        $forceSelectedClasses = (bool) $this->option('forceSelectedClasses');

        if ($this->shouldQueue()) {
            RunScanJob::dispatch($connection->id, $mode, $classes, $thresholdDays, $forceSelectedClasses);
            $this->info(sprintf('Scan mis en file sur le worker %s.', (string) config('queue.default', 'queue')));

            return self::SUCCESS;
        }

        $this->warn('La file est en mode sync, exécution du scan en mode synchrone.');

        $scan = $scanRunner->run($connection, $mode, $classes, $thresholdDays, $forceSelectedClasses);
        $this->info(sprintf('Scan #%d terminé. Score global: %s', $scan->id, $scan->scores_json['global'] ?? 'N/D'));

        return self::SUCCESS;
    }

    private function shouldQueue(): bool
    {
        if (! (bool) config('oqlike.use_queue', false)) {
            return false;
        }

        $queueDriver = (string) config('queue.default', 'sync');

        if ($queueDriver === 'sync') {
            return false;
        }

        if ($queueDriver === 'redis') {
            return extension_loaded('redis') || class_exists(\Predis\Client::class);
        }

        return true;
    }
}
