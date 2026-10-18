<?php

namespace App\Modules\Operations\DocumentManagement\Support;

use App\Support\Hubs\ModuleHubs;

/**
 * Document Management navigation (Operations & Support).
 *
 * The rule the rest of the platform follows applies here too: a card points at a page that exists.
 *
 * Tiers are set by what the action costs if it goes wrong rather than by seniority. Reading and
 * uploading are READ and MODIFY, because a repository nobody can put anything into is not a
 * repository. Archiving is MODIFY — it is reversible. The settings that decide what may be
 * uploaded at all, and the permanent-deletion screen, are SUPERUSER.
 */
class DocumentHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'document_management';
    }

    public static function guards(): array
    {
        return ['document-management' => self::READ];
    }

    public static function direct(): array
    {
        return [
            // The dashboard is the front door: it is where somebody who opens the module without a
            // particular document in mind should land, and it is the only screen that says what
            // needs attention today.
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'document-management/dashboard',
                'icon' => 'view-dashboard-outline', 'tier' => self::READ],
            ['key' => 'documents', 'name' => 'Documents', 'path' => 'document-management',
                'icon' => 'folder-multiple-outline', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'library' => [
                'name' => 'Library', 'path' => 'document-management/library-hub', 'icon' => 'bookshelf', 'tier' => self::READ,
                'title' => 'document_management.hubs.library.title',
                'subtitle' => 'document_management.hubs.library.subtitle',
                'cards' => [
                    self::card('all_documents', 'document-management', 'file-document-multiple-outline', self::READ, 'blue'),
                    self::card('upload', 'document-management/upload', 'cloud-upload-outline', self::MODIFY, 'green'),
                    self::card('folders', 'document-management/folders', 'folder-outline', self::MODIFY, 'amber'),
                    /*
                     * The four personal slices. Each is a different answer to "where is that
                     * document" — I put it there, somebody sent it to me, I had it open on Tuesday,
                     * I starred it — and none of them is a search anybody would think to build.
                     */
                    self::card('mine', 'document-management/my-documents', 'account-outline', self::READ, 'indigo'),
                    self::card('shared_with_me', 'document-management/shared-with-me', 'account-arrow-left-outline', self::READ, 'cyan'),
                    self::card('recent', 'document-management/recent', 'history', self::READ, 'blue-grey'),
                    self::card('favourites', 'document-management/favourites', 'star-outline', self::READ, 'amber'),
                    // Its own card rather than a filter, because "what did we archive?" is a
                    // question people ask directly and would not think to filter for.
                    self::card('recycle_bin', 'document-management/recycle-bin', 'delete-restore', self::MODIFY, 'red'),
                    self::card('archived', 'document-management/archived', 'archive-outline', self::READ, 'blue-grey'),
                ],
            ],
            'workflow' => [
                'name' => 'Workflow', 'path' => 'document-management/workflow-hub', 'icon' => 'clipboard-check-outline', 'tier' => self::MODIFY,
                'title' => 'document_management.hubs.workflow.title',
                'subtitle' => 'document_management.hubs.workflow.subtitle',
                'cards' => [
                    // The queues people are actually asked about, each its own card because none of
                    // them is a filter anybody would think to construct.
                    // Asking somebody for a document, which is the opposite direction from every
                    // other queue here: not "let me in to that", but "send me that".
                    self::card('document_requests', 'document-management/requests', 'file-send-outline', self::READ, 'teal'),
                    // What should be on file, against what is. The repository can say what it
                    // holds; this is the only screen that says what it is missing.
                    self::card('checklists', 'document-management/checklists', 'format-list-checks', self::READ, 'green'),
                    self::card('awaiting_approval', 'document-management/awaiting-approval', 'clipboard-clock-outline', self::MODIFY, 'primary'),
                    self::card('awaiting_signature', 'document-management/awaiting-signature', 'draw-pen', self::MODIFY, 'indigo'),
                    self::card('expiring', 'document-management/expiring', 'calendar-clock', self::READ, 'orange'),
                    self::card('expired', 'document-management/expired', 'calendar-remove-outline', self::READ, 'red'),
                ],
            ],
            'governance' => [
                'name' => 'Governance', 'path' => 'document-management/governance-hub', 'icon' => 'shield-lock-outline', 'tier' => self::MODIFY,
                'title' => 'document_management.hubs.governance.title',
                'subtitle' => 'document_management.hubs.governance.subtitle',
                'cards' => [
                    self::card('confidential', 'document-management/confidential', 'lock-outline', self::MODIFY, 'red'),
                    // The register rather than a flat listing of held documents. "What are we holding
                    // for case X" is the question people bring to this, and a list of documents
                    // makes answering it a counting exercise.
                    self::card('legal_hold', 'document-management/legal-holds', 'gavel', self::MODIFY, 'deep-purple'),
                    self::card('disposal', 'document-management/disposal', 'delete-clock-outline', self::MODIFY, 'orange'),
                    self::card('groups', 'document-management/groups', 'account-group-outline', self::MODIFY, 'indigo'),
                    self::card('access_requests', 'document-management/access-requests', 'account-key-outline', self::MODIFY, 'purple'),
                    // Reviewing access rather than granting it. Every other screen here answers
                    // "let this person in"; none of them answers "who is in, and why".
                    self::card('access_management', 'document-management/access-management', 'account-search-outline', self::MODIFY, 'deep-purple'),
                    self::card('unreadable', 'document-management/unreadable', 'text-box-remove-outline', self::MODIFY, 'blue-grey'),
                    self::card('activity', 'document-management/activity', 'history', self::MODIFY, 'teal'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'document-management/configure-hub', 'icon' => 'cog-outline', 'tier' => self::SUPERUSER,
                'title' => 'document_management.hubs.configure.title',
                'subtitle' => 'document_management.hubs.configure.subtitle',
                'cards' => [
                    // Superuser: this screen decides what may enter the system at all, and holds
                    // the category list everything else is filed under.
                    self::card('settings', 'document-management/settings', 'tune', self::SUPERUSER, 'blue-grey'),
                    // Storage is a settings question: it says nothing about any document and
                    // everything about the account.
                    self::card('storage', 'document-management/storage', 'database-outline', self::SUPERUSER, 'teal'),
                    // MODIFY rather than SUPERUSER: a template is wording, and the person who
                    // writes the employment contract is not usually the person who administers the
                    // system. Generating from one is gated by the document permissions.
                    self::card('templates', 'document-management/templates', 'file-document-edit-outline', self::MODIFY, 'purple'),
                ],
            ],
        ];
    }
}
