# LivaChat — Requirements Document & MkhayaHub Integration Analysis

**Author:** Gift Ncube  
**Date:** 2026-05-21  
**Source:** `/LivaChat/` (Spruko Technologies — commercial licence)  
**Target:** MkhayaHub (Laravel 11 · Vue 3 · Inertia · Vuetify · Sneat template)

---

## 1. Project Overview

LivaChat is a commercial customer-support SaaS product developed by **Spruko Technologies Private Limited** and licensed via CodeCanyon/ThemeForest. It ships as a stand-alone Laravel 10 application with a Bootstrap 5 Blade frontend and self-hosted WebSocket infrastructure. The system covers the full support lifecycle: live chat, ticketing, knowledge base, email campaigns, visitor tracking, and agent management.

---

## 2. Core Modules & Features

### 2.1 Live Chat

| Feature | Detail |
|---|---|
| Embeddable widget | JavaScript snippet (`liveChat.js`) served from `/MfIatXueATJr` |
| Real-time messaging | `beyondcode/laravel-websockets` (self-hosted Pusher protocol) over a single public channel `liveChat` |
| Chat flows (chatbot) | Visual flow builder; flows stored as JSON in `livechat_flows` table |
| Typing indicators | Broadcast via `ChatMessageEvent` |
| Message delivery status | Sent / Seen / Unread tracking per message |
| File sharing | Customer → agent and agent → customer, with per-conversation permission toggle |
| Voice chat | Enable/disable toggle in settings |
| Canned (quick) responses | Stored in `cannedmessages`, supports `{{placeholders}}` |
| Chat rating / CSAT | Post-chat star rating stored in `livechat_reviews` |
| Assign / re-assign | Conversations assignable to one or more operators |
| Mark as solved / unread | Status transitions per conversation |
| Ban / unban customer | Soft-ban by customer record |
| Conversation to ticket | Convert live-chat session into a support ticket |
| Offline message mode | Configurable offline form when no agent is available |
| Appearance settings | Custom colours, logo, icon via media library |
| Notification sounds | Uploadable audio files per agent |

### 2.2 Ticket System

| Feature | Detail |
|---|---|
| Ticket creation | Customers (guest or authenticated) and agents can open tickets |
| Priorities | Configurable priority levels |
| Statuses | New · Inprogress · Re-Open · On-Hold · Closed · Suspend |
| Categories & sub-categories | Hierarchical, agent-assigned per category |
| Ticket assignment | Self-assign or admin-assign; multiple agents via `ticketassignchildren` |
| Internal notes | Private notes visible only to agents |
| Media attachments | Via `spatie/laravel-medialibrary` |
| Email-to-ticket (IMAP) | Fetch emails via `webklex/laravel-imap`; convert to tickets |
| Auto-close | Configurable idle timeout |
| Auto-response | Configurable first-reply template |
| Ticket history | Full per-customer timeline |
| Rating | CSAT rating link sent after ticket close |
| Envato licence verification | Purchase code validation for CodeCanyon products |
| Soft-delete / trash | Restore or permanently destroy trashed tickets |
| CC emails | CC recipients per ticket |

### 2.3 Knowledge Base

| Feature | Detail |
|---|---|
| Articles | Rich text with feature images, media, likes/dislikes |
| Categories & sub-categories | Hierarchical |
| Article comments & replies | Threaded comments |
| Search & suggestions | Full-text search with AI article suggestions |
| FAQs | Stand-alone FAQ section with categories |
| Language detect | `pear/text_languagedetect` for auto-routing |

### 2.4 Agent / Operator Management

| Feature | Detail |
|---|---|
| Roles & permissions | `spatie/laravel-permission`; custom role builder |
| Agent-to-agent messaging | Internal chat via `agent_conversations` table |
| Group conversations | `agent_group_conversations` for team rooms |
| Business hours | Global + personal schedules; holiday calendar |
| Employee activity log | `employee_activities` table tracks agent actions |
| Agent performance reports | Ratings, response times, ticket counts |
| 2FA | Google Authenticator TOTP + Email OTP |

### 2.5 Visitor Tracking

| Feature | Detail |
|---|---|
| Live visitors list | Real-time list with browser, IP, city, country, current URL |
| Geo-IP lookup | `torann/geoip` |
| Proactive engagement | Agent can initiate chat with a live visitor |
| Session close | Force-end a visitor session |

### 2.6 Email Campaigns

| Feature | Detail |
|---|---|
| Contact lists | Import via CSV (`CustomerImport`) |
| Templates | Reusable HTML templates |
| Campaigns | Schedule or immediate send via queued `BulkMails` job |
| Unsubscribe / status | Per-contact status toggle |

### 2.7 Administration & Configuration

| Feature | Detail |
|---|---|
| Dashboard | Ticket KPIs, live chat count, active agents |
| AI integration | Google Gemini for spell-check / suggestions in ticket replies |
| Multi-language | JSON-based i18n; auto-translate via Google Translate API |
| Announcements | System-wide broadcast messages |
| Social login | Microsoft, Envato OAuth via `laravel/socialite` |
| Security | IP block/unblock, country allow/deny lists, CAPTCHA (Google reCAPTCHA + image), honeypot, rate limiting, HTTPS enforcement |
| Storage | Local or AWS S3 (`league/flysystem-aws-s3-v3`) |
| Maintenance mode | Custom maintenance and 404 pages |
| SEO settings | Per-page meta title/description |
| Custom CSS/JS | Admin-injectable front-end code |
| SMTP configuration | Per-environment email settings with test-send |
| WhatsApp integration | Webhook store (basic) |
| Instagram integration | Webhook store (basic) |

---

## 3. Current Technology Stack

| Layer | Current (LivaChat) |
|---|---|
| PHP | 8.1 |
| Framework | Laravel 10 |
| Frontend | Blade · Bootstrap 5.2 · Vanilla JS |
| Build tool | Vite 5 (Sass only; no component framework) |
| Real-time | `beyondcode/laravel-websockets` 1.14 (Pusher protocol, self-hosted) |
| WebSocket client | `pusher-js` 8.4 + `laravel-echo` 1.16 |
| ORM | Eloquent (MySQL/MariaDB) |
| Auth | Laravel Sanctum · Spatie Roles & Permissions |
| File storage | Spatie Media Library 10 · Local / S3 |
| Queue | Laravel Jobs (`BulkMails`, `MailSend`, `SocketWorker`) |
| GeoIP | `torann/geoip` 3 |
| Image processing | `intervention/image` 2 |
| Email | SwiftMailer 5 (deprecated) / Symfony Mailer shim |
| 2FA | `pragmarx/google2fa-laravel` |
| AI | Google Gemini (HTTP API) |
| i18n | `stichoza/google-translate-php` |
| Primary key | Auto-increment integer |

---

## 4. Identified Issues & Technical Debt

### 4.1 Architecture

- **Single public WebSocket channel** — all messages broadcast on `Channel('liveChat')` with no per-conversation isolation. Any agent can receive all messages; privacy relies entirely on client-side filtering. This is a security and scalability risk.
- **God-controller** — `LiveChatController` handles ≈50 routes (appearance, settings, flow builder, conversations, visitor tracking, file upload, security). Needs decomposition.
- **No UUIDs** — All primary keys are auto-increment integers. Exposes sequential IDs in URLs and API.
- **ChatMessageEvent constructor bloat** — 23 positional nullable parameters; extremely fragile to change and hard to test.
- **No API versioning** — `routes/api.php` is largely unused; all interactions are session-based blade routes.
- **SwiftMailer** — Deprecated since 2021; superseded by Symfony Mailer (already included transitively).
- **Intervention/Image 2** — Major version behind; v3 has a fully rewritten API.
- **`beyondcode/laravel-websockets`** — Largely unmaintained; the recommended successor is `soketi` or managed Pusher/Ably.

### 4.2 Security

- **No CSRF on some AJAX routes** — Several GET routes mutate state (e.g., `conversationdelete/{id}`, `bancustomer`, `markasread`). These should be POST/DELETE.
- **Plaintext WebSocket secrets in config** — `websockets.php` has hardcoded `key: 'ABCDEFG'` and `secret: 'HIJKLMNOP'`.
- **`verify_peer: false`** in SSL config — SSL certificate validation disabled.
- **No rate-limiting on chat message send** — Only `throttle:refresh` applied at the middleware group level.

### 4.3 Code Quality

- **Naming conventions** — Mixed case: `livechat_flow`, `Bussinesshours`, `LiveChatCustomers`, `ticketassignchild`. PHP convention violations throughout.
- **No soft-deletes on conversations** — `delete` is a nullable column rather than `SoftDeletes` trait.
- **Business logic in Blade views** — Filtering/sorting logic exists in view templates.
- **No feature tests** — Only example unit/feature stubs; zero test coverage.

---

## 5. Improvement Recommendations (MkhayaHub-Aligned)

These recommendations align with MkhayaHub's existing stack: **Laravel 11 · PHP 8.3 · Vue 3 + Inertia · Vuetify/Sneat · TypeScript · PostgreSQL · UUIDs · spatie/laravel-permission · module-based architecture**.

### 5.1 Real-time Infrastructure — Replace `beyondcode/laravel-websockets`

**Recommended:** Laravel Reverb (official, Laravel 11 native, GA 2024)

```
composer require laravel/reverb
php artisan reverb:install
```

- Ships with Laravel 11's event broadcasting; first-class Vite integration
- Scales horizontally via Redis pub/sub
- Uses the same Pusher JS / Laravel Echo client — zero frontend change
- Drop-in replacement for `beyondcode/laravel-websockets`
- Supports **private and presence channels** — fix the security issue of the single public channel

Replace the broadcast channel:
```php
// Before (single public channel - all agents see all messages)
return new Channel('liveChat');

// After (private per-conversation channel)
return new PrivateChannel("livechat.{$this->customerId}");
```

### 5.2 Frontend — Vue 3 + Inertia + Vuetify (Sneat)

Replace all Blade templates with Inertia/Vue3 pages following the existing MkhayaHub pattern:

```
resources/js/Modules/Support/
  Pages/
    LiveChat/Index.vue          # Agent chat workspace
    LiveChat/Conversation.vue   # Single conversation view
    Tickets/Index.vue
    Tickets/Show.vue
    KnowledgeBase/Index.vue
    KnowledgeBase/Article.vue
  Components/
    ChatWidget/                  # Embeddable customer widget (separate bundle)
    TicketForm.vue
    CannedMessagePicker.vue
  Stores/
    livechat.ts                  # Pinia store for WebSocket state
    tickets.ts
```

Use **Vuetify components** throughout:
- `v-navigation-drawer` for conversation list
- `v-virtual-scroll` for infinite message history
- `v-chip` for status badges
- `v-badge` for unread counts

### 5.3 Module Structure (aligned with existing MkhayaHub architecture)

```
app/Modules/Support/
  Http/
    Controllers/
      LiveChatController.php          # Decomposed from god-controller
      ConversationController.php
      ChatFlowController.php
      TicketController.php
      KnowledgeBaseController.php
      VisitorController.php
    Requests/
      SendMessageRequest.php
      CreateTicketRequest.php
  Models/
    Conversation.php                  # Renamed from LiveChatConversations
    ConversationMessage.php
    ChatFlow.php
    SupportTicket.php
    KnowledgeArticle.php
    LiveVisitor.php
    CannedResponse.php
  Events/
    MessageSent.php                   # Cleaned up from ChatMessageEvent
    ConversationAssigned.php
    TicketStatusChanged.php
  Jobs/
    SendBulkCampaign.php
  Policies/
    ConversationPolicy.php
    TicketPolicy.php
```

### 5.4 Database — PostgreSQL + UUIDs

Replace integer PKs with UUIDs consistent with MkhayaHub:

```php
// Conversation
$table->uuid('id')->primary();
$table->uuid('customer_id');
$table->foreignUuid('assigned_to')->nullable()->constrained('users');
$table->string('status')->default('pending');    // pending|active|solved
$table->softDeletes();                           // replace nullable 'delete' column
```

### 5.5 AI Enhancement — Claude API (Anthropic)

Replace the Google Gemini HTTP call with the Anthropic SDK (already available in the project ecosystem):

```typescript
// Agent reply suggestions in the chat workspace
const suggestion = await useClaude({
  model: 'claude-haiku-4-5-20251001',   // fastest/cheapest for inline suggestions
  system: 'You are a support agent assistant. Suggest a professional reply.',
  messages: [{ role: 'user', content: conversationHistory }],
  max_tokens: 300,
});
```

Use cases:
- **Reply suggestions** while agent types
- **Ticket summary** (auto-summarise a long thread)
- **Sentiment detection** to flag frustrated customers
- **FAQ auto-answer** (RAG against knowledge base articles)

### 5.6 Email — Replace SwiftMailer

Switch to **Symfony Mailer** (already bundled with Laravel 11):

```php
// Remove: "swiftmailer/swiftmailer": "^5.4"
// Already available via Laravel's Mail facade backed by Symfony Mailer
```

Use `Mailable` classes with queue dispatch; no other change needed.

### 5.7 Image Processing — Intervention Image 3

```
composer require intervention/image:^3.0
```

API changed: `Image::read($path)` replaces `Image::make($path)`.

### 5.8 Security Hardening

| Issue | Fix |
|---|---|
| GET routes that mutate state | Convert to POST/DELETE with CSRF tokens |
| Single public WebSocket channel | Use `PrivateChannel` with `BroadcastServiceProvider` auth |
| Hardcoded WebSocket secrets | Move to `.env`; generate via `php artisan reverb:install` |
| `verify_peer: false` | Remove; let SSL verify normally in production |
| No rate limit on message send | Add `throttle:60,1` per customer on the message endpoint |
| Integer IDs in URLs | UUIDs (see §5.4) |

### 5.9 Testing

Add a test suite mirroring the MkhayaHub pattern:

```php
// tests/Modules/Support/
//   LiveChatTest.php
//   TicketTest.php
//   KnowledgeBaseTest.php
```

Use `Illuminate\Testing\TestResponse` + Inertia assertions:
```php
$this->actingAs($agent)
     ->post('/support/conversations', $payload)
     ->assertInertia(fn ($page) => $page->component('Support/LiveChat/Index'));
```

### 5.10 Chat Widget — Standalone Vite Bundle

The embeddable customer widget should be a separate Vite entry point (not bundled with the admin SPA):

```js
// vite.config.ts
input: {
  'app': 'resources/js/app.ts',                        // MkhayaHub admin
  'chat-widget': 'resources/js/chat-widget/index.ts',  // embeddable snippet
}
```

Customers embed via a single `<script>` tag:
```html
<script src="https://hub.mkhaya.co.za/chat-widget.js"
        data-workspace="abc123"></script>
```

---

## 6. Integration Scope for MkhayaHub

The following features are directly relevant to an ERP/HR platform:

| Priority | Feature | Rationale |
|---|---|---|
| P1 | **Internal live chat** (agent ↔ employee) | Staff can raise HR / asset queries in real-time |
| P1 | **Support tickets** | Replace any ad-hoc email for IT/HR support requests |
| P1 | **Knowledge base** | HR policies, asset procedures, onboarding docs |
| P2 | **Chat flows (chatbot)** | Auto-answer FAQs before routing to an agent |
| P2 | **Visitor / page tracking** | See which module an employee is on when they open chat |
| P3 | **Email campaigns** | HR announcements to all staff |
| P3 | **CSAT ratings** | Measure HR/IT helpdesk quality |
| Out of scope | Envato licence verification | Not relevant to internal tool |
| Out of scope | WhatsApp/Instagram webhooks | No customer-facing channels needed initially |

---

## 7. Migration Approach

```
Phase 1 — Scaffold Support Module
  - Create app/Modules/Support/ skeleton
  - Migrations: conversations, conversation_messages, support_tickets,
                knowledge_articles, canned_responses, chat_flows
  - All UUIDs, PostgreSQL-compatible

Phase 2 — Real-time Layer
  - Install Laravel Reverb
  - Define PrivateChannel per conversation
  - Pinia store: livechat.ts consuming Echo

Phase 3 — Vue UI
  - Chat workspace (agent side) — Vuetify layout
  - Customer widget — standalone Vite bundle
  - Ticket management pages — reuse existing table/form patterns

Phase 4 — Ticket & Knowledge Base
  - Port ticket logic from LivaChat
  - Port knowledge base with article editor (TipTap or Quill)

Phase 5 — AI Enhancements
  - Claude Haiku for reply suggestions
  - Sentiment flagging on incoming messages

Phase 6 — Testing & Hardening
  - Feature tests for all P1 items
  - Security audit: channel auth, rate limiting, UUID exposure
```

---

## 8. Summary of Key Technical Decisions

| Decision | Choice | Reason |
|---|---|---|
| WebSocket server | **Laravel Reverb** | Official Laravel 11 package; maintained; private channels |
| Frontend | **Vue 3 + Inertia + Vuetify** | Consistent with MkhayaHub; no context switch |
| Real-time client | **Laravel Echo + pusher-js** | No change needed; Reverb speaks Pusher protocol |
| Primary keys | **UUID** | Consistent with MkhayaHub; no sequential ID exposure |
| AI | **Claude Haiku (Anthropic)** | Already in project ecosystem; better for inline suggestions than Gemini |
| Email | **Symfony Mailer** (Laravel 11 native) | SwiftMailer is EOL |
| Images | **Intervention Image 3** | Current major version |
| State management | **Pinia** | Existing standard in MkhayaHub |
| Module location | `app/Modules/Support/` | Follows existing module pattern |
