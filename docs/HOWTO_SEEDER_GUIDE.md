# HowTo Seeder - Documentation

## 📚 Vue d'ensemble

Le `HowToSeeder` peuple automatiquement la table `module_how_tos` avec des questions et réponses pertinentes pour chaque ribbon du système.

**Objectif**: Fournir une documentation auto-générée directement accessible aux utilisateurs via l'interface "How-To".

## 🏗️ Architecture

### Hiérarchie des données
```
Category
  └── Module
       └── Ribbon
            └── HowTo (Question + Answer)
```

### Relations dans la base de données
```sql
-- HowTo table structure
CREATE TABLE module_how_tos (
    id BIGINT PRIMARY KEY,
    module_id BIGINT NOT NULL,
    ribbon_id BIGINT NOT NULL,
    question TEXT NOT NULL,
    answer LONGTEXT NOT NULL,
    active TINYINT DEFAULT 1,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## 🔍 Contenu organisé par module

### 1. System Administration (5 modules)

#### User Management
- Profile Updates Approval (2 how-tos)
- Users Access (2 how-tos)
- Manage Users (3 how-tos)

#### System Settings
- Public Holidays (2)
- Document Types (2)
- Company Identity (2)
- Modules Management (2)
- System Restoration (2)
- Platform Admins (2)
- Offline Guard Policy (2)

#### Company Settings
- Organisation Structure (2)
- Positions (2)

#### Roles & Permissions
- Roles (3)
- Permissions (2)

#### Reports
- Audits (2)
- System Usage (2)
- User Rights (2)

---

### 2. Human Resources (2 modules)

#### Recruitment (13 ribbons)
- Dashboard (2 how-tos)
- Jobs (2)
- Vacancies (2)
- Requisitions (2)
- Candidates (3)
- Approvals (2)
- Succession Planning (2)
- Internal Job Board (2)
- Offers (2)
- Analytics (2)
- Reports (2)
- Approval Workflows (2)
- Social Accounts (2)

#### Boarding (2 ribbons)
- Pre-Boarding (3 how-tos)
- Onboarding (4)

---

### 3. Asset Management (2 modules)

#### Assets (16 ribbons)
- Dashboard (2 how-tos)
- Asset Register (3)
- Maintenance (3)
- Depreciation (2)
- Procurement (2)
- Exit Clearances (2)
- Misuse Detection (2)
- Doc Extraction (2)
- Utilization (2)
- Compliance (2)
- Reports (2)
- Scheduled Reports (2)
- General Settings (1)
- Categories (2)
- Asset Types (2)
- Locations (2)

#### Fleet Management (15 ribbons)
- My Dashboard (2 how-tos)
- Dashboard (2)
- Bookings Approval (2)
- Vehicle Approvals (2)
- Manage Fleet (3)
- Fleet Cards (3)
- Reports (2)
- Scheduled Reports (1)
- Integrations (2)
- BI Analytics (2)
- Alerts (2)
- Setup (2)
- Projects (2)
- Security Check (2)
- Search Bookings (2)

---

### 4. Operations & Support (2 modules)

#### HelpDesk (17 ribbons)
- Dashboard (2 how-tos)
- Tickets (2)
- New Ticket (2)
- Live Chat (2)
- Knowledge Base (2)
- Analytics (2)
- Reports (2)
- Settings (2)
- Inboxes (1)
- Business Hours (2)
- WhatsApp (2)
- CSAT Ratings (2)
- Agent Chat (2)
- Group Chat (2)
- Announcements (2)
- FAQ (2)
- Performance (2)

#### POPIA Compliance (3 ribbons)
- Dashboard (1 how-to)
- Breach Register (3)
- Consent Register (3)
- PHI Access Log (3)

---

### 5. Finance (1 module)

#### Claims Management (11 ribbons)
- Dashboard (2 how-tos)
- My Claims (2)
- New Claim (3)
- Approvals (3)
- Delegations (2)
- Payment Processing (3)
- Reports (2)
- Scheduled Reports (1)
- Executive Dashboard (2)
- AI Intelligence (2)
- Setup (2)

## 🚀 Utilisation

### Lancer le seeder

```bash
# Seul
php artisan db:seed --class=HowToSeeder

# Avec d'autres seeders
php artisan migrate:fresh --seed

# Dans un seeder parent
public function run()
{
    $this->call([
        ModuleSeeders::class,
        HowToSeeder::class,
    ]);
}
```

### Ajouter de nouveaux how-tos manuellement

Via l'interface utilisateur ou via code:

```php
use App\Core\HowTo\Models\HowTo;

HowTo::create([
    'module_id'  => 1,
    'ribbon_id'  => 5,
    'question'   => 'How do I...?',
    'answer'     => 'You can...',
    'active'     => 1,
]);
```

## 🔧 Extension du seeder

### Pour ajouter plus de modules

1. Trouvez le `code_name` du module dans `ModuleSeeders.php`
2. Ajoutez un nouvel appel dans le `run()` method:

```php
// Example: Adding Payroll module how-tos
$this->seedHowTosForModule('payroll', [
    'Dashboard' => [
        ['question' => 'How do I access the payroll dashboard?', 'answer' => '...'],
        ['question' => 'What metrics are displayed?', 'answer' => '...'],
    ],
    'Payroll Runs' => [
        ['question' => 'What is a payroll run?', 'answer' => '...'],
        ['question' => 'How do I create a new payroll run?', 'answer' => '...'],
    ],
    // ... more ribbons
]);
```

### Méthode helper

```php
private function seedHowTosForModule(string $moduleCodeName, array $ribbonData): void
{
    // Finds module by code_name
    // Finds ribbons by name within that module
    // Creates how-tos with question/answer pairs
    // Uses firstOrCreate to prevent duplicates
}
```

## ✅ Bonnes pratiques pour les how-tos

### Format des questions
✅ **Bon**: "How do I add a new user?"
❌ **Mauvais**: "Adding users"

### Format des réponses
✅ **Bon**: Réponse complète avec étapes (3-5 phrases)
❌ **Mauvais**: Réponse vague ou incomplète

### Ordre des questions
1. Questions basiques/fréquentes d'abord
2. Questions avancées après
3. Questions de dépannage en dernier

### Couvrir les cas courants
- "How do I...?" - Les tâches principales
- "Can I...?" - Les limitations/possibilités
- "What is...?" - Les concepts clés
- "Why should I...?" - Les justifications

## 📊 Statistiques

**Total dans le seeder actuel**:
- Modules couverts: 13
- Ribbons: ~100+
- How-tos: ~250+

## 🔄 Maintenance

### Mettre à jour les how-tos existants

```php
HowTo::where('ribbon_id', 5)
      ->update(['answer' => 'Updated answer...']);
```

### Désactiver des how-tos obsolètes

```php
HowTo::where('question', 'Old question')->update(['active' => 0]);
```

### Réséeder en cas de changement

```bash
# Effacer tous les how-tos et réséeder
php artisan db:seed --class=HowToSeeder --force

# Ou avec migration fresh
php artisan migrate:fresh --seed
```

## 🎯 Prochaines étapes

1. **Testez le seeder**: `php artisan db:seed --class=HowToSeeder`
2. **Vérifiez dans l'interface**: Allez à How-To Management
3. **Ajoutez plus de modules**: Complétez les modules manquants
4. **Affinez le contenu**: Mettez à jour les réponses basées sur le feedback
5. **Automatisez les mises à jour**: Créez des migrations pour les changements futurs

## 📝 Fichiers concernés

- **Seeder**: `database/seeders/HowToSeeder.php`
- **Modèle**: `app/Core/HowTo/Models/HowTo.php`
- **Service**: `app/Core/HowTo/Services/HowToService.php`
- **Table**: `module_how_tos`
- **Contrôleur**: `app/Core/HowTo/Controllers/`

## 🐛 Dépannage

### HowTo ne s'affiche pas
- Vérifiez que le ribbon_id est correct
- Assurez-vous que `active = 1`
- Videz le cache Laravel: `php artisan cache:clear`

### Module/Ribbon non trouvé
- Vérifiez le `code_name` du module
- Vérifiez le `ribbon_name` exact (case-sensitive)
- Consultez [ModuleSeeders.php](../ModuleSeeders.php)

### Doublons de how-tos
- Le seeder utilise `firstOrCreate()` pour éviter les doublons
- Vérifiez question exacte et ribbon_id
- Supprimez manuellement les doublons si nécessaire

## 📞 Support

Pour ajouter ou modifier des how-tos:
1. Éditez le seeder
2. Exécutez `php artisan db:seed --class=HowToSeeder`
3. Testez dans l'interface How-To Management

