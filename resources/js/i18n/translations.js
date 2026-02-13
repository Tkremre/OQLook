export const SUPPORTED_LOCALES = ['fr', 'en'];

export const TRANSLATIONS = {
  fr: {
    app: {
      name: 'OQLook',
      tagline: 'Scanner CMDB auto-adaptatif',
    },
    nav: {
      dashboard: 'Tableau de bord',
      connections: 'Connexions',
      issues: 'Anomalies',
      settings: 'Paramètres',
    },
    pages: {
      dashboard: {
        title: 'Vue DSI',
        subtitle: 'Pilotage des scans CMDB, suivi des anomalies et exécution opérationnelle',
      },
      connections: {
        title: 'Assistant connexion iTop',
        subtitle: 'Configuration des connecteurs, authentification et tests de connectivité',
      },
      issues: {
        title: 'Vue admin - Anomalies',
        subtitle: 'Analyse détaillée des anomalies, filtrage multicritère et gestion des acquittements',
      },
      issueShow: {
        title: 'Anomalie #{id}',
        subtitle: 'Analyse détaillée de l\'anomalie, recommandations et échantillons iTop',
      },
      settings: {
        title: 'Paramètres',
        subtitle: 'Langue de l\'interface et guide d\'utilisation',
      },
    },
    common: {
      nd: 'N/D',
      mode: {
        full: 'Complet',
        delta: 'Delta',
      },
      status: {
        completed: 'Terminé',
        running: 'En cours',
        failed: 'Échec',
      },
    },
    settings: {
      languageCardTitle: 'Langue de l\'interface',
      languageCardDescription: 'Choisissez le pack de langue de l\'application',
      languageLabel: 'Langue',
      languageHint: 'Préférence enregistrée dans ce navigateur.',
      uiCardTitle: 'Affichage et disposition',
      uiCardDescription: 'Personnalisez le thème, la largeur et la densité',
      themeLabel: 'Thème',
      layoutLabel: 'Disposition',
      densityLabel: 'Densité',
      themeOqlook: 'OQLook (par défaut)',
      themeSlate: 'Ardoise',
      themeSand: 'Sable',
      themeDark: 'Nuit',
      layoutFull: 'Pleine largeur',
      layoutBoxed: 'Centrée (boxed)',
      densityComfortable: 'Confort',
      densityCompact: 'Compacte',
      readmeCardTitle: 'Tutoriel et documentation',
      readmeCardDescription: 'Consultez les README directement depuis l\'application',
      readmeEmpty: 'Aucun README disponible.',
      readmeUpdatedAt: 'Mis à jour',
      readmeSize: 'Taille',
      frLabel: 'Français',
      enLabel: 'Anglais',
    },
  },
  en: {
    app: {
      name: 'OQLook',
      tagline: 'Adaptive CMDB quality scanner',
    },
    nav: {
      dashboard: 'Dashboard',
      connections: 'Connections',
      issues: 'Issues',
      settings: 'Settings',
    },
    pages: {
      dashboard: {
        title: 'Executive View',
        subtitle: 'CMDB scan control, issue tracking and operational execution',
      },
      connections: {
        title: 'iTop Connection Wizard',
        subtitle: 'Connector setup, authentication and connectivity tests',
      },
      issues: {
        title: 'Admin View - Issues',
        subtitle: 'Detailed issue analysis, multi-filtering and acknowledgement management',
      },
      issueShow: {
        title: 'Issue #{id}',
        subtitle: 'Detailed issue analysis, recommendations and iTop samples',
      },
      settings: {
        title: 'Settings',
        subtitle: 'UI language and usage guide',
      },
    },
    common: {
      nd: 'N/A',
      mode: {
        full: 'Full',
        delta: 'Delta',
      },
      status: {
        completed: 'Completed',
        running: 'Running',
        failed: 'Failed',
      },
    },
    settings: {
      languageCardTitle: 'Interface language',
      languageCardDescription: 'Choose the application language pack',
      languageLabel: 'Language',
      languageHint: 'Preference is saved in this browser.',
      uiCardTitle: 'Display and layout',
      uiCardDescription: 'Customize theme, width and density',
      themeLabel: 'Theme',
      layoutLabel: 'Layout',
      densityLabel: 'Density',
      themeOqlook: 'OQLook (default)',
      themeSlate: 'Slate',
      themeSand: 'Sand',
      themeDark: 'Dark',
      layoutFull: 'Full width',
      layoutBoxed: 'Centered (boxed)',
      densityComfortable: 'Comfortable',
      densityCompact: 'Compact',
      readmeCardTitle: 'Tutorial and documentation',
      readmeCardDescription: 'Read project README files directly in the app',
      readmeEmpty: 'No README available.',
      readmeUpdatedAt: 'Updated',
      readmeSize: 'Size',
      frLabel: 'French',
      enLabel: 'English',
    },
  },
};