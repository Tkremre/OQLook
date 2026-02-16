<?php

namespace App\Http\Controllers\OQLike;

use App\Http\Controllers\Controller;
use App\Models\ScanCheckPreference;
use App\OQLike\Checks\CheckCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        $allowedSeverities = ['crit', 'warn', 'info'];

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
                'content_html' => $this->renderReadmeHtml($content),
                'updated_at' => date('Y-m-d H:i:s', (int) @filemtime($path)),
                'size_bytes' => (int) @filesize($path),
            ];
        }

        $preferencesByCode = collect();

        if (Schema::hasTable('scan_check_preferences')) {
            $preferencesByCode = ScanCheckPreference::query()
                ->get(['issue_code', 'enabled', 'severity_override'])
                ->keyBy('issue_code');
        }

        $checkRules = [];

        foreach (CheckCatalog::definitions() as $definition) {
            $issueCode = (string) ($definition['issue_code'] ?? '');

            if ($issueCode === '') {
                continue;
            }

            $preference = $preferencesByCode->get($issueCode);
            $severityOverride = is_string($preference?->severity_override)
                ? strtolower(trim($preference->severity_override))
                : null;

            if (! in_array($severityOverride, $allowedSeverities, true)) {
                $severityOverride = null;
            }

            $checkRules[] = [
                'issue_code' => $issueCode,
                'check_class' => (string) ($definition['check_class'] ?? ''),
                'domain' => (string) ($definition['domain'] ?? ''),
                'default_severity' => (string) ($definition['default_severity'] ?? 'info'),
                'enabled' => $preference?->enabled ?? true,
                'severity_override' => $severityOverride,
                'title_fr' => (string) ($definition['title_fr'] ?? $issueCode),
                'title_en' => (string) ($definition['title_en'] ?? $issueCode),
                'description_fr' => (string) ($definition['description_fr'] ?? ''),
                'description_en' => (string) ($definition['description_en'] ?? ''),
            ];
        }

        return Inertia::render('Settings/Index', [
            'readmes' => $readmes,
            'checkRules' => $checkRules,
        ]);
    }

    public function updateCheckPreferences(Request $request): RedirectResponse
    {
        $allowedSeverities = ['crit', 'warn', 'info'];

        $validated = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.issue_code' => ['required', 'string', 'max:120'],
            'rules.*.enabled' => ['required', 'boolean'],
            'rules.*.severity_override' => ['nullable', 'string', Rule::in($allowedSeverities)],
        ]);

        if (! Schema::hasTable('scan_check_preferences')) {
            return back()->with('status', 'Migration manquante: table scan_check_preferences non disponible.');
        }

        $catalogByCode = collect(CheckCatalog::definitions())->keyBy('issue_code');
        $customizedCount = 0;

        foreach ((array) ($validated['rules'] ?? []) as $rule) {
            $issueCode = trim((string) ($rule['issue_code'] ?? ''));

            if ($issueCode === '' || ! $catalogByCode->has($issueCode)) {
                continue;
            }

            $definition = (array) $catalogByCode->get($issueCode);
            $enabled = (bool) ($rule['enabled'] ?? true);
            $severityOverride = isset($rule['severity_override'])
                ? strtolower(trim((string) $rule['severity_override']))
                : null;

            if ($severityOverride === '' || ! in_array($severityOverride, $allowedSeverities, true)) {
                $severityOverride = null;
            }

            $defaultSeverity = strtolower((string) ($definition['default_severity'] ?? 'info'));
            if ($severityOverride === $defaultSeverity) {
                $severityOverride = null;
            }

            if ($enabled && $severityOverride === null) {
                ScanCheckPreference::query()
                    ->where('issue_code', $issueCode)
                    ->delete();
                continue;
            }

            ScanCheckPreference::query()->updateOrCreate(
                ['issue_code' => $issueCode],
                [
                    'enabled' => $enabled,
                    'severity_override' => $severityOverride,
                ]
            );

            $customizedCount++;
        }

        return back()->with('status', sprintf(
            'Règles de conformité mises à jour (%d règle(s) personnalisée(s)).',
            $customizedCount
        ));
    }

    private function renderReadmeHtml(string $markdown): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return $this->injectHeadingAnchors($html);
    }

    private function injectHeadingAnchors(string $html): string
    {
        $trimmed = trim($html);

        if ($trimmed === '' || ! class_exists(\DOMDocument::class)) {
            return $html;
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<div id="oq-readme-root">'.$trimmed.'</div>';
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        if (! $loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);

            return $html;
        }

        $usedIds = [];
        $xpath = new \DOMXPath($dom);

        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') as $headingNode) {
            if (! $headingNode instanceof \DOMElement) {
                continue;
            }

            $existingId = trim((string) $headingNode->getAttribute('id'));
            if ($existingId !== '') {
                $usedIds[$existingId] = true;
                continue;
            }

            $headingText = trim((string) $headingNode->textContent);
            $baseSlug = Str::slug($headingText, '-');
            if ($baseSlug === '') {
                $baseSlug = 'section';
            }

            $slug = $baseSlug;
            $suffix = 2;
            while (isset($usedIds[$slug])) {
                $slug = $baseSlug.'-'.$suffix;
                $suffix++;
            }

            $headingNode->setAttribute('id', $slug);
            $usedIds[$slug] = true;
        }

        foreach ($xpath->query('//a[@href]') as $anchorNode) {
            if (! $anchorNode instanceof \DOMElement) {
                continue;
            }

            $href = trim((string) $anchorNode->getAttribute('href'));
            if ($href === '' || preg_match('/^(https?:|mailto:|tel:)/i', $href) === 1) {
                continue;
            }

            $fragment = parse_url($href, PHP_URL_FRAGMENT);
            if (! is_string($fragment) || $fragment === '') {
                continue;
            }

            $decodedFragment = trim(rawurldecode($fragment));
            if ($decodedFragment === '') {
                continue;
            }

            if (isset($usedIds[$decodedFragment])) {
                $anchorNode->setAttribute('href', '#'.$decodedFragment);
                continue;
            }

            $slugCandidate = Str::slug($decodedFragment, '-');
            if ($slugCandidate !== '' && isset($usedIds[$slugCandidate])) {
                $anchorNode->setAttribute('href', '#'.$slugCandidate);
            }
        }

        $root = $dom->getElementById('oq-readme-root');
        if (! $root instanceof \DOMElement) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);

            return $html;
        }

        $result = '';
        foreach ($root->childNodes as $childNode) {
            $result .= (string) $dom->saveHTML($childNode);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        return $result !== '' ? $result : $html;
    }
}
