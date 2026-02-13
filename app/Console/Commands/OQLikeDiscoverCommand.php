<?php

namespace App\Console\Commands;

use App\Models\Connection;
use App\OQLike\Discovery\MetamodelDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class OQLikeDiscoverCommand extends Command
{
    protected $signature = 'oqlike:discover {connectionId} {--classes=}';

    protected $description = 'Découvre et met en cache le catalogue de classes iTop (métamodèle) pour une connexion OQLook.';

    public function handle(MetamodelDiscoveryService $discoveryService): int
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $connection = Connection::find($this->argument('connectionId'));

        if (! $connection instanceof Connection) {
            $this->error('Connexion introuvable.');

            return self::FAILURE;
        }

        $targetClasses = collect(explode(',', (string) $this->option('classes')))
            ->map(fn (string $className) => trim($className))
            ->filter()
            ->values()
            ->all();

        $this->info(sprintf('Découverte du métamodèle pour la connexion #%d (%s)...', $connection->id, $connection->name));
        $metamodel = $discoveryService->discover($connection, $targetClasses);
        $temporaryMetamodelPath = Arr::get($metamodel, 'classes_jsonl_path');

        $classes = array_keys((array) Arr::get($metamodel, 'classes', []));
        sort($classes);

        $this->info(sprintf(
            'Terminé. source=%s source_detail=%s classes=%d',
            (string) Arr::get($metamodel, 'source', 'N/D'),
            (string) Arr::get($metamodel, 'source_detail', 'N/D'),
            count($classes)
        ));

        $discoveryError = (string) Arr::get($metamodel, 'discovery_error', '');
        if ($discoveryError !== '') {
            $this->warn('Avertissement de découverte: '.$discoveryError);
        }

        if ($classes !== []) {
            $preview = array_slice($classes, 0, 30);
            $this->line('Aperçu des classes: '.implode(', ', $preview));
        }

        if (is_string($temporaryMetamodelPath) && $temporaryMetamodelPath !== '' && is_file($temporaryMetamodelPath)) {
            @unlink($temporaryMetamodelPath);
        }

        return self::SUCCESS;
    }
}
