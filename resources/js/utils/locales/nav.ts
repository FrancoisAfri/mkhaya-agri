// Navigation category + module display-name translations, keyed by category slug
// and module code_name. Merged into each locale's `nav` namespace in i18n.ts and
// resolved by MainLayout (tCategory/tModule) with fallback to the English DB name.
//
// Best-effort translations for the SA languages — acronyms/proper terms (CRM, LMS,
// E-Sign, POPIA, GL) are intentionally kept. Native-speaker review welcome; any
// key omitted for a language simply shows the English name.

// `more` labels the overflow menu in the horizontal bar (see HorizontalNav.vue).
// `insights` labels the Insights entry that ends every category (see MainLayout).
type NavNames = { categories: Record<string, string>; modules: Record<string, string>; more: string; insights?: string }

// ── Ribbon labels ────────────────────────────────────────────────────────────
// The third level of the sidebar. Unlike categories and modules, these are NOT
// translated here: every one of these pages already owns a title in its module's
// locale files, in all twelve languages. So this maps a ribbon path to that
// existing key rather than restating the words — one place for the translation,
// and a page's sidebar entry can never drift from its own heading.
//
// The key is the DB `ribbon_path` with non-alphanumerics collapsed to underscores
// (see ribbonSlug below). A path with no entry falls back to the English DB name,
// which is what every module that has not opted in still does.
export const ribbonLabelKeys: Record<string, string> = {
  // System Settings — a direct entry, so without this it renders its raw English name in every
  // other language. The key is the slugified *path*, not the ribbon's name.
  settings_bi_export: 'analytics.bi.title',
  // E-Sign
  esign_my_signatures: 'e_sign.mine.title',
  // Accounting — the businesses a tenant keeps books for. Without this the sidebar shows the
  // English ribbon name from the database in all twelve languages.
  finance_accounting_entities: 'accounting.cards.entities.title',
  // Project Management
  project_management: 'project_management.hubs.dashboard.title',
  project_management_projects: 'project_management.projects.title',
  project_management_field: 'project_management.field.title',
  project_management_approvals: 'project_management.approvals.title',
  project_management_announcements: 'project_management.announcements.title',
  project_management_portfolio: 'project_management.portfolio.title',
  project_management_meetings: 'project_management.meetings.title',
  project_management_portal: 'project_management.portal.title',
  project_management_evidence: 'project_management.hubs.evidence.title',
  project_management_workspace: 'project_management.hubs.workspace.title',
  project_management_insights: 'project_management.hubs.insights.title',
  project_management_invoicing: 'project_management.hubs.invoicing.title',
  project_management_reporting: 'project_management.hubs.reporting.title',
  project_management_contractors: 'project_management.hubs.contractors.title',
  project_management_setup: 'project_management.hubs.setup.title',
  // Manufacturing & Production
  manufacturing: 'manufacturing.hubs.dashboard.title',
  manufacturing_engineering: 'manufacturing.hubs.engineering.title',
  manufacturing_production: 'manufacturing.hubs.production.title',
  manufacturing_planning: 'manufacturing.hubs.planning.title',
  manufacturing_workforce: 'manufacturing.hubs.workforce.title',
  manufacturing_fulfilment: 'manufacturing.hubs.fulfilment.title',
  manufacturing_loss: 'manufacturing.hubs.loss.title',
  manufacturing_reports: 'manufacturing.hubs.reports.title',
  manufacturing_notifications: 'manufacturing.hubs.notifications.title',
  manufacturing_assistant: 'manufacturing.hubs.assistant.title',
  manufacturing_accounting: 'manufacturing.hubs.accounting.title',
  manufacturing_factories: 'manufacturing.hubs.factories.title',
  manufacturing_integrations: 'manufacturing.hubs.integrations.title',
  manufacturing_quality: 'manufacturing.hubs.quality.title',
  manufacturing_costing: 'manufacturing.hubs.costing.title',
  manufacturing_maintenance: 'manufacturing.hubs.maintenance.title',
  manufacturing_setup: 'manufacturing.hubs.setup.title',
  // Warehouse Management & Intelligence
  warehouse: 'warehouse_management.hubs.dashboard.title',
  warehouse_layout: 'warehouse_management.hubs.layout.title',
  warehouse_products: 'warehouse_management.hubs.products.title',
  warehouse_inventory: 'warehouse_management.hubs.inventory.title',
  warehouse_inbound: 'warehouse_management.hubs.inbound.title',
  warehouse_floor: 'warehouse_management.hubs.floor.title',
  warehouse_outbound: 'warehouse_management.hubs.outbound.title',

  // Supplier Intelligence & Due Diligence
  // Document Management. Without these the third-level ribbons render their raw English name
  // whatever the chosen language, which is the module looking half-translated rather than the
  // labels simply being missing.
  document_management: 'document_management.title',
  // The card title, not the page heading: inside a menu already called Document Management,
  // "Document dashboard" says the same word twice.
  document_management_dashboard: 'document_management.cards.dashboard.title',
  document_management_library_hub: 'document_management.hubs.library.title',
  document_management_workflow_hub: 'document_management.hubs.workflow.title',
  document_management_governance_hub: 'document_management.hubs.governance.title',
  document_management_configure_hub: 'document_management.hubs.configure.title',

  suppliers_monitoring: 'supplier_management.monitoring.title',
  suppliers_settings_data_sources: 'supplier_management.data_sources.title',

  // SHEQ. Without this the activity catalogue's sidebar entry renders its raw English name from
  // the database in all twelve languages, which reads as a module that was only half translated.
  sheq_activities: 'sheq.activities.title',
  sheq_attendance: 'sheq.attendance.title',
  sheq_overrides: 'sheq.overrides.title',
  sheq_contractor_notices: 'sheq.notices.title',

  /*
   * The pages added across this session's SHEQ work.
   *
   * All of them are hub cards rather than ribbons, so they are reached from inside a hub and do
   * not need an entry to be navigable. They need one to be LABELLED: a card can be promoted to a
   * sidebar ribbon — `sheq/activities`, `sheq/attendance`, `sheq/overrides` and
   * `sheq/contractor-notices` above all are — and a ribbon with no key here renders its raw
   * English name from the database in every other language. Harmless while the build ships
   * English only, and a trap the moment languages come back.
   *
   * The key is the slugified path; the value is the card's own title, so the sidebar and the hub
   * card cannot drift apart.
   */
  sheq_command_centre: 'sheq.cards.command_centre.title',
  sheq_emergency_command: 'sheq.cards.emergency_command.title',
  sheq_risk_intelligence: 'sheq.cards.risk_intelligence.title',
  sheq_quality: 'sheq.cards.quality.title',
  sheq_observations: 'sheq.cards.observations.title',
  sheq_changes: 'sheq.cards.changes.title',
  sheq_occupational_health: 'sheq.cards.occupational_health.title',
  sheq_environmental_registers: 'sheq.cards.environmental_registers.title',
  sheq_subcontractors: 'sheq.cards.subcontractors.title',
  sheq_pass_scans: 'sheq.cards.pass_scans.title',
  sheq_contractor_performance: 'sheq.cards.contractor_performance.title',
  sheq_document_versions: 'sheq.cards.document_versions.title',
  sheq_contractor_history: 'sheq.cards.contractor_history.title',
  sheq_compliance: 'sheq.cards.compliance_check.title',
}

export function ribbonSlug(path: string): string {
  return (path || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
}

// Category slugs: human_resources, finance, operations_support,
// system_administration, asset_management, governance_management,
// supply_chain_management

// ── English ──────────────────────────────────────────────────────────────────
// English had no entry at all, so it fell through to the raw `security_categories.name`.
// These are shorter labels for the same rows — "Management" appeared in four of the eight
// names and carried no information, and four labels ran past twenty characters, which is
// what pushed the horizontal bar past the width of the screen.
//
// Deliberately a display label rather than a rename: a dozen migrations, seeders and tests
// resolve categories by that exact name string, and they fail silently when it moves.
const en: NavNames = {
  categories: {
    human_resources: "Human Resources", finance: "Finance", operations_support: "Operations",
    system_administration: "System", asset_management: "Assets", governance_management: "Governance",
    supply_chain_management: "Supply Chain",
    marketing_sales: "Marketing",
  },
  modules: {},
  more: "More",
  insights: "Insights",
}

// ── Afrikaans ────────────────────────────────────────────────────────────────
const af: NavNames = {
  categories: {
    human_resources: "Menslike Hulpbronne", finance: "Finansies", operations_support: "Bedryf",
    system_administration: "Stelsel", asset_management: "Bates", governance_management: "Beheer",
    supply_chain_management: "Voorsieningsketting",
    marketing_sales: "Bemarking",
  },
  modules: {
    payroll_bureau: "Salarisburo", accounting: "Rekeningkunde (GL)", tender_pursuit: "Tenderaanvraag", user_management: "Gebruikersbestuur",
    system_settings: "Stelselinstellings", company_settings: "Maatskappy-instellings", roles_permissions: "Rolle & Toestemmings",
    reports: "Verslae", policy_enforcement: "Beleidstoepassing", recruitment: "Werwing", boarding: "Aanboord", asset_management: "Bates",
    fleet_management: "Vlootbestuur", disciplinary: "Dissiplinêr & Wangedrag", payroll: "Betaalstaat", claims: "Eisebestuur",
    petty_cash: "Kleinkas", staff_loans: "Personeellenings", finance_executive: "Finansiële Uitvoerende", help_desk: "Hulptoonbank",
    popia_compliance: "POPIA-nakoming", esign: "E-Handtekening", crm: "CRM", surveys: "Werknemersopnames", learning_management: "LMS",
    offboarding: "Uitboord", recognition: "Erkenning & Belonings", time_attendance: "Tyd & Bywoning", leave_management: "Verlofbestuur",
    board_committee: "Raad & Komiteebestuur", content_management: "Inhoudsbestuur", resources: "Hulpbronne",
    performance_appraisal: "Prestasiebestuur", whistleblower: "Fluitjieblaser & Etiek", contract_management: "Kontrakbestuur",
    supplier_management: "Verskafferbestuur", supplier_due_diligence: "Verskafferverkenning & Omsigtigheid", budget_management: "Begrotingsbestuur", procurement_requests: "Aankoopversoeke",
    marketplace: "Verskaffermark", conferences: "Konferensies", project_management: "Projekbestuur",
    sheq: "SHEQ",
  },
  more: "Meer",
  insights: "Insigte",
}

// ── French ───────────────────────────────────────────────────────────────────
const fr: NavNames = {
  categories: {
    human_resources: "Ressources Humaines", finance: "Finances", operations_support: "Opérations",
    system_administration: "Système", asset_management: "Actifs", governance_management: "Gouvernance",
    supply_chain_management: "Chaîne d'appro.",
    marketing_sales: "Marketing",
  },
  modules: {
    payroll_bureau: "Bureau de Paie", accounting: "Comptabilité (GL)", tender_pursuit: "Appels d'Offres", user_management: "Gestion des Utilisateurs",
    system_settings: "Paramètres Système", company_settings: "Paramètres de l'Entreprise", roles_permissions: "Rôles & Permissions",
    reports: "Rapports", policy_enforcement: "Application des Politiques", recruitment: "Recrutement", boarding: "Intégration", asset_management: "Actifs",
    fleet_management: "Gestion de Flotte", disciplinary: "Discipline & Fautes", payroll: "Paie", claims: "Gestion des Réclamations",
    petty_cash: "Petite Caisse", staff_loans: "Prêts au Personnel", finance_executive: "Direction Financière", help_desk: "Assistance",
    popia_compliance: "Conformité POPIA", esign: "Signature Électronique", crm: "CRM", surveys: "Enquêtes Employés", learning_management: "LMS",
    offboarding: "Départ", recognition: "Reconnaissance & Récompenses", time_attendance: "Temps & Présence", leave_management: "Gestion des Congés",
    board_committee: "Conseil & Comités", content_management: "Gestion de Contenu", resources: "Ressources",
    performance_appraisal: "Gestion de la Performance", whistleblower: "Lanceur d'Alerte & Éthique", contract_management: "Gestion des Contrats",
    supplier_management: "Gestion des Fournisseurs", supplier_due_diligence: "Renseignement & Diligence Fournisseurs", budget_management: "Gestion Budgétaire", procurement_requests: "Demandes d'Achat",
    marketplace: "Place de Marché Fournisseurs", conferences: "Conférences", project_management: "Gestion de Projet",
    sheq: "SHEQ",
  },
  more: "Plus",
  insights: "Analyses",
}

// ── isiZulu ──────────────────────────────────────────────────────────────────
const zu: NavNames = {
  categories: {
    human_resources: "Abasebenzi", finance: "Ezezimali", operations_support: "Ukusebenza",
    system_administration: "Isistimu", asset_management: "Impahla", governance_management: "Ukubusa",
    supply_chain_management: "Uchungechunge",
    marketing_sales: "Ezokumaketha",
  },
  modules: {
    payroll_bureau: "Ihhovisi Lomholo", accounting: "Ubhalozimali (GL)", tender_pursuit: "Ithenda", user_management: "Ukuphathwa Kwabasebenzisi",
    system_settings: "Izilungiselelo Zesistimu", company_settings: "Izilungiselelo Zenkampani", roles_permissions: "Izindima & Izimvume",
    reports: "Imibiko", policy_enforcement: "Ukuqinisekiswa Kwenqubomgomo", recruitment: "Ukuqashwa", boarding: "Ukwamukelwa", asset_management: "Impahla",
    fleet_management: "Ukuphathwa Kwezimoto", disciplinary: "Isiyalo & Ukuziphatha Okubi", payroll: "Umholo", claims: "Ukuphathwa Kwezimangalo",
    petty_cash: "Imali Encane", staff_loans: "Izikweletu Zabasebenzi", finance_executive: "Isikhulu Sezezimali", help_desk: "Ideski Losizo",
    popia_compliance: "Ukuthobela i-POPIA", esign: "I-E-Sign", crm: "CRM", surveys: "Izinhlolovo Zabasebenzi", learning_management: "LMS",
    offboarding: "Ukuphuma", recognition: "Ukubonga & Imiklomelo", time_attendance: "Isikhathi & Ukuba Khona", leave_management: "Ukuphathwa Kwekhefu",
    board_committee: "Ibhodi & Amakomiti", content_management: "Ukuphathwa Kokuqukethwe", resources: "Izinsiza",
    performance_appraisal: "Ukuphathwa Kokusebenza", whistleblower: "Umbiki & Ubuqotho", contract_management: "Ukuphathwa Kwezinkontileka",
    supplier_management: "Ukuphathwa Kwabahlinzeki", supplier_due_diligence: "Ubuhlakani Nokucwaninga Ngabahlinzeki", budget_management: "Ukuphathwa Kwesabelomali", procurement_requests: "Izicelo Zokuthenga",
    marketplace: "Imakethe Yabahlinzeki", conferences: "Izingqungquthela", project_management: "Ukuphathwa Kwamaphrojekthi",
    sheq: "SHEQ",
  },
  more: "Okuningi",
}

// ── Setswana ─────────────────────────────────────────────────────────────────
const tn: NavNames = {
  categories: {
    human_resources: "Ditlhabololo tsa Batho", finance: "Ditšhelete", operations_support: "Ditiro",
    system_administration: "Sistimi", asset_management: "Dithoto", governance_management: "Puso",
    supply_chain_management: "Ketane ya Kabo",
    marketing_sales: "Papatso",
  },
  modules: {
    payroll_bureau: "Ofisi ya Tuelo", accounting: "Dipalotshelete (GL)", tender_pursuit: "Dithendara", user_management: "Taolo ya Badirisi",
    system_settings: "Dipeakanyo tsa Sistimi", company_settings: "Dipeakanyo tsa Khampani", roles_permissions: "Dikarolo & Ditetla",
    reports: "Dipego", policy_enforcement: "Tiragatso ya Pholisi", recruitment: "Thapo", boarding: "Kamogelo", asset_management: "Dithoto",
    fleet_management: "Taolo ya Dikoloi", disciplinary: "Kgalemo & Boitshwaro jo bo Sa Siamang", payroll: "Tuelo", claims: "Taolo ya Dikopo",
    petty_cash: "Madi a Mannye", staff_loans: "Dikadimo tsa Badiri", finance_executive: "Motsamaisi wa Ditšhelete", help_desk: "Deski ya Thuso",
    popia_compliance: "Kobamelo ya POPIA", esign: "E-Sign", crm: "CRM", surveys: "Dipatlisiso tsa Badiri", learning_management: "LMS",
    offboarding: "Go Tswa", recognition: "Kamogelo & Dituelo", time_attendance: "Nako & Boteng", leave_management: "Taolo ya Matsatsi a Boikhutso",
    board_committee: "Boto & Dikomiti", content_management: "Taolo ya Diteng", resources: "Ditlhabololo",
    performance_appraisal: "Taolo ya Tiragatso", whistleblower: "Motlhagisi & Maitsholo", contract_management: "Taolo ya Dikonteraka",
    supplier_management: "Taolo ya Batlamedi", supplier_due_diligence: "Bohlale le Dipatlisiso tsa Bafani", budget_management: "Taolo ya Tekanyetso", procurement_requests: "Dikopo tsa Theko",
    marketplace: "Mmaraka wa Batlamedi", conferences: "Dikhonferense", project_management: "Taolo ya Diporojeke",
    sheq: "SHEQ",
  },
  more: "Tse dingwe",
}

// ── isiXhosa ─────────────────────────────────────────────────────────────────
const xh: NavNames = {
  categories: {
    human_resources: "Abasebenzi", finance: "Ezemali", operations_support: "Imisebenzi",
    system_administration: "Inkqubo", asset_management: "Impahla", governance_management: "Ulawulo",
    supply_chain_management: "Ikhonkco Lonikezelo",
    marketing_sales: "Ukumaketha",
  },
  modules: {
    payroll_bureau: "Iofisi Yomrholo", accounting: "Ugcino-zimali (GL)", tender_pursuit: "Iithenda", user_management: "Ulawulo Lwabasebenzisi",
    system_settings: "Iisetingi Zenkqubo", company_settings: "Iisetingi Zenkampani", roles_permissions: "Iindima & Iimvume",
    reports: "Iingxelo", policy_enforcement: "Ukunyanzeliswa Komgaqo-nkqubo", recruitment: "Uqeshwa", boarding: "Ukwamkelwa", asset_management: "Impahla",
    fleet_management: "Ulawulo Lweenqwelo", disciplinary: "Ingqeqesho & Isimilo Esibi", payroll: "Umrholo", claims: "Ulawulo Lwamabango",
    petty_cash: "Imali Encinci", staff_loans: "Iimali-mboleko Zabasebenzi", finance_executive: "Igosa Lezemali", help_desk: "Ideski Yoncedo",
    popia_compliance: "Ukuthobela i-POPIA", esign: "E-Sign", crm: "CRM", surveys: "Uvavanyo Lwabasebenzi", learning_management: "LMS",
    offboarding: "Ukuphuma", recognition: "Ukuqashelwa & Imivuzo", time_attendance: "Ixesha & Ubukho", leave_management: "Ulawulo Lwekhefu",
    board_committee: "Ibhodi & Iikomiti", content_management: "Ulawulo Lomxholo", resources: "Izixhobo",
    performance_appraisal: "Ulawulo Lokusebenza", whistleblower: "Umkhwazi & Ukuziphatha", contract_management: "Ulawulo Lwezivumelwano",
    supplier_management: "Ulawulo Lwabathengisi", supplier_due_diligence: "Ubukrelekrele Nophando Lwabathengisi", budget_management: "Ulawulo Lohlahlo-lwabiwo-mali", procurement_requests: "Izicelo Zokuthenga",
    marketplace: "Imarike Yabathengisi", conferences: "Iinkomfa", project_management: "Ulawulo Lweeprojekthi",
    sheq: "SHEQ",
  },
  more: "Okungakumbi",
}

// ── Sepedi (Sesotho sa Leboa) ────────────────────────────────────────────────
const nso: NavNames = {
  categories: {
    human_resources: "Methopo ya Batho", finance: "Ditšhelete", operations_support: "Mešomo",
    system_administration: "Tshepedišo", asset_management: "Dithoto", governance_management: "Pušo",
    supply_chain_management: "Ketane ya Kabo",
    marketing_sales: "Papatšo",
  },
  modules: {
    payroll_bureau: "Ofisi ya Tefo", accounting: "Dipalotšhelete (GL)", tender_pursuit: "Ditendara", user_management: "Taolo ya Badiriši",
    system_settings: "Dipeakanyo tša Tshepedišo", company_settings: "Dipeakanyo tša Khamphani", roles_permissions: "Dikarolo & Ditumelelo",
    reports: "Dipego", policy_enforcement: "Phethagatšo ya Pholisi", recruitment: "Thwalo", boarding: "Kamogelo", asset_management: "Dithoto",
    fleet_management: "Taolo ya Dikoloi", disciplinary: "Kgalemo & Boitshwaro bjo bo Fošagetšego", payroll: "Tefo", claims: "Taolo ya Dikgopelo",
    petty_cash: "Tšhelete ye Nnyane", staff_loans: "Dikadimo tša Bašomi", finance_executive: "Molaodi wa Ditšhelete", help_desk: "Deseke ya Thušo",
    popia_compliance: "Kobamelo ya POPIA", esign: "E-Sign", crm: "CRM", surveys: "Dinyakišišo tša Bašomi", learning_management: "LMS",
    offboarding: "Go Tšwa", recognition: "Kamogelo & Diputso", time_attendance: "Nako & Boteng", leave_management: "Taolo ya Maikhutšo",
    board_committee: "Boto & Dikomiti", content_management: "Taolo ya Dikagare", resources: "Methopo",
    performance_appraisal: "Taolo ya Tirišo", whistleblower: "Mobegi & Boitshwaro", contract_management: "Taolo ya Dikonteraka",
    supplier_management: "Taolo ya Baabi", supplier_due_diligence: "Bohlale le Dinyakišišo tša Baabi", budget_management: "Taolo ya Tekanyetšo", procurement_requests: "Dikgopelo tša Theko",
    marketplace: "Mmaraka wa Baabi", conferences: "Dikhonferentshe", project_management: "Taolo ya Diprotšeke",
    sheq: "SHEQ",
  },
  more: "Tše dingwe",
}

// ── Sesotho ──────────────────────────────────────────────────────────────────
const st: NavNames = {
  categories: {
    human_resources: "Mehlodi ya Batho", finance: "Ditjhelete", operations_support: "Mesebetsi",
    system_administration: "Sisteme", asset_management: "Thepa", governance_management: "Puso",
    supply_chain_management: "Ketane ya Phano",
    marketing_sales: "Papatso",
  },
  modules: {
    payroll_bureau: "Ofisi ya Tefo", accounting: "Dipalotjhelete (GL)", tender_pursuit: "Ditendara", user_management: "Taolo ya Basebedisi",
    system_settings: "Ditlhophiso tsa Sisteme", company_settings: "Ditlhophiso tsa Khamphani", roles_permissions: "Dikarolo & Ditumello",
    reports: "Ditlaleho", policy_enforcement: "Phethahatso ya Leano", recruitment: "Thalo", boarding: "Kamohelo", asset_management: "Thepa",
    fleet_management: "Taolo ya Dikoloi", disciplinary: "Kotlo & Boitshwaro bo bobe", payroll: "Tefo", claims: "Taolo ya Diqoso",
    petty_cash: "Tjhelete e Nyane", staff_loans: "Dikadimo tsa Basebetsi", finance_executive: "Motsamaisi wa Ditjhelete", help_desk: "Deske ya Thuso",
    popia_compliance: "Kobamelo ya POPIA", esign: "E-Sign", crm: "CRM", surveys: "Dipatlisiso tsa Basebetsi", learning_management: "LMS",
    offboarding: "Ho Tswa", recognition: "Kananelo & Meputso", time_attendance: "Nako & Boteng", leave_management: "Taolo ya Matsatsi a Phomolo",
    board_committee: "Boto & Dikomiti", content_management: "Taolo ya Dikahare", resources: "Mehlodi",
    performance_appraisal: "Taolo ya Tshebetso", whistleblower: "Mmegi & Boitshwaro", contract_management: "Taolo ya Dikonteraka",
    supplier_management: "Taolo ya Baabi", supplier_due_diligence: "Botlhale le Dipatlisiso tsa Baabi", budget_management: "Taolo ya Tekanyetso", procurement_requests: "Dikopo tsa Theko",
    marketplace: "Mmaraka wa Baabi", conferences: "Dikhonferense", project_management: "Taolo ya Diprojeke",
    sheq: "SHEQ",
  },
  more: "Tse ding",
}

// ── siSwati ──────────────────────────────────────────────────────────────────
const ss: NavNames = {
  categories: {
    human_resources: "Bantfu Basebenti", finance: "Timali", operations_support: "Imisebenti",
    system_administration: "Umshini", asset_management: "Impahla", governance_management: "Kubusa",
    supply_chain_management: "Luchungechunge",
    marketing_sales: "Kumaketha",
  },
  modules: {
    payroll_bureau: "Lihhovisi Lemholo", accounting: "Kubhala Timali (GL)", tender_pursuit: "Emathenda", user_management: "Kuphatfwa Kwebasebentisi",
    system_settings: "Tilungiselelo Temshini", company_settings: "Tilungiselelo Tenkampani", roles_permissions: "Tindzima & Timvume",
    reports: "Imibiko", policy_enforcement: "Kucinisekiswa Kwenchubomgomo", recruitment: "Kucashwa", boarding: "Kwemukelwa", asset_management: "Impahla",
    fleet_management: "Kuphatfwa Kwetimoto", disciplinary: "Sijeziso & Kutiphatsa Lokubi", payroll: "Umholo", claims: "Kuphatfwa Kwetikhalo",
    petty_cash: "Imali Lencane", staff_loans: "Tikweletu Tebasebenti", finance_executive: "Sikhulu Setimali", help_desk: "Lideski Lelusito",
    popia_compliance: "Kulandzela i-POPIA", esign: "E-Sign", crm: "CRM", surveys: "Ticwaningo Tebasebenti", learning_management: "LMS",
    offboarding: "Kuphuma", recognition: "Kubongwa & Imiklomelo", time_attendance: "Sikhatsi & Kubakhona", leave_management: "Kuphatfwa Kwelikhefu",
    board_committee: "Libhodi & Emakomiti", content_management: "Kuphatfwa Kwalokucuketfwe", resources: "Tinsita",
    performance_appraisal: "Kuphatfwa Kwekusebenta", whistleblower: "Umbiki & Kutiphatsa", contract_management: "Kuphatfwa Kwetinkhontileka",
    supplier_management: "Kuphatfwa Kwebahlinzeki", supplier_due_diligence: "Buhlakani Nekuphenya Ngebahlinteki", budget_management: "Kuphatfwa Kwesabelomali", procurement_requests: "Ticelo Tekutsenga",
    marketplace: "Imakethe Yebahlinzeki", conferences: "Tinhlangano", project_management: "Kuphatfwa Kwemaphrojekthi",
    sheq: "SHEQ",
  },
  more: "Lokunye",
}

// ── Tshivenda ────────────────────────────────────────────────────────────────
const ve: NavNames = {
  categories: {
    human_resources: "Zwiko zwa Vhathu", finance: "Zwa Masheleni", operations_support: "Mishumo",
    system_administration: "Sisiteme", asset_management: "Thundu", governance_management: "Vhuvhusi",
    supply_chain_management: "Tshingaha tsha U Ṋekedza",
    marketing_sales: "Mbambadzo",
  },
  modules: {
    payroll_bureau: "Ofisi ya Muholo", accounting: "Vhalo ha Masheleni (GL)", tender_pursuit: "Thendaha", user_management: "Ndangulo ya Vhashumisi",
    system_settings: "Ndinganyiso dza Sisiteme", company_settings: "Ndinganyiso dza Khamphani", roles_permissions: "Mishumo & Thendelo",
    reports: "Mivhigo", policy_enforcement: "U Shumiswa ha Mbekanyamaitele", recruitment: "U Thoḽiwa", boarding: "U Ṱanganedzwa", asset_management: "Thundu",
    fleet_management: "Ndangulo ya Zwidimela", disciplinary: "Ndayo & Vhuḓifari Vhu si Vhuḓi", payroll: "Muholo", claims: "Ndangulo ya Khumbelo",
    petty_cash: "Tshelede Ṱhukhu", staff_loans: "Zwikoloto zwa Vhashumi", finance_executive: "Muhulwane wa Masheleni", help_desk: "Desiki ya Thuso",
    popia_compliance: "U Tevhedza POPIA", esign: "E-Sign", crm: "CRM", surveys: "Ṱhoḓisiso dza Vhashumi", learning_management: "LMS",
    offboarding: "U Bva", recognition: "U Dzhielwa Nṱha & Malamba", time_attendance: "Tshifhinga & U Vha Hone", leave_management: "Ndangulo ya Duvha ḽa U Awela",
    board_committee: "Bodo & Komiti", content_management: "Ndangulo ya Zwidzhiwa", resources: "Zwiko",
    performance_appraisal: "Ndangulo ya Mushumo", whistleblower: "Muvhigi & Vhuḓifari", contract_management: "Ndangulo ya Konthiraka",
    supplier_management: "Ndangulo ya Vhaṋekedzi", supplier_due_diligence: "Vhuṱali na Ṱhoḓisiso dza Vhaṋekedzi", budget_management: "Ndangulo ya Mugaganyagwama", procurement_requests: "Khumbelo dza U Renga",
    marketplace: "Mmaraka wa Vhaṋekedzi", conferences: "Khonferentsi", project_management: "Ndangulo ya Mishumo",
    sheq: "SHEQ",
  },
  more: "Zwiṅwe",
}

// ── Xitsonga ─────────────────────────────────────────────────────────────────
const ts: NavNames = {
  categories: {
    human_resources: "Switirhisiwa swa Vanhu", finance: "Timali", operations_support: "Mintirho",
    system_administration: "Sisiteme", asset_management: "Nhundzu", governance_management: "Mfumo",
    supply_chain_management: "Nketani wa Vunyiketeri",
    marketing_sales: "Mabindzu",
  },
  modules: {
    payroll_bureau: "Hofisi ya Muhakelo", accounting: "Tibuku ta Timali (GL)", tender_pursuit: "Titendara", user_management: "Vulawuri bya Vatirhisi",
    system_settings: "Vulunghiselelo bya Sisiteme", company_settings: "Vulunghiselelo bya Khampani", roles_permissions: "Swiavelo & Timvumelo",
    reports: "Swiviko", policy_enforcement: "Ku Tirhisiwa ka Pholisi", recruitment: "Ku Thoriwa", boarding: "Ku Amukeriwa", asset_management: "Nhundzu",
    fleet_management: "Vulawuri bya Movha", disciplinary: "Nandzu & Ku Tikhoma ko Biha", payroll: "Muhakelo", claims: "Vulawuri bya Swikombelo",
    petty_cash: "Mali Leyitsongo", staff_loans: "Swikweleti swa Vatirhi", finance_executive: "Mufambisi wa Timali", help_desk: "Deski ra Mpfuno",
    popia_compliance: "Ku Landzela POPIA", esign: "E-Sign", crm: "CRM", surveys: "Vulavisisi bya Vatirhi", learning_management: "LMS",
    offboarding: "Ku Huma", recognition: "Ku Tekiwa & Tibonasi", time_attendance: "Nkarhi & Vukona", leave_management: "Vulawuri bya Ku Wisa",
    board_committee: "Bodo & Tikomiti", content_management: "Vulawuri bya Leswi nga Endzeni", resources: "Switirhisiwa",
    performance_appraisal: "Vulawuri bya Matirhelo", whistleblower: "Muviki & Ku Tikhoma", contract_management: "Vulawuri bya Tikontiraka",
    supplier_management: "Vulawuri bya Vanyiketeri", supplier_due_diligence: "Vutlhari ni Vulavisisi bya Vaphakeri", budget_management: "Vulawuri bya Mpimanyeto", procurement_requests: "Swikombelo swa Ku Xava",
    marketplace: "Makete ya Vanyiketeri", conferences: "Tinhlengeletano", project_management: "Vulawuri bya Tiphurojeke",
    sheq: "SHEQ",
  },
  more: "Swin'wana",
}

// ── isiNdebele ───────────────────────────────────────────────────────────────
const nr: NavNames = {
  categories: {
    human_resources: "Abasebenzi", finance: "Ezeemali", operations_support: "Imisebenzi",
    system_administration: "Isistimu", asset_management: "Impahla", governance_management: "Ukubusa",
    supply_chain_management: "Iketanga",
    marketing_sales: "Ikumaketha",
  },
  modules: {
    payroll_bureau: "Ihhovisi Lomrholo", accounting: "Ukubalwa Kweemali (GL)", tender_pursuit: "Amathenda", user_management: "Ukuphathwa Kwabasebenzisi",
    system_settings: "Iimlungiselelo Zesistimu", company_settings: "Iimlungiselelo Zekampani", roles_permissions: "Iindima & Iimvumo",
    reports: "Imibiko", policy_enforcement: "Ukusetjenziswa Komthethomgomo", recruitment: "Ukuqatjhwa", boarding: "Ukwamukelwa", asset_management: "Impahla",
    fleet_management: "Ukuphathwa Kweemboduluko", disciplinary: "Isijeziso & Ukuziphatha Okumbi", payroll: "Umrholo", claims: "Ukuphathwa Kwezikhalazo",
    petty_cash: "Imali Encani", staff_loans: "Iimboleko Zabasebenzi", finance_executive: "Isiphathimandla Seemali", help_desk: "Ideski Losizo",
    popia_compliance: "Ukulandela i-POPIA", esign: "E-Sign", crm: "CRM", surveys: "Iinhlolisiso Zabasebenzi", learning_management: "LMS",
    offboarding: "Ukuphuma", recognition: "Ukwaziswa & Imiklomelo", time_attendance: "Isikhathi & Ukuba Khona", leave_management: "Ukuphathwa Kwekhefu",
    board_committee: "Ibhodi & Amakomiti", content_management: "Ukuphathwa Kokuqukethwe", resources: "Iinsetjenziswa",
    performance_appraisal: "Ukuphathwa Kokusebenza", whistleblower: "Umbiki & Ukuziphatha", contract_management: "Ukuphathwa Kweenkontileka",
    supplier_management: "Ukuphathwa Kwabaphakeli", supplier_due_diligence: "Ubuhlakani Nokuphenya Ngabarhwebi", budget_management: "Ukuphathwa Kwesabelomali", procurement_requests: "Iimbawo Zokuthenga",
    marketplace: "Imarabho Yabaphakeli", conferences: "Iimhlangano", project_management: "Ukuphathwa Kwamaphrojekthi",
    sheq: "SHEQ",
  },
  more: "Okunye",
}

export const navNames: Record<string, NavNames> = { en, af, fr, zu, tn, xh, nso, st, ss, ve, ts, nr }
