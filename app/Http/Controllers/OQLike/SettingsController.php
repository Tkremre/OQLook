<?php

namespace App\Http\Controllers\OQLike;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        $readmeFiles = [
            [
                'id' => 'main',
                'title' => 'README principal',
                'path' => base_path('README.md'),
            ],
            [
                'id' => 'connector',
                'title' => 'README connecteur',
                'path' => base_path('oqlike-connector/README.md'),
            ],
        ];

        $readmes = [];

        foreach ($readmeFiles as $entry) {
            $path = (string) ($entry['path'] ?? '');

            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                continue;
            }

            $content = (string) @file_get_contents($path);
            $maxChars = 220_000;

            if (mb_strlen($content) > $maxChars) {
                $content = mb_substr($content, 0, $maxChars)
                    ."\n\n[... contenu tronqué pour l'affichage ...]";
            }

            $readmes[] = [
                'id' => (string) ($entry['id'] ?? basename($path)),
                'title' => (string) ($entry['title'] ?? basename($path)),
                'relative_path' => str_replace('\\', '/', ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR)),
                'content' => $content,
                'updated_at' => date('Y-m-d H:i:s', (int) @filemtime($path)),
                'size_bytes' => (int) @filesize($path),
            ];
        }

        return Inertia::render('Settings/Index', [
            'readmes' => $readmes,
        ]);
    }
}

