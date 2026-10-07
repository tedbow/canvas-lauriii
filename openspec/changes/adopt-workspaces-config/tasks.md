# Tasks: adopt-workspaces-config

## 1. Branch and dependency

- [x] 1.1 Fork branch `3588540-workspaces-config` from `3588540-stage-canvas-auto-saves` for issue 3588540 (MR 1056 itself is not diverted)
- [x] 1.2a Add `drupal/workspace_config` to the module's composer dependencies (project is `workspace_config`, not `workspaces_config`; constraint is `^1.0@dev` — composer only honours a `#<commit>` pin in the root package, so the site pins the commit, not Canvas)
- [x] 1.2b Declare `workspace_config` in canvas.info.yml and enable it in the install and update paths (done by ADR 0017's update path)
- [x] 1.3 Ship these specs under `openspec/changes/adopt-workspaces-config/` in the canvas module repository on the implementation branch
- [x] 1.4 Realign these specs with ADR 0017: staging follows the active workspace, the workspace is the unit of publish, cache invalidation accepted (D6), base copy and attribution decided (D7)

## 2. Config persist path (D1, D2, D5, D7)

- [x] 2.0a Pin auto-save staging bookkeeping (legacy key-value store, pending write buffer, form violations, pruner state) to a key-value factory no workspace overlay decorates: Workspace Config turns every collection into a per-workspace overlay while a workspace is active, so staging rows written inside a workspace would be invisible to the reads, deletes and migrations that run in Live
- [x] 2.0c Retire key-value staging: Workspaces and Workspace Config are hard dependencies, so every draft is a workspace revision, workspace-scoped configuration or a snapshot row (staged configuration translations now take the snapshot path); the nullable workspace-service fallbacks, the lazy legacy migration on every read/write and the schema-readiness checks are removed, the Main workspace cannot be deleted, and `CanvasKernelTestBase` provisions the Main workspace, `workspace_config` and the snapshot schema like an installed site
- [x] 2.0b ~~Write Live config on `canvas.api.config.*` routes outside the auto-save workspace~~ Superseded by ADR 0017: those routes stage into the active workspace; the Live-write wrappers were removed
- [x] 2.7 ~~Resolve the config cache partitioning between Live writes and in-workspace reads~~ Moot after 2.0b's reversal: reads and writes share the workspace partition
- [x] 2.1 Route persistable auto-saves of component tree config entities (content templates, patterns, page variants) into workspace-scoped configuration in the active workspace via Workspaces Config: a plain entity save inside the workspace on every staged write, not only at publish. Code components, asset libraries, brand kits and staged config updates keep snapshot staging (D1)
- [x] 2.11 Declare page variants workspace-safe from Canvas: the Workspace Config module's built-in list predates page variants, so saving one inside a workspace was refused
- [x] 2.2 Repurpose the fallback as the invalid-data store: config persist failures (storage-layer rejection, or a type the site has not declared workspace-safe) fall back to it; a successful workspace-scoped config persist deletes any invalid-data entry for the target; only Canvas clients load it, never non-Canvas consumers (Views, entity display outside Canvas)
- [x] 2.3 Resolve config reads through buffer, then invalid-data store, then workspace-scoped configuration in the auto-save read API
- [x] 2.4 Confirm staged config resolves as regular configuration when the workspace is active and as live configuration outside it, including on non-Canvas routes (entity view builder, Views, page variant resolution) with no preview-route special-casing
- [x] 2.5 Attribution and conflict metadata for workspace-scoped config staging live in the pending buffer sidecar (editor, edit time, client instance, base hash), per the editing-lifecycle attribution requirement (D7)
- [ ] 2.6 Verify hot-path PATCH latency does not regress: staging writes on preview-critical routes stay buffered (or equally cheap), with no synchronous entity-store or config-store writes (D5)
- [x] 2.8 Route config drafts through the deferred flusher so a request produces at most one config save per target, and so one round of cache invalidation (D5, D6)
- [x] 2.9 Base copy for hashes, dirty state and the auto-save starting point: Live configuration when it exists; for configuration created inside the workspace, the hash recorded in the sidecar at the creating write. Never the workspace-scoped copy itself (D7)
- [ ] 2.10 (deferred, separate change) Retire the "created disabled" rule for content templates. Today `status` means "published at least once": created `FALSE`, flipped `TRUE` at publish, and the layout API's `isNew` is `!status()`. With creation itself staged, the flag no longer guards Live, and a greenfield design would make `status` mean "enabled" like every other config entity and derive `isNew` from "no Live copy exists". Retiring it must also redefine `isNew` for config (same signal for patterns and page variants), decide the CLI's write target (its Live writes would render immediately), update the UI badge, and update the three `ApiAutoSaveControllerTest` status assertions. Kept out of this change for its blast radius, not on the merits
- [x] 2.12 Render a workspace's unpublished template creations inside that workspace: the view builder treats a disabled template with no Live copy as effective while a workspace is active, and renders vary by the `workspace` cache context (D3)

## 3. Publish, discard, dirty state (D3, D4)

- [x] 3.1 Workspace publish: validate every tracked config item as typed configuration from its workspace-scoped values before core publish; stage invalid-data store entries into the workspace first and report entries the storage layer still rejects as per-item violations; Workspaces Config applies staged configuration at the pre-publish event
- [x] 3.2 Discard (single and all) clears workspace-scoped configuration alongside every other staging store: delete when no Live copy exists, otherwise reset to Live values
- [x] 3.3 Derive dirty state for config from the workspace-scoped values against the base of 2.9; invalid-data-only state whose normalized data equals canonical reports as no pending changes and discards cleanly

## 4. Migration

- [x] 4.1 Key-value migration (post-update and lazy) stages valid config rows into workspace-scoped configuration, preserving payload, editor, and timestamp. The migrator persists through the same path as a staged write (`LegacyAutoSaveMigrator::importLegacyArray()` → `WorkspaceAutoSave::persistStagedEntity()`), so component tree config rows now land as workspace-scoped configuration with the legacy entry's metadata
- [x] 4.2 Legacy rows that cannot be persisted still migrate into the invalid-data store (the persist path's snapshot fallback)
- [x] 4.3 Snapshot rows holding valid config drafts are promoted into workspace-scoped configuration of the workspace recorded on the row by a post-update pass (`canvas_post_update_0033_promote_config_snapshots`), and deleted once the staged copy is durable. No lazy promotion on read: a snapshot row is also the legitimate fallback for a draft the storage layer rejects, and retrying that save on every read would fail every time

## 5. Docs and diagram

- [x] 5.1 Update the architectural diagram in the canvas module docs for the Workspaces Config storage split
- [x] 5.2 Add a revision note to ADR 0014 recording the storage decision change and its rationale
- [x] 5.3 Record in ADR 0017 that staged configuration writes invalidate cache tags globally (accepted) and that content templates are no longer created disabled
- [x] 5.4 Once 2.x lands, re-read the diagram and the doc blocks in `AutoSave/Workspace` that still describe config drafts as snapshot rows, and fix them

## 6. Tests

- [x] 6.1 Kernel: valid config auto-save stages workspace-scoped configuration, no invalid-data entry, live config unchanged
- [x] 6.2 Kernel: a config payload the storage layer rejects falls back to the invalid-data store; a later valid save promotes it and deletes the entry
- [x] 6.3 Kernel: staged config resolves inside the workspace context and not outside it
- [x] 6.4 Kernel: workspace publish applies staged config to live and clears the workspace-scoped copy; discard removes it without touching live
- [ ] 6.5 Kernel: invalid-data-only staged state equal to canonical reports no pending changes and discards without residue
- [ ] 6.6 Port the existing config auto-save test coverage from MR 1056 and run the full suite on the implementation branch
- [x] 6.7 A content template created and edited inside a named workspace renders entities of that bundle through the template on a non-Canvas route while that workspace is active, and through the core display outside it. Covered at the kernel level (entity view builder render inside and outside the workspace, in `ApiLayoutControllerPatchTest`); a functional test against the frontpage view remains worthwhile once Update tests boot again
- [x] 6.8 Kernel: successive auto-saves of a config entity created inside a workspace keep the same auto-save starting point and do not report a reset to original values

## 7. Follow-up (separate change)

- [ ] 7.1 Open a change for workspace-scoped cache-tag invalidation of workspace-safe configuration (remap `config:NAME` and list tags to a workspace-qualified tag while a workspace is active; tag in-workspace renders accordingly; decide whether it lives in Canvas or upstream in `workspace_config`). Does not touch the write path decided here
