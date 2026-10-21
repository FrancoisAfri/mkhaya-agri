<?php

/*
 * Document Management — folder structure and categories.
 *
 * The refusals here are written as sentences a person can act on. "You cannot put a folder inside
 * itself" is the whole explanation; a stack trace is not an answer to it.
 */

return [

    /*
     * Uploading a folder, by uploading a zip of it. Every file inside still goes
     * through the ordinary upload path.
     */
    'archive' => [
        'unreadable' => 'That file could not be read as a zip archive.',
        'unreadable_entry' => 'This entry could not be read from the archive.',
        'unsafe_path' => 'The path inside the archive is not one we will file under.',
        'too_many' => 'An archive can hold at most :max files.',
        'too_large' => 'That archive expands to more than we will take in one upload.',
        'done' => 'Imported :files file(s) into :folders folder(s).',
    ],

    'groups' => [
        'created' => 'Group created.',
        'updated' => 'Group updated.',
        'deleted' => 'Group deleted, along with the access it gave.',
        'members_added' => 'Added :count person/people.',
        'member_removed' => 'Removed from the group.',
        'folders_granted' => 'Access given to :count folder(s).',
        'folder_revoked' => 'Access to that folder taken away.',
    ],

    'recycle' => [
        'restored' => 'Restored.',
        'purged' => 'Permanently destroyed. The record that it existed is kept.',
        'emptied' => 'Destroyed :purged document(s). :held under legal hold were left alone.',
        'folder_gone' => 'The folder this was in has since been deleted. Restore the folder first, or move the document somewhere else.',
    ],

    'bulk' => [
        'done' => 'Applied to :done document(s). :refused could not be changed.',
    ],

    'access' => [
        'requested' => 'Your request has been sent.',
        'granted' => 'Access granted to :count document(s).',
        'declined' => 'Request declined.',
        'not_found' => 'That document or folder could not be found.',
        'granted_note' => 'Granted from an access request.',
        'download_requested' => 'Your request for a copy has been sent.',
        'download_granted_note' => 'Approved in answer to a request for a copy.',
        'nothing_chosen' => 'Nothing was chosen.',
        'requested_many' => 'Asked for :count thing(s).',
    ],

    'lock' => [
        'held_by_another' => 'Somebody else has this checked out.',
        'cannot_break' => 'You cannot take a document back from somebody else.',
        'checked_out' => 'Checked out to you.',
        'checked_in' => 'Checked back in.',
    ],

    'access_map' => [
        'revoked' => 'Withdrawn.',
        'via_user' => 'Named directly',
        'via_role' => 'Through the :name role',
        'via_group' => 'Through the :name group',
        'via_department' => 'Their department',
        'via_company' => 'Open to the company',
        'via_parent' => ':reason, inherited from :folder',
    ],

    'comments' => [
        'added' => 'Comment added.',
        'resolved' => 'Thread settled.',
        'reopened' => 'Thread reopened.',
        'deleted' => 'Comment removed.',
        'mail_subject' => 'You were mentioned on :title',
        'mail_line' => ':who mentioned you in a comment on :title.',
        'mail_action' => 'Open the document',
        'somebody' => 'Somebody',
    ],

    'requests' => [
        'asked' => 'Asked :count person/people.',
        'fulfilled' => 'Sent. Thank you.',
        'reminded' => 'Asked again.',
        'cancelled' => 'Request withdrawn.',
        'not_pending' => 'That request has already been dealt with.',
        'mail_subject' => 'A document is needed: :what',
        'mail_subject_due' => ':what is needed by :date',
        'mail_line' => ':who has asked you for :what.',
        'mail_reminder' => 'A reminder: :who is still waiting for :what.',
        'mail_due' => 'It is needed by :date.',
        'mail_action' => 'Send it',
    ],

    'checklists' => [
        'template_created' => 'Pack type saved.',
        'started' => 'Pack started.',
        'satisfied' => 'Attached.',
        'cleared' => 'Unlinked.',
        'chased' => 'Asked for :count outstanding document(s).',
        'deleted' => 'Pack removed.',
        'needs_a_subject' => 'A pack about nobody cannot be found again.',
        'started_n' => 'Started :count pack(s).',
    ],

    'superseded' => [
        'done' => 'Marked as superseded.',
        'cleared' => 'No longer marked as superseded.',
        'itself' => 'A document cannot replace itself.',
        'replacement_superseded' => 'That document has itself been replaced. Point at the one in force instead.',
    ],

    'watermark_unknown_viewer' => 'Unidentified reader',

    'quotas' => [
        'full' => ':name has no storage left. Nothing more can be filed there until something is removed or the limit is raised.',
        'saved' => 'Limit saved.',
        'removed' => 'Limit removed.',
    ],

    'fields' => [
        'required' => 'These are needed for this kind of document: :fields.',
    ],

    'shortcuts' => [
        'added' => 'Also filed in :folder.',
        'removed' => 'No longer filed there.',
        'already_there' => 'It is already in that folder.',
    ],

    'share_everyone' => 'Everybody in the company',

    'downloads' => [
        'restricted' => 'Downloads are now restricted.',
        'allowed' => 'Downloads are allowed again.',
    ],

    'uploads' => [
        'infected' => 'This file was refused: the virus scanner identified it as :detail.',
    ],

    'records' => [
        'attached' => 'Filed against this record.',
        'detached' => 'Unlinked from this record. The document is still in the library.',
    ],

    'retention' => [
        'auto_archived' => 'Archived automatically by the retention schedule.',
    ],

    'ai' => [
        'fields_applied' => 'Saved :count detail(s) from the document.',
        'summarised' => 'Summary generated.',
        'disabled' => 'The assistant is switched off for this company.',
        'confidential_excluded' => 'This document is confidential or under legal hold, and this company has not allowed those to be sent to the assistant.',
        'no_text' => 'There is no readable text in this document. It may be a scan that needs OCR.',
        'quota' => 'This company’s monthly AI allowance is used up.',
        'failed' => 'The assistant could not be reached. Try again shortly.',
        'no_question' => 'Type a question first.',
    ],

    'sharing' => [
        'shared_internally' => 'Shared. It is now visible to them.',
        'internal_revoked' => 'Share withdrawn.',
        'unknown_subject' => 'That person, department or role could not be found.',
        'disabled' => 'External sharing is switched off for this company.',
        'created' => 'Link created. Copy it now — it is not shown again.',
        'revoked' => 'Link revoked. The record that it existed is kept.',
        'wrong_password' => 'That password is not correct.',
        'code_subject' => 'Your access code',
        'code_line' => 'Somebody has shared a document with you. Use this code to open it:',
        'code_expiry' => 'It lasts fifteen minutes.',
        'code_ignore' => 'If you were not expecting this, ignore it — nothing has been shared with anybody else.',
        'code_sent' => 'Code sent.',
        'code_too_soon' => 'A code was just sent. Wait a moment before asking for another.',
        'code_expired' => 'That code has expired. Ask for a new one.',
        'code_exhausted' => 'Too many attempts. Ask for a new code.',
        'code_wrong' => 'That code is not right.',
    ],

    // Named where the schedule is read, so a rule reads as a sentence: "5 years from the
    // effective date" rather than a period with an unexplained field name beside it.
    'retention_basis_document_date' => 'the document date',
    'retention_basis_effective_date' => 'the effective date',
    'retention_basis_expiry_date' => 'the expiry date',
    'retention_basis_created_at' => 'the upload date',

    'folders' => [
        'created' => 'Folder created.',
        'updated' => 'Folder updated.',
        'moved' => 'Folder moved.',
        'copied' => 'Copied to ":name".',
        'archived' => 'Folder archived, along with everything inside it.',
        'restored' => 'Folder restored.',
        'deleted' => 'Folder removed.',
        'permissions_saved' => 'Folder permissions saved.',
        'too_deep' => 'Folders can only be nested :max levels deep.',
        'move_into_self' => 'A folder cannot be moved into itself.',
        'move_into_descendant' => 'A folder cannot be moved into one of its own sub-folders.',
        'copy_into_descendant' => 'A folder cannot be copied into one of its own sub-folders.',
        'not_empty' => 'This folder still holds :count document(s). Move or archive them first.',
        'archived_with_folder' => 'Archived with its folder.',
        'copied_from' => 'Copied from ":title".',
    ],

    'categories' => [
        'created' => 'Category created.',
        'updated' => 'Category updated.',
        'deleted' => 'Category removed.',
        'system_protected' => 'This is a built-in category. It can be renamed or switched off, but not removed.',
        'in_use' => 'This category is used by :count document(s). Switch it off instead of removing it.',
    ],

    'compliance' => [
        'blocked' => 'This is on hold: :documents must be renewed first.',
        'expired_on' => 'expired :date',
    ],

    'expiry' => [
        'greeting' => 'Hello',
        'subject_days' => ':title expires in :days days',
        'subject_today' => ':title expires today',
        'headline_days' => '":title" expires in :days days.',
        'headline_today' => '":title" expires today.',
        'reference' => 'Reference: :number',
        'date_line' => 'Expiry date: :date',
        'blocking_warning' => 'Work that depends on this document will be held once it lapses.',
        'open' => 'Open the document',
        'replace_hint' => 'Upload the renewed document as a new version — the current one is kept in its history.',
    ],

    'versions' => [
        'archived' => 'Restore this document before changing its file.',
        'wrong_document' => 'That version belongs to a different document.',
        'already_current' => 'That version is already the current one.',
        'restored_note' => 'Restored from version :version.',
        'restored' => 'Version :version is now current. Nothing was removed.',
    ],

    'approvals' => [
        'already_open' => 'This document is already going through approval.',
        'no_workflow' => 'No approval workflow applies to this document.',
        'no_steps' => 'That workflow has no approval steps yet.',
        'not_yours' => 'This approval is not assigned to you.',
        'not_your_own' => 'Someone other than who submitted or uploaded this document approves it.',
        'already_decided' => 'That approval has already been answered.',
        'closed' => 'This approval is no longer open.',
        'not_current_step' => 'An earlier step has not finished yet.',
        'delegate_to_self' => 'You cannot delegate an approval to yourself.',
        'publish_requires_approval' => 'A document must be approved before it can be published.',
        'mail_subject' => 'Approval needed: :title',
        'mail_greeting' => 'Hello',
        'mail_line' => '":title" is waiting for your approval at the :step step.',
        'mail_due' => 'Due by :date.',
        'mail_delegated' => 'This was delegated to you.',
        'mail_action' => 'Review the document',
        'submitted' => 'Sent for approval.',
        'comment_required' => 'Say why — a decision with no reason behind it cannot be reviewed later.',
        'decision_recorded' => 'Your decision has been recorded.',
        'delegate_unknown' => 'That person could not be found.',
        'delegated' => 'Handed on.',
        'published' => 'Published.',
        'cancelled' => 'Approval cancelled.',
    ],

    'templates' => [
        'empty_body' => 'This template has no content yet.',
        'missing_fields' => 'The record has no value for: :fields. Fill those in, or generate anyway if the gaps are expected.',
        'generated_note' => 'Generated from a template.',
        'created' => 'Template created.',
        'updated' => 'Template saved.',
        'deleted' => 'Template removed.',
        'generated' => 'Document generated.',
        'unknown_fields' => 'These fields do not exist and will print as written: :fields',
    ],

    'signing' => [
        'no_signatories' => 'Add at least one person to sign.',
        'no_file' => 'This document has no file to sign.',
        'already_sent' => 'This document is already out for signature.',
        'pdf_only' => 'Only PDFs can be sent for signature. Replace the file with a PDF first.',
        'signed_note' => 'Signed copy, returned from the signing request.',
        'sent' => 'Sent for signature.',
        'cancelled' => 'Signing request cancelled.',
        'awaiting_signature' => 'Awaiting signature',
        'partially_signed' => 'Partially signed',
        'fully_signed' => 'Fully signed',
        'declined' => 'Declined',
        'expired' => 'Expired',
        'cancelled_status' => 'Cancelled',
        'cancelled_reason' => 'Cancelled without a reason given.',
    ],

];
