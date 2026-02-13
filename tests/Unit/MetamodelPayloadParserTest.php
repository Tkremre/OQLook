<?php

namespace Tests\Unit;

use App\OQLike\Discovery\MetamodelPayloadParser;
use PHPUnit\Framework\TestCase;

class MetamodelPayloadParserTest extends TestCase
{
    public function test_it_normalizes_connector_payload(): void
    {
        $parser = new MetamodelPayloadParser();

        $payload = [
            'classes' => [
                'Server' => [
                    'name' => 'Server',
                    'label' => 'Server',
                    'is_abstract' => false,
                    'is_persistent' => true,
                    'attributes' => [
                        [
                            'code' => 'name',
                            'mandatory' => true,
                            'type' => 'string',
                        ],
                        [
                            'code' => 'status',
                            'enum_values' => ['production', 'obsolete'],
                        ],
                    ],
                    'relations' => [
                        ['attribute' => 'org_id', 'target_class' => 'Organization'],
                    ],
                ],
            ],
        ];

        $normalized = $parser->parseConnectorPayload($payload);

        $this->assertNotEmpty($normalized['metamodel_hash']);
        $this->assertArrayHasKey('Server', $normalized['classes']);
        $this->assertTrue($normalized['classes']['Server']['is_persistent']);
        $this->assertSame('name', $normalized['classes']['Server']['attributes'][0]['code']);
    }

    public function test_it_filters_target_classes(): void
    {
        $parser = new MetamodelPayloadParser();

        $payload = [
            'classes' => [
                'Server' => ['attributes' => []],
                'Person' => ['attributes' => []],
            ],
        ];

        $normalized = $parser->parseConnectorPayload($payload, ['Person']);

        $this->assertArrayNotHasKey('Server', $normalized['classes']);
        $this->assertArrayHasKey('Person', $normalized['classes']);
    }

    public function test_it_parses_jsonl_connector_payload_with_lazy_index(): void
    {
        $parser = new MetamodelPayloadParser();
        $path = tempnam(sys_get_temp_dir(), 'oqlike_test_');
        $this->assertNotFalse($path);

        $line1 = json_encode([
            'name' => 'Server',
            'label' => 'Server',
            'is_abstract' => false,
            'is_persistent' => true,
            'attributes' => [
                ['code' => 'name', 'mandatory' => true, 'type' => 'string'],
            ],
        ]).PHP_EOL;
        $line2 = json_encode([
            'name' => 'Person',
            'label' => 'Person',
            'is_abstract' => false,
            'is_persistent' => true,
            'attributes' => [
                ['code' => 'status', 'type' => 'enum', 'enum_values' => ['active', 'obsolete']],
            ],
        ]).PHP_EOL;

        file_put_contents($path, $line1.$line2);

        $normalized = $parser->parseConnectorPayload([
            'metamodel_hash' => 'abc123',
            'classes_jsonl_path' => $path,
            'errors' => [],
        ]);

        $this->assertSame('abc123', $normalized['metamodel_hash']);
        $this->assertArrayHasKey('Server', $normalized['classes']);
        $this->assertArrayHasKey('Person', $normalized['classes']);
        $this->assertArrayHasKey('classes_index', $normalized);
        $this->assertArrayHasKey('Server', $normalized['classes_index']);
        $this->assertSame([], $normalized['classes']['Server']['attributes']);
        $this->assertFalse($normalized['cache_persistable'] ?? true);

        @unlink($path);
    }
}
