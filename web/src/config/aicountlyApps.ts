/**
 * Central catalog for the AICOUNTLY app launcher (M365-style grid).
 * Sandbox hosts: {product}.gh.aicountly.com
 *
 * `altHosts` lists extra hostnames that still serve the same product (renamed
 * domains, legacy hosts) so resolveCurrentAppId() keeps matching them.
 *
 * Ported from `Saas Common Handler/app-launcher/aicountlyApps.js` in
 * manage-aicountly, which is the source of truth for this catalog. Do not
 * edit it here — change the catalog in manage-aicountly and copy it across.
 */
export interface AicountlyAppDef {
  id: string
  name: string
  jumpKey: string
  prodHost: string
  sandboxHost: string
  altHosts?: string[]
  accent: string
}

export const AICOUNTLY_APPS: AicountlyAppDef[] = [
  {
    id: 'buddy',
    name: 'AI Pulse',
    jumpKey: 'buddy',
    prodHost: 'pulse.aicountly.com',
    sandboxHost: 'buddy.gh.aicountly.com',
    altHosts: ['buddy.aicountly.com', 'pulse.gh.aicountly.com'],
    accent: 'bg-emerald-600',
  },
  {
    id: 'contacts',
    name: 'Contacts',
    jumpKey: 'contacts',
    prodHost: 'contacts.aicountly.com',
    sandboxHost: 'contacts.gh.aicountly.com',
    accent: 'bg-violet-600',
  },
  {
    id: 'books',
    name: 'Smart Books',
    jumpKey: 'books',
    prodHost: 'books.aicountly.com',
    sandboxHost: 'books.gh.aicountly.com',
    accent: 'bg-emerald-600',
  },
  {
    id: 'calendar',
    name: 'Calendar',
    jumpKey: 'calendar',
    prodHost: 'calendar.aicountly.com',
    sandboxHost: 'calendar.gh.aicountly.com',
    accent: 'bg-sky-600',
  },
  {
    id: 'docs',
    name: 'Drive',
    jumpKey: 'docs',
    prodHost: 'drive.aicountly.com',
    sandboxHost: 'drive.gh.aicountly.com',
    accent: 'bg-indigo-600',
  },
  {
    id: 'chat',
    name: 'Connect',
    jumpKey: 'chat',
    prodHost: 'connect.aicountly.com',
    sandboxHost: 'connect.gh.aicountly.com',
    accent: 'bg-fuchsia-600',
  },
  {
    id: 'auditor',
    name: 'Auditor',
    jumpKey: 'auditor',
    prodHost: 'auditor.aicountly.com',
    sandboxHost: 'auditor.gh.aicountly.com',
    accent: 'bg-rose-600',
  },
  {
    id: 'fr',
    name: 'Financial Reporting',
    jumpKey: 'fr',
    prodHost: 'fr.aicountly.com',
    sandboxHost: 'fr.gh.aicountly.com',
    accent: 'bg-cyan-600',
  },
  {
    id: 'secretarial',
    name: 'Secretarial',
    jumpKey: 'secretarial',
    prodHost: 'secretarial.aicountly.com',
    sandboxHost: 'secretarial.gh.aicountly.com',
    accent: 'bg-teal-600',
  },
  {
    id: 'vault',
    name: 'Vault',
    jumpKey: 'vault',
    prodHost: 'vault.aicountly.com',
    sandboxHost: 'vault.gh.aicountly.com',
    accent: 'bg-stone-600',
  },
  {
    id: 'hrms',
    name: 'HRMS',
    jumpKey: 'hrms',
    prodHost: 'hrms.aicountly.com',
    sandboxHost: 'hrms.gh.aicountly.com',
    accent: 'bg-orange-600',
  },
  {
    id: 'manage',
    name: 'Manage Account',
    jumpKey: 'myaccount',
    prodHost: 'manage.aicountly.com',
    sandboxHost: 'manage.gh.aicountly.com',
    accent: 'bg-slate-800',
  },
  {
    id: 'notes',
    name: 'Notes',
    jumpKey: 'notes',
    prodHost: 'notes.aicountly.com',
    sandboxHost: 'notes.gh.aicountly.com',
    accent: 'bg-yellow-600',
  },
  {
    id: 'pos',
    name: 'POS',
    jumpKey: 'pos',
    prodHost: 'pos.aicountly.com',
    sandboxHost: 'pos.gh.aicountly.com',
    accent: 'bg-purple-700',
  },
  {
    id: 'billing',
    name: 'Billing',
    jumpKey: 'billing',
    prodHost: 'billing.aicountly.com',
    sandboxHost: 'billing.gh.aicountly.com',
    accent: 'bg-blue-700',
  },
  {
    id: 'inventory',
    name: 'Inventory',
    jumpKey: 'inventory',
    prodHost: 'inventory.aicountly.com',
    sandboxHost: 'inventory.gh.aicountly.com',
    accent: 'bg-lime-700',
  },
  {
    id: 'sales',
    name: 'Sales',
    jumpKey: 'sales',
    prodHost: 'sales.aicountly.com',
    sandboxHost: 'sales.gh.aicountly.com',
    accent: 'bg-rose-500',
  },
  {
    id: 'purchases',
    name: 'Purchase',
    jumpKey: 'purchases',
    prodHost: 'purchase.aicountly.com',
    sandboxHost: 'purchase.gh.aicountly.com',
    altHosts: ['purchases.aicountly.com', 'purchases.gh.aicountly.com'],
    accent: 'bg-pink-700',
  },
  {
    id: 'insights',
    name: 'Insights',
    jumpKey: 'insights',
    prodHost: 'insights.aicountly.com',
    sandboxHost: 'insights.gh.aicountly.com',
    accent: 'bg-amber-600',
  },
  {
    id: 'remote',
    name: 'Remote',
    jumpKey: 'remote',
    prodHost: 'remote.aicountly.com',
    sandboxHost: 'remote.gh.aicountly.com',
    accent: 'bg-green-700',
  },
  {
    id: 'advisor',
    name: 'Advisor',
    jumpKey: 'advisor',
    prodHost: 'advisor.aicountly.com',
    sandboxHost: 'advisor.gh.aicountly.com',
    accent: 'bg-blue-500',
  },
  {
    id: 'appointments',
    name: 'Appointments',
    jumpKey: 'appointments',
    prodHost: 'appointments.aicountly.com',
    sandboxHost: 'appointments.gh.aicountly.com',
    accent: 'bg-teal-700',
  },
  {
    id: 'contracts',
    name: 'Contracts',
    jumpKey: 'contracts',
    prodHost: 'contracts.aicountly.com',
    sandboxHost: 'contracts.gh.aicountly.com',
    accent: 'bg-slate-600',
  },
  {
    id: 'pay',
    name: 'Pay',
    jumpKey: 'pay',
    prodHost: 'pay.aicountly.com',
    sandboxHost: 'pay.gh.aicountly.com',
    accent: 'bg-emerald-700',
  },

  // Newer blank-scaffold SaaS products — mirrors AppCatalogService::APPS in
  // manage-aicountly server-php. Keep in sync with manage-aicountly's copy of
  // this file (product_icon_catalog_test.php fails the build otherwise).
  {
    id: 'crm',
    name: 'CRM',
    jumpKey: 'crm',
    prodHost: 'crm.aicountly.com',
    sandboxHost: 'crm.gh.aicountly.com',
    accent: 'bg-blue-600',
  },
  {
    id: 'helpdesk',
    name: 'Helpdesk',
    jumpKey: 'helpdesk',
    prodHost: 'helpdesk.aicountly.com',
    sandboxHost: 'helpdesk.gh.aicountly.com',
    accent: 'bg-orange-700',
  },
  {
    id: 'receptionist',
    name: 'Receptionist',
    jumpKey: 'receptionist',
    prodHost: 'receptionist.aicountly.com',
    sandboxHost: 'receptionist.gh.aicountly.com',
    accent: 'bg-pink-600',
  },
  {
    id: 'valuations',
    name: 'Valuations',
    jumpKey: 'valuations',
    prodHost: 'valuations.aicountly.com',
    sandboxHost: 'valuations.gh.aicountly.com',
    accent: 'bg-amber-700',
  },
  {
    id: 'email',
    name: 'Email',
    jumpKey: 'email',
    prodHost: 'email.aicountly.com',
    sandboxHost: 'email.gh.aicountly.com',
    accent: 'bg-sky-700',
  },
  {
    id: 'messaging',
    name: 'Messaging',
    jumpKey: 'messaging',
    prodHost: 'messaging.aicountly.com',
    sandboxHost: 'messaging.gh.aicountly.com',
    accent: 'bg-violet-700',
  },
  {
    id: 'voice',
    name: 'Voice',
    jumpKey: 'voice',
    prodHost: 'voice.aicountly.com',
    sandboxHost: 'voice.gh.aicountly.com',
    accent: 'bg-cyan-700',
  },
  {
    id: 'edge',
    name: 'Edge',
    jumpKey: 'edge',
    prodHost: 'edge.aicountly.com',
    sandboxHost: 'edge.gh.aicountly.com',
    accent: 'bg-zinc-700',
  },
  {
    id: 'aspire',
    name: 'Aspire',
    jumpKey: 'aspire',
    prodHost: 'aspire.aicountly.com',
    sandboxHost: 'aspire.gh.aicountly.com',
    accent: 'bg-fuchsia-700',
  },
  {
    id: 'integrate',
    name: 'Integrate',
    jumpKey: 'integrate',
    prodHost: 'integrate.aicountly.com',
    sandboxHost: 'integrate.gh.aicountly.com',
    accent: 'bg-indigo-700',
  },
  {
    id: 'benefits',
    name: 'Benefits',
    jumpKey: 'benefits',
    prodHost: 'benefits.aicountly.com',
    sandboxHost: 'benefits.gh.aicountly.com',
    accent: 'bg-green-600',
  },
  {
    id: 'capital',
    name: 'Capital',
    jumpKey: 'capital',
    prodHost: 'capital.aicountly.com',
    sandboxHost: 'capital.gh.aicountly.com',
    accent: 'bg-yellow-700',
  },
]

/** App id for the host running this build. */
export const CURRENT_APP_ID = 'pay'
