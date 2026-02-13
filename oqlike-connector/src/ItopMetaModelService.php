<?php

namespace OQLikeConnector;

use RuntimeException;

class ItopMetaModelService
{
    private bool $metamodelLoaded = false;
    private ?array $classesCache = null;
    private ?string $globalHash = null;

    public function __construct(private readonly array $config)
    {
        $this->bootstrap();
    }

    public function ping(): array
    {
        return [
            'ok' => true,
            'timestamp' => gmdate('c'),
            'itop_version' => defined('ITOP_VERSION') ? ITOP_VERSION : null,
            'metamodel_available' => $this->metamodelLoaded,
            'metamodel_hash' => $this->metamodelLoaded ? $this->getGlobalMetamodelHash() : null,
        ];
    }

    public function classes(string $filter = 'persistent'): array
    {
        if (! $this->metamodelLoaded) {
            return [];
        }

        $classes = $this->metaModelGetClasses();
        $result = [];

        foreach ($classes as $className) {
            if (! is_string($className) || $className === '') {
                continue;
            }

            if ($filter === 'persistent' && ! $this->isPersistent($className)) {
                continue;
            }

            if ($filter === 'non_abstract' && $this->isAbstract($className)) {
                continue;
            }

            $result[] = $className;
        }

        sort($result);

        return $result;
    }

    public function classPayload(string $className): array
    {
        if (! $this->metamodelLoaded || ! $this->isValidClass($className)) {
            throw new RuntimeException('Class not available in iTop metamodel.');
        }

        $attributes = [];
        $relations = [];

        foreach ($this->listAttributes($className) as $code => $definition) {
            $isExternalKey = $this->attributeIsExternalKey($definition);

            $attributes[] = [
                'code' => (string) $code,
                'label' => $this->attributeLabel($definition, (string) $code),
                'type' => $this->attributeType($definition),
                'mandatory' => $this->attributeMandatory($definition),
                'is_external_key' => $isExternalKey,
                'target_class' => $this->attributeTargetClass($definition),
                'enum_values' => $this->attributeEnumValues($definition),
                'is_computed' => $this->attributeIsComputed($definition),
                'is_readonly' => $this->attributeIsReadonly($definition),
            ];

            if ($isExternalKey) {
                $relations[] = [
                    'attribute' => (string) $code,
                    'type' => 'external_key',
                    'target_class' => $this->attributeTargetClass($definition),
                ];
            }
        }

        return [
            'metamodel_hash' => $this->getGlobalMetamodelHash(),
            'name' => $className,
            'label' => $this->classLabel($className),
            'is_abstract' => $this->isAbstract($className),
            'is_persistent' => $this->isPersistent($className),
            'attributes' => $attributes,
            'relations' => $relations,
        ];
    }

    public function classRelations(string $className): array
    {
        if (! $this->metamodelLoaded || ! $this->isValidClass($className)) {
            throw new RuntimeException('Class not available in iTop metamodel.');
        }

        $relations = [];

        foreach ($this->listAttributes($className) as $code => $definition) {
            if ($this->attributeIsExternalKey($definition)) {
                $relations[] = [
                    'attribute' => (string) $code,
                    'type' => 'external_key',
                    'target_class' => $this->attributeTargetClass($definition),
                ];
            }
        }

        return [
            'metamodel_hash' => $this->getGlobalMetamodelHash(),
            'class' => $className,
            'relations' => $relations,
        ];
    }

    public function getGlobalMetamodelHash(): string
    {
        if ($this->globalHash !== null) {
            return $this->globalHash;
        }

        if (! $this->metamodelLoaded) {
            return hash('sha256', 'metamodel_unavailable');
        }

        $signature = [];

        foreach ($this->metaModelGetClasses() as $className) {
            if (! is_string($className) || $className === '') {
                continue;
            }

            // Keep this intentionally lightweight for large metamodels.
            // Full per-attribute hashing is done in OQLook after class payload collection.
            $signature[$className] = [
                'is_abstract' => $this->isAbstract($className),
                'is_persistent' => $this->isPersistent($className),
            ];
        }

        ksort($signature);
        $this->globalHash = hash('sha256', json_encode($signature));

        return $this->globalHash;
    }

    private function bootstrap(): void
    {
        $bootstrapPath = $this->config['itop_bootstrap'] ?? null;

        if (! is_string($bootstrapPath) || $bootstrapPath === '' || ! is_file($bootstrapPath)) {
            return;
        }

        $bootstrapPath = str_replace('\\', '/', $bootstrapPath);
        $appRoot = rtrim(dirname($bootstrapPath, 2), '/');

        if (! defined('APPROOT') && $appRoot !== '') {
            define('APPROOT', $appRoot.'/');
        }

        $cwd = getcwd();

        if ($appRoot !== '' && is_dir($appRoot)) {
            @chdir($appRoot);
        }

        // iTop bootstrap compatibility across versions/distributions:
        // load approot and core exception classes before startup when available.
        $approotInc = $appRoot.'/approot.inc.php';
        if (is_file($approotInc)) {
            require_once $approotInc;
        }

        $coreExceptionFile = $appRoot.'/core/exception.class.inc.php';
        if (! class_exists('CoreException', false) && is_file($coreExceptionFile)) {
            require_once $coreExceptionFile;
        }

        require_once $bootstrapPath;

        // Some setups expose MetaModel only after application include.
        if (! class_exists('MetaModel') && is_file($appRoot.'/application/application.inc.php')) {
            require_once $appRoot.'/application/application.inc.php';
        }

        $this->metamodelLoaded = class_exists('MetaModel');

        if (is_string($cwd) && $cwd !== '') {
            @chdir($cwd);
        }
    }

    private function metaModelGetClasses(): array
    {
        if (is_array($this->classesCache)) {
            return $this->classesCache;
        }

        if (method_exists('MetaModel', 'GetClasses')) {
            /** @phpstan-ignore-next-line */
            $classes = \MetaModel::GetClasses();

            if (is_array($classes)) {
                $classes = array_values(array_filter($classes, static fn ($value): bool => is_string($value) && $value !== ''));
                sort($classes);
                $this->classesCache = $classes;

                return $this->classesCache;
            }
        }

        $this->classesCache = [];

        return $this->classesCache;
    }

    private function isValidClass(string $className): bool
    {
        if (method_exists('MetaModel', 'IsValidClass')) {
            /** @phpstan-ignore-next-line */
            return \MetaModel::IsValidClass($className);
        }

        return in_array($className, $this->metaModelGetClasses(), true);
    }

    private function isAbstract(string $className): bool
    {
        if (method_exists('MetaModel', 'IsAbstract')) {
            /** @phpstan-ignore-next-line */
            return (bool) \MetaModel::IsAbstract($className);
        }

        return false;
    }

    private function isPersistent(string $className): bool
    {
        if (method_exists('MetaModel', 'IsPersistentClass')) {
            /** @phpstan-ignore-next-line */
            return (bool) \MetaModel::IsPersistentClass($className);
        }

        return ! $this->isAbstract($className);
    }

    private function classLabel(string $className): string
    {
        if (method_exists('MetaModel', 'GetName')) {
            /** @phpstan-ignore-next-line */
            return (string) \MetaModel::GetName($className);
        }

        return $className;
    }

    private function listAttributes(string $className): array
    {
        if (method_exists('MetaModel', 'ListAttributeDefs')) {
            /** @phpstan-ignore-next-line */
            $attributes = \MetaModel::ListAttributeDefs($className);

            return is_array($attributes) ? $attributes : [];
        }

        return [];
    }

    private function attributeLabel(object $definition, string $fallback): string
    {
        if (method_exists($definition, 'GetLabel')) {
            return (string) $definition->GetLabel();
        }

        return $fallback;
    }

    private function attributeType(object $definition): string
    {
        if (method_exists($definition, 'GetEditClass')) {
            return strtolower((string) $definition->GetEditClass());
        }

        return strtolower((new \ReflectionClass($definition))->getShortName());
    }

    private function attributeMandatory(object $definition): bool
    {
        if (method_exists($definition, 'IsNullAllowed')) {
            return ! (bool) $definition->IsNullAllowed();
        }

        return false;
    }

    private function attributeIsExternalKey(object $definition): bool
    {
        if (method_exists($definition, 'IsExternalKey')) {
            return (bool) $definition->IsExternalKey();
        }

        return str_contains(strtolower((new \ReflectionClass($definition))->getShortName()), 'externalkey');
    }

    private function attributeTargetClass(object $definition): ?string
    {
        if (method_exists($definition, 'GetTargetClass')) {
            return (string) $definition->GetTargetClass();
        }

        return null;
    }

    private function attributeEnumValues(object $definition): array
    {
        if (method_exists($definition, 'GetAllowedValues')) {
            $values = $definition->GetAllowedValues();

            return is_array($values) ? array_values(array_map('strval', array_keys($values))) : [];
        }

        return [];
    }

    private function attributeIsComputed(object $definition): bool
    {
        if (method_exists($definition, 'IsComputed')) {
            return (bool) $definition->IsComputed();
        }

        return str_contains(strtolower((new \ReflectionClass($definition))->getShortName()), 'computed');
    }

    private function attributeIsReadonly(object $definition): bool
    {
        if (method_exists($definition, 'IsWritable')) {
            return ! (bool) $definition->IsWritable();
        }

        return false;
    }
}
