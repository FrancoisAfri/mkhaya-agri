# PM-ADR-012 — Project documents live in the database, not on a disk

**Status:** Accepted · **Date:** 2026-08-08 · **Backlog:** EPIC-050, PM-1500 · **Supersedes part of:** PM-ADR-001

## Context

`LocalDocumentStore` originally wrote uploads to the application's `local` disk under
`pm/documents/{project}/…` and kept only an index row in `pm_external_refs`. The reasoning at the
time was sound as far as it went — "PM never holds the bytes" keeps the module swappable — but it
put the bytes outside every guarantee the rest of the module makes.

Three consequences, in order of how much they would have cost:

1. **Tenancy stopped at the index.** Every `pm_*` table carries `company_id` and `FORCE ROW LEVEL
   SECURITY`. A file on a shared disk carries nothing. One tenant's site photographs and signed
   variation orders sat in a directory that any code holding a path could read, and the isolation
   the module advertises applied only to the row that pointed at them.
2. **Backups became partial.** What is backed up, restored and replicated on this platform is the
   database. A restore brought back the index of a drawing without the drawing — and did so
   silently, because the listing still rendered.
3. **The deployment assumption was unstated.** Disk-backed storage assumes one machine with one
   persistent volume for the life of a tenant. Nothing guarantees that, and the day it changes the
   failure is "documents from before the migration are gone", discovered by a user.

## Decision

**The bytes go in `pm_document_blobs`**, a tenant-scoped table under the same RLS policy as
everything else. Content is `bytea`, written in Postgres hex-input form and read back through the
stream PDO returns; `DocumentBlob` is the only class that has to know that.

**The index stays in `pm_external_refs`.** Only the meaning of `source_id` changes, from a path to a
blob uuid. Listings, task attachments and an eventual switch to the Documents module keep their
shape, and the migration carries existing files across before deleting them from the disk.

**Reads are streamed by the application, never linked.** `DocumentStore::contents()` is the new
primary read path; `url()` remains for a store that genuinely keeps its bytes elsewhere. The local
store returns `null` from `url()` deliberately: a signed link works after it has been forwarded, and
a variation order is not something to hand out on a URL. Every read now passes the same policy check
the page did.

**A store that owns its bytes must delete them.** `DocumentStore::delete()` replaces the old
"remove the index row and leave the file" behaviour. That behaviour was right when the bytes
belonged to another module and wrong the moment they belonged to us: "removed" that only hides a row
leaks storage forever and leaves a tenant's document readable after they deleted it.

## What this costs

Large objects in row storage. Postgres TOASTs them out of line, so the cost is real but bounded, and
`INDEX_COLUMNS` exists so no listing query ever selects `content` — a page that pulled the bytes of
forty drawings to render forty filenames would be the slowest thing in the module.

The 50 MB cap stands. It is the point at which a single row stops being a sensible unit, and a
tenant needing more than that is a tenant who should be running the Documents module.

## What does not change

The seam. Feature code still asks for a `DocumentStore` and never learns which one it got. When the
Documents module is licensed, uploads go there, `contents()` returns `null`, and the controller
falls through to the link that module mints — with no change to any page.
