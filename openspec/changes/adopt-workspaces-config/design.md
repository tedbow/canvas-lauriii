# Design: adopt-workspaces-config

## Context

Decided in MR 1056 review conversation (Lauri Timmanee, Ted Bowman, Christian Lopez Espinola, 2026-07-16). Phase 1 stages all config auto-saves as opaque JSON payload rows on `canvas_auto-save_snapshot`, which is enough while workspaces are only ever active on Canvas API routes. Phase 2 (full workspace integration) exposes workspaces to users across the whole site, where staged config must behave as real configuration inside the active workspace: Ted's motivating example is a content template for a teaser edited in a workspace that must affect a view rendered outside Canvas. The Phase 2 branch already implements workspace-scoped config loading; this change pulls that storage decision forward into Phase 1 so the storage does not have to migrate twice and so Canvas does not ship a second, competing config-staging storage next to the contrib Workspaces Config module.

Workspaces Config provenance: split out of Workspaces Extra, maintainers amateescu and S. Lu, ecosystem direction steered by catch, used by Tag1 in production on large sites (their edge-case hardening is a large part of the value). Status caveat: dev module, no tagged release.

Delivery: implementation happens on branch `3588540-workspaces-config`, forked from `3588540-stage-canvas-auto-saves` and pushed to issue 3588540. That branch carries these specs in-repo under `openspec/changes/adopt-workspaces-config/` (full target-state specs, not deltas), the amended ADR 0014, and the updated architecture diagram.

Scope update (2026-09-30): ADR 0017 landed on the same branch before the config persist path was implemented. It superseded two assumptions this change was written against: staging is no longer confined to `canvas_default` (it follows the negotiated active workspace, with `canvas_default` as the Main workspace fallback), and publish is no longer per item (the workspace is the unit of publish, and Workspaces Config applies staged configuration at the pre-publish event). Meanwhile `workspace_config` was enabled and Canvas API config writes started staging into the active workspace, but config *auto-saves* still land in the snapshot store on every write and only become workspace-scoped configuration at publish. The effect is visible on any non-Canvas route rendered inside a workspace: a content template created there resolves as the disabled, empty entity it was created as, so the site falls back to the core display. This design is updated to the ADR 0017 model so the remaining tasks implement the decision as it now stands.

## Goals / Non-Goals

**Goals:**

- Valid auto-saves of component tree config entities (content templates, patterns, page variants) staged as workspace-scoped configuration in the active workspace (the Main workspace `canvas_default` when none is negotiated) via Workspaces Config.
- The fallback store becomes an invalid-data store holding only what no primary store can persist; the one-store-per-target invariant preserved.
- No observable editor behavior change inside Canvas: same auto-save read API, same validation lifecycle, no hot-path latency regression.
- Staged configuration is effective on every route rendered inside the workspace, not only Canvas preview routes: a content template drafted in a workspace changes how entities render on the site while that workspace is active.

**Non-Goals:**

- Scoping cache-tag invalidation per workspace. Staged configuration writes invalidate the same tags as Live writes (D6); narrowing that is a separate change.
- Staging config entity types Canvas does not manage (sites can enable Workspaces Config for those themselves).
- Review, scheduling, or anything else ADR 0017 assigns to `canvas_workflows`.
- Getting `workspace_config` a stable release (worth raising upstream, not a blocker here).

## Decisions

### D1: Workspaces Config is the staging store for valid config auto-saves

The config persist path attempts a workspace-scoped config write first: a plain entity save executed inside the active workspace, which Workspaces Config intercepts and stores in that workspace's partition. Success means the staged values live as real config attached to the active workspace; loading that config with the workspace active yields staged values, loading it outside yields live values, and configuration that was created inside the workspace and has no Live copy is absent outside it. The auto-save read API resolves config from this store the same way it resolves content from workspace revisions.

Every staged write persists this way, not only the create. A config entity created through the Canvas API inside a workspace and then edited in the editor has its current draft in workspace-scoped configuration at all times, so any consumer loading that config inside the workspace (entity view builders, Views, page variant resolution, the editor's own preview) sees the same state without special-casing.

Scope: component tree config entities only (content templates, patterns, page variants). Code components and asset libraries compile and write asset files on every save and invalidate library discovery globally; staged config updates apply to a different target config on save. None of that should run on every keystroke of a draft, so those types keep snapshot staging (the invalid-data store is their primary store) and the code editor keeps its own draft model. Widening to them is a separate decision. The persist path also falls back to a snapshot row for a type the site has not declared workspace-safe; Canvas declares its own types safe (page variants were missing from the Workspace Config module's built-in list).

Rejected alternative: keep Canvas config in the Phase 1 payload store and let sites run Workspaces Config beside it for everything else, configured to ignore Canvas-owned config. Two storages for the same problem, permanent divergence risk, and a guaranteed migration in Phase 2.

### D2: The invalid-data store holds only what no primary store can persist

`canvas_auto-save_snapshot` (the entity backing the invalid-data store) keeps its schema and read integration but narrows to a separate storage capable of storing invalid data: payloads rejected at storage level (invalid intermediate states, for content and config alike) and entity types neither Workspaces nor Workspaces Config can stage. Canvas clients load it through the auto-save read API; non-Canvas consumers (Views, entity display outside Canvas, and similar) never load it. The invariant from Phase 1 carries over with one addition: a successful persist to either primary store (workspace revision or workspace-scoped config) deletes the invalid-data entry for that target. Read precedence stays buffer, then invalid-data store, then primary store.

This resolves Phase 1's open question "should the snapshot entity also serve as the Phase 2 per-workspace config staging store": no, Workspaces Config does.

### D3: Publish is the workspace publish; config rides on it

Superseding the original D3 (per-item publish) per ADR 0017. The workspace is the unit of publish. Before core `Workspace::publish()` runs, Canvas validates every item tracked in the workspace: content through entity validation plus recorded form violations, config through typed configuration validation of the workspace-scoped values. Invalid-data store entries are staged into the workspace first (a config save inside the workspace, or a pending revision for content) so that one store holds the publish source; an entry the storage layer still rejects is a publish blocker reported as a per-item violation. Workspaces Config then applies the staged configuration to Live at its pre-publish event and core promotes the tracked revisions, both inside one database transaction on Canvas-triggered paths. Discard removes the workspace-scoped configuration for the discarded item (deleting it when it has no Live copy, otherwise resetting it to the Live values) alongside every other staging store.

The "created disabled" rule for content templates stays, for now. It existed so that a template created directly in Live could not take over rendering before its first publish; `status` therefore means "published at least once" and the layout API derives `isNew` from it. With creation itself staged that guard is redundant, and a greenfield design would make `status` mean "enabled" and derive `isNew` from "no Live copy exists". Retiring it reaches the UI's `isNew` contract, the CLI's write target and existing publish tests, so it is a separate change (task 2.10). What this change does instead: inside a workspace, the entity view builder treats a disabled template that has no Live copy as effective, since that is precisely the workspace's own unpublished creation; outside the workspace such a template does not load at all. Renders of templated entities vary by the `workspace` cache context, because both the template's content and its effectiveness now depend on the active workspace.

### D4: The invalid-data-only dirty-state edge case becomes explicit spec

From review: when the first and only staged state of a target sits in the invalid-data store (it was never persistable) and later normalized evaluation equals the canonical state, the old auto-save system looped forever on discard ("not exactly the same, keep it"). The baseline requirement "Dirty state is derived, not stored" already implies the right behavior; the spec makes the invalid-data-only path an explicit scenario so it is tested rather than discovered in production again.

### D5: Staging writes stay off the hot path

Writing to entity storage is too slow for the hot path of preview-critical editing requests; the deferred write buffer exists for exactly this reason. Routing valid config auto-saves into workspace-scoped configuration must not put synchronous entity-store or config-store writes back on that hot path: PATCH latency must not regress, and the buffer (or an equally cheap write) covers these writes, flushing into workspace-scoped configuration at kernel terminate the same way content flushes into revisions. Config drafts therefore enter the same deferred flusher as content, which also collapses a request's writes to one config save per target and so one round of cache invalidation per request (D6).

### D6: Staged configuration writes invalidate cache tags exactly like Live writes

Core invalidates a configuration object's cache tag (`config:NAME`) and its entity type's list tag on every save, and Workspaces Config partitions cache *IDs* per workspace, not cache *tags*. A staged config write inside one workspace therefore drops every cache entry carrying that tag in every partition, including Live: render, dynamic page and page cache entries for every entity whose output depends on that configuration (for a content template, every entity of that bundle in that view mode) and anything that listed that entity type. Correctness is unaffected; Live cache hit rate suffers while a template is being edited in any workspace.

Accepted for this change. The invalidation happens once per flushed request (D5), not per PATCH, and it matches how core Workspaces itself treats a content entity saved in a workspace (the entity's own tag is invalidated globally). Narrowing invalidation to the writing workspace, by remapping workspace-safe config tags to a workspace-qualified tag while a workspace is active and tagging in-workspace renders accordingly, is a separate change. It is purely an invalidation-layer concern and does not alter the write path decided here, so it can follow without reworking this change. Global invalidations unrelated to tags, such as `library_info` on code component and asset library saves, are also out of scope here.

### D7: Base copy and attribution for workspace-scoped config

Hash comparison (idempotency, dirty state, conflict detection) and the client's auto-save starting point need a stable base that does not move on every staged write. For content that base is the Live revision loaded outside the workspace. For config the base is the Live configuration when one exists; for configuration that was created inside the workspace and has no Live copy, the base is the copy as it was created, whose normalized hash Canvas records in the pending buffer sidecar at that creating write (the default revision Workspaces Config writes for its tracking entity is an empty placeholder, not a usable copy). Loading the workspace-scoped copy itself as the base is wrong: it is the draft, so every re-save would look like a reset to the original values.

Workspaces Config records no editor or edit time per staged config object, so attribution and conflict metadata (client instance, base hash, editor, edit time) live in the pending buffer sidecar keyed by the auto-save key, exactly as for content whose workspace revision cannot carry them.

There is no exclusive-edit lock for configuration. Core's one-workspace-per-entity rule applies to content; Workspaces Config keeps one staged copy per workspace with no cross-workspace ownership, and a Live config write outside any workspace does not fail because a workspace holds a draft. Conflict detection against the base hash (D7, first paragraph) is what surfaces such an outside edit at publish.

## Risks / Trade-offs

- [`workspace_config` is a dev module with no release] → Pin to a vetted commit in composer; review on the implementation branch is the evaluation gate. Tag1 production usage mitigates maturity concerns more than the release status suggests.
- [Behavioral differences between opaque payloads and real config staging: config CRUD events and entity hooks fire on every staged write] → Covered by porting the existing config auto-save tests and running the full suite on the implementation branch; any event Canvas must suppress gets an explicit test. Component generation already runs outside any workspace with a reentrancy guard (ADR 0017, consequence 10).
- [Cache invalidation fires on every staged write and reaches Live] → Accepted (D6). Bounded to once per flushed request by D5. Scoped invalidation is a follow-up change.
- [Workspaces Config stores each staged write as a new revision of its tracking entity, with no pruning] → Bounded by D5 to one revision per flushed request per target; if revision volume proves a problem, prune those revisions the way Canvas prunes staged content revisions.
- [Workspace-scoped config writes are slower than key-value writes] → Covered by D5: buffered writes on preview-critical routes; verify PATCH latency before merge (tasks).
- [Invalid config states must never hit the config storage layer] → The persist path checks first whether the payload can be persisted and routes failures to the invalid-data store, mirroring the content revision fallback; write-time validation of data semantics remains prohibited.
- [Module direction is community-owned] → amateescu, S. Lu, and catch drive it; check with Glaman, whose Acquia Source work inspired the Phase 1 config handling, before diverging from module conventions.

## Migration Plan

- No shipped-site migration: Phase 1 (MR 1056) has not merged, so no site holds Phase 1 config payload rows.
- The legacy key-value migration (post-update plus lazy) gains a branch: valid config rows stage into workspace-scoped config; rows that cannot persist stage into the invalid-data store. Attribution and timestamps preserved as in Phase 1.
- Update path additionally enables `workspace_config` (done as part of ADR 0017's update path).
- Snapshot rows that hold valid config drafts at the time this lands are promoted into workspace-scoped configuration of the workspace recorded on the row, on first read or by a post-update pass; the row is deleted once the staged copy is durable.

## Resolved Questions

- *How do Live config writes on `canvas.api.*` routes stay visible to reads taken inside the workspace?* Moot. ADR 0017 removed the Live-write wrappers: while a workspace is active, `canvas.api.config.*` writes stage into it, so reads and writes share one config cache partition. Content deletion remains a Live operation.
- *Does Workspaces Config support every config operation Canvas stages?* Create, update and delete are supported for workspace-safe config (delete of a workspace-only object removes it; delete of an object that exists in Live stages a delete marker applied at publish). Rename is not an operation Canvas stages for its managed config; a renamed object is a delete plus a create.
- *Where does per-editor attribution live?* In the pending buffer sidecar (D7).
- *Should the exclusive-edit story for config match content?* No lock (D7).

## Open Questions

- None.
