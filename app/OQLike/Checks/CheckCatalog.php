<?php

namespace App\OQLike\Checks;

class CheckCatalog
{
    /**
     * @return array<int, array<string, string>>
     */
    public static function definitions(): array
    {
        return [
            [
                'check_class' => CompletenessMandatoryEmptyCheck::class,
                'issue_code' => 'COMPLETENESS_MANDATORY_EMPTY',
                'domain' => 'completeness',
                'default_severity' => 'crit',
                'title_fr' => 'Champs obligatoires vides',
                'title_en' => 'Mandatory fields empty',
                'description_fr' => 'Détecte les objets avec attributs obligatoires non renseignés.',
                'description_en' => 'Detects objects with missing mandatory attributes.',
            ],
            [
                'check_class' => RelationsMissingExternalKeyCheck::class,
                'issue_code' => 'RELATIONS_MISSING_EXTERNALKEY',
                'domain' => 'relations',
                'default_severity' => 'crit',
                'title_fr' => 'Relations avec clé externe manquante',
                'title_en' => 'Relations with missing external key',
                'description_fr' => 'Signale les relations incomplètes (clé externe vide ou nulle).',
                'description_en' => 'Flags incomplete relations (empty or null external key).',
            ],
            [
                'check_class' => StalenessLastUpdateCheck::class,
                'issue_code' => 'STALENESS_LAST_UPDATE',
                'domain' => 'obsolescence',
                'default_severity' => 'warn',
                'title_fr' => 'Objets obsolètes (dernier update ancien)',
                'title_en' => 'Stale objects (old last update)',
                'description_fr' => 'Détecte les objets non mis à jour depuis un seuil défini.',
                'description_en' => 'Detects objects not updated since a defined threshold.',
            ],
            [
                'check_class' => StaleWithoutOwnerCheck::class,
                'issue_code' => 'STALE_WITHOUT_OWNER',
                'domain' => 'hygiene',
                'default_severity' => 'crit',
                'title_fr' => 'Objets obsolètes sans responsable',
                'title_en' => 'Stale objects without owner',
                'description_fr' => 'Identifie les objets anciens sans propriétaire ou équipe.',
                'description_en' => 'Identifies old objects without owner or team.',
            ],
            [
                'check_class' => OwnershipMissingCheck::class,
                'issue_code' => 'OWNERSHIP_MISSING',
                'domain' => 'hygiene',
                'default_severity' => 'warn',
                'title_fr' => 'Ownership manquant',
                'title_en' => 'Missing ownership',
                'description_fr' => 'Contrôle la présence d’un owner/manager/contact.',
                'description_en' => 'Checks for owner/manager/contact assignment.',
            ],
            [
                'check_class' => ClassificationMissingCheck::class,
                'issue_code' => 'CLASSIFICATION_MISSING',
                'domain' => 'completeness',
                'default_severity' => 'warn',
                'title_fr' => 'Classification manquante',
                'title_en' => 'Missing classification',
                'description_fr' => 'Vérifie les champs de classification (organisation/localisation).',
                'description_en' => 'Verifies classification fields (organization/location).',
            ],
            [
                'check_class' => OrgLocationConsistencyCheck::class,
                'issue_code' => 'CLASSIFICATION_ORG_LOCATION_MISMATCH',
                'domain' => 'consistency',
                'default_severity' => 'info',
                'title_fr' => 'Incohérence organisation/localisation',
                'title_en' => 'Organization/location inconsistency',
                'description_fr' => 'Détecte les couples org/location incohérents.',
                'description_en' => 'Detects inconsistent org/location pairs.',
            ],
            [
                'check_class' => StatusEmptyCheck::class,
                'issue_code' => 'STATUS_EMPTY',
                'domain' => 'completeness',
                'default_severity' => 'warn',
                'title_fr' => 'Statut vide',
                'title_en' => 'Empty status',
                'description_fr' => 'Signale les objets sans valeur de statut.',
                'description_en' => 'Flags objects with missing status value.',
            ],
            [
                'check_class' => NamePlaceholderCheck::class,
                'issue_code' => 'NAME_PLACEHOLDER',
                'domain' => 'hygiene',
                'default_severity' => 'info',
                'title_fr' => 'Nom placeholder',
                'title_en' => 'Placeholder name',
                'description_fr' => 'Détecte les noms techniques ou temporaires (test, tmp, etc.).',
                'description_en' => 'Detects technical/temporary names (test, tmp, etc.).',
            ],
            [
                'check_class' => DuplicatesNameCheck::class,
                'issue_code' => 'DUPLICATES_NAME',
                'domain' => 'consistency',
                'default_severity' => 'warn',
                'title_fr' => 'Doublons de nom',
                'title_en' => 'Duplicate names',
                'description_fr' => 'Repère les doublons par nom (+ org/location si présents).',
                'description_en' => 'Detects duplicates by name (+ org/location when available).',
            ],
            [
                'check_class' => RelationsOrphanExternalKeyCheck::class,
                'issue_code' => 'RELATIONS_ORPHAN_EXTERNALKEY',
                'domain' => 'relations',
                'default_severity' => 'crit',
                'title_fr' => 'Relations orphelines',
                'title_en' => 'Orphan relations',
                'description_fr' => 'Vérifie les clés externes pointant vers des objets absents.',
                'description_en' => 'Checks external keys pointing to missing target objects.',
            ],
            [
                'check_class' => StatusObsoleteReferencedCheck::class,
                'issue_code' => 'STATUS_OBSOLETE_REFERENCED',
                'domain' => 'obsolescence',
                'default_severity' => 'warn',
                'title_fr' => 'Objets obsolètes encore référencés',
                'title_en' => 'Obsolete objects still referenced',
                'description_fr' => 'Signale les objets obsolètes toujours utilisés par d’autres.',
                'description_en' => 'Flags obsolete objects that are still referenced.',
            ],
        ];
    }
}
