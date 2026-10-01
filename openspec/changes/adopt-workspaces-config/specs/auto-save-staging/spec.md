# auto-save-staging

## Purpose

Define how pending Canvas changes are persisted and read back: the staging
workspace, workspace-scoped staging of content and configuration, the
invalid-data store, the deferred write buffer, staging invariants (exactly one
store holds the current staged state per entity), revision pruning, workspace
isolation, and migration from the legacy key-value store. Canonical detail
lives in ADR 0014 (staging mechanics) and ADR 0017 (the workspace as the unit
of publish) in the canvas module. This is the target state after the
`adopt-workspaces-config` change, merged onto the Phase 1 baseline
(`stage-auto-saves-in-workspace`, MR 1056, issue 3588540) and realigned with
ADR 0017.

Throughout this spec, "the staging workspace" means the workspace that core
Workspaces negotiation resolves as active for the request, falling back to
the Main workspace (`canvas_default`) when none is negotiated.

## ADDED Requirements

### Requirement: Staging follows the active workspace

Canvas SHALL stage auto-saves in the active workspace, and SHALL activate the Main workspace (`canvas_default`, created during install or update) when negotiation yields none. Every staging key SHALL be workspace-qualified so that each workspace's staged state is partitioned from every other's. If the active workspace entity no longer exists at write time (deleted mid-session), the auto-save write SHALL fail with an error; it MUST NOT fall through to another workspace or to saving the entity in Live. If the Main workspace has not been provisioned yet (before install or update completes), the auto-save SHALL be retained in a fallback staging store and remain readable.

#### Scenario: No workspace negotiated

- **WHEN** an editor opens Canvas with no workspace active in their session
- **THEN** the Main workspace is activated and their auto-saves stage in it

#### Scenario: Active workspace deleted mid-session

- **WHEN** the workspace an editor's session points at has been deleted and an auto-save write arrives
- **THEN** the write is rejected with an error, no default (live) revision is created, and nothing is staged in another workspace

#### Scenario: Missing Main workspace never leaks to Live

- **WHEN** the Main workspace has not been provisioned and an auto-save write arrives
- **THEN** no default (live) revision is created and the draft is still retained and readable in the editor

### Requirement: Content auto-saves are pending revisions

Auto-saves of workspace-supported content entities SHALL be persisted as pending (non-default) revisions of the target entity, tracked in the staging workspace. Each staged revision SHALL record the acting editor as revision user and the edit time as revision timestamp, since Canvas API saves bypass entity forms. The live default revision SHALL remain unchanged.

#### Scenario: Auto-save creates a pending revision

- **WHEN** an editor changes a page in the Canvas editor
- **THEN** a new non-default revision tracked in the staging workspace exists, its revision user is the editor, and the live page output is unchanged

### Requirement: Valid config auto-saves are staged as workspace-scoped configuration

Auto-saves of component tree config entities (content templates, patterns, page variants) that can be persisted SHALL be staged as workspace-scoped configuration attached to the staging workspace, on every staged write and not only when the entity is created or published. Code components, asset libraries, brand kits and staged config updates SHALL keep the invalid-data store as their primary staging store: their saves compile assets or apply to other configuration, which a draft MUST NOT trigger. Canvas SHALL declare its workspace-staged config entity types workspace-safe to the Workspace Config module; a type the site has not declared safe SHALL fall back to the invalid-data store. Live configuration SHALL remain unchanged until publish. Staged config SHALL be readable through the same auto-save read API as every other staged state, and SHALL resolve as regular configuration when loaded with the staging workspace active: reads inside the workspace context return the staged values, reads outside it return the live values, and configuration created inside the workspace with no live copy is absent outside it. This resolution SHALL apply to every consumer of configuration inside the workspace (entity view builders, Views, page variant resolution, the editor preview), with no route-specific overlay of drafts.

A content template is created disabled and enabled at its first publish. Inside the staging workspace, a disabled template that has no live copy SHALL render as if enabled, since it is that workspace's unpublished creation; outside the workspace it SHALL NOT load at all. Rendered output of templated entities SHALL vary by the workspace cache context.

#### Scenario: Config entity auto-save stages workspace-scoped configuration

- **WHEN** an editor changes a code component (config entity) with a persistable payload
- **THEN** the pending state is stored as workspace-scoped configuration, no invalid-data store entry exists for it, and live configuration is unchanged

#### Scenario: Staged config resolves inside the workspace context

- **WHEN** a page region staged in the staging workspace is loaded while that workspace is active
- **THEN** the staged values are returned, and loading the same configuration outside the workspace returns the live values

#### Scenario: Content template drafted in a workspace renders on non-Canvas routes

- **WHEN** an editor creates a content template for a bundle and view mode inside a named workspace, edits its component tree in the editor, and then views a non-Canvas route inside that workspace that renders entities of that bundle in that view mode (for example a listing of teasers)
- **THEN** those entities render through the template's current staged component tree, and the same route outside the workspace renders them through the core entity display

### Requirement: An invalid-data store retains every auto-save the primary stores cannot hold

When an auto-save cannot be persisted in a primary staging store (pending revision for content, workspace-scoped configuration for config), Canvas SHALL retain it in a separate storage capable of storing invalid data, keyed by target entity type, ID, and language. This applies to payloads the storage layer rejects (content and config alike) and to entity types workspace staging cannot hold. Valid config entity auto-saves SHALL NOT be stored in the invalid-data store. Canvas clients MAY load invalid-data store entries through the auto-save read API; non-Canvas consumers (for example Views or entity display outside Canvas) MUST NOT load them. An auto-save write SHALL only report success to the client after the data is durably stored in one of the staging stores; failures SHALL surface as errors, never be logged and swallowed.

#### Scenario: Invalid config payload

- **WHEN** an editor's change to a code component produces a payload the storage layer cannot persist
- **THEN** the auto-save is retained in the invalid-data store and the editor continues to see and restore that state

#### Scenario: Storage-rejected content payload

- **WHEN** a content auto-save contains a value the storage layer refuses to store as a revision
- **THEN** the auto-save is retained in the invalid-data store and the editor continues to see and restore that state

#### Scenario: Invalid drafts are invisible outside Canvas

- **WHEN** a draft held in the invalid-data store targets an entity rendered by a view or entity display outside Canvas
- **THEN** the rendered output reflects the live state, not the draft

#### Scenario: All stores fail

- **WHEN** neither a primary staging store nor the invalid-data store can persist the auto-save
- **THEN** the client receives an error response for the auto-save request

### Requirement: Exactly one store holds the current staged state

For a given target entity and language, the current staged state SHALL live in exactly one staging store at any time. Read precedence SHALL be: deferred write buffer, then the invalid-data store, then the primary staging store (pending revision for content, workspace-scoped configuration for config). A successful persist to a primary store SHALL delete any invalid-data store entry for the same target; publish and discard SHALL clear all stores for the target.

#### Scenario: Content recovery from fallback

- **WHEN** a content auto-save previously fell back to the invalid-data store and a later auto-save persists successfully as a pending revision
- **THEN** the invalid-data store entry is removed and reads resolve to the pending revision

#### Scenario: Config recovery from fallback

- **WHEN** a config auto-save previously fell back to the invalid-data store and a later auto-save persists successfully as workspace-scoped configuration
- **THEN** the invalid-data store entry is removed and reads resolve to the workspace-scoped configuration

### Requirement: Deferred auto-save writes are durable

Writing to entity storage is too slow for the hot path of preview-critical editing requests; this constraint applies to every staging write regardless of which store it targets. On preview-critical API routes, Canvas SHALL therefore buffer auto-save writes during the request and flush them into staging at kernel terminate, rather than writing to entity storage synchronously. Buffered rows SHALL survive until flushed: a flush failure SHALL keep the row for retry, any read of an entity's auto-save state SHALL first flush its pending buffer row, and buffered rows MUST NOT expire while unflushed. Endpoints returning auto-save hashes or starting points SHALL flush before responding so returned tokens match durable state.

#### Scenario: Hot path defers entity storage writes

- **WHEN** an editor's change arrives on a preview-critical API route
- **THEN** the response is produced without a synchronous entity storage write, and the change is flushed into staging at kernel terminate

#### Scenario: Terminate never runs

- **WHEN** the process dies after the auto-save response is sent but before the terminate flush
- **THEN** the buffered edit is flushed into staging on the next read of that entity's auto-save state, with no data loss

### Requirement: Auto-save writes are idempotent

Re-sending an auto-save payload identical to the currently staged state SHALL be a no-op: it MUST NOT create a new staged revision and MUST NOT discard existing staged state. Detecting "the editor reverted to the canonical values" SHALL compare against the canonical base: the live revision or live configuration loaded outside the staging workspace, or, for configuration created inside the staging workspace with no live copy, the configuration as it was created. The staged copy itself MUST NOT serve as the base. The auto-save starting point reported to the client SHALL be derived from that base and SHALL NOT change across successive staged writes.

#### Scenario: Successive auto-saves of workspace-created configuration

- **WHEN** an editor creates a content template inside a workspace and auto-saves it several times
- **THEN** every response reports the same auto-save starting point and none of the auto-saves is treated as a reset to the original values

#### Scenario: Client retry after timeout

- **WHEN** the client re-sends the same auto-save payload because the first response timed out after being applied
- **THEN** the staged state is unchanged and no staged data is deleted

### Requirement: Dirty state is derived, not stored

Whether an entity has pending changes SHALL be derived at read time by comparing normalized data hashes of the staged state and the canonical revision. Staged state whose normalized data equals the canonical revision SHALL be reported as "no pending changes". This SHALL hold regardless of which staging store holds the state, including state that only ever existed in the invalid-data store.

#### Scenario: Undo back to live values

- **WHEN** an editor manually reverts every change so the staged state matches live
- **THEN** the entity no longer appears in the pending-changes list

#### Scenario: Invalid-data store state equal to live

- **WHEN** a target's only staged state is an invalid-data store entry (no primary-store persist ever succeeded) and its normalized data now equals the canonical state
- **THEN** the entity is reported as having no pending changes and discarding it succeeds without residue

### Requirement: Staged revision history is bounded

Canvas SHALL bound the number of retained staged revisions per entity using log-spaced pruning (approximately 2 * log2(n) retained revisions). Pruning MUST NOT delete the most recent staged revision and MUST NOT touch default (live) revisions.

#### Scenario: Long editing session

- **WHEN** an editor produces hundreds of auto-saves on one entity
- **THEN** the retained staged revisions grow logarithmically and the latest staged state is always intact

#### Scenario: Live revisions are never pruned

- **WHEN** pruning runs for an entity that also has published (default) revisions
- **THEN** no default revision is deleted and the newest staged revision remains intact

### Requirement: Validation happens only at publish time, uniformly

Auto-save staging SHALL NOT validate data semantics at write time; staging stores accept invalid intermediate states by design (routing a payload between a primary store and the invalid-data store based on whether the storage layer can persist it is not validation). At publish time, every item tracked in the workspace SHALL be validated before any live write occurs: content entities through entity validation plus recorded form violations, config entities through typed configuration validation of the staged values, wherever they are held. Validation failures SHALL be reported as per-item violation responses grouped by entity, for content and config alike; they MUST NOT surface as unhandled server errors. A validation failure in any tracked item SHALL prevent the workspace from being published (all-or-nothing per publish).

#### Scenario: Invalid staged config entity

- **WHEN** a user publishes a workspace that tracks a staged code component whose values fail typed configuration validation
- **THEN** the response lists that item's violations in the standard per-item format and nothing is written to live

#### Scenario: Validation precedes every live write

- **WHEN** a workspace tracks one valid content item and one invalid config item and the user publishes it
- **THEN** the invalid item's violations are reported and the valid item is not published either

### Requirement: The workspace is the unit of publish

Publishing SHALL publish the whole active workspace through the core workspace publish operation. Before it runs, Canvas SHALL validate and access check every tracked item, and SHALL stage any invalid-data store entry into the workspace (as a pending revision or workspace-scoped configuration) so that the workspace holds the single publish source; an entry the storage layer still rejects SHALL block the publish as a per-item violation. Staged configuration SHALL be applied to live configuration by Workspaces Config at the pre-publish event, and core SHALL promote the tracked revisions; on Canvas-triggered publishes both SHALL run inside one database transaction. Publishing SHALL clear every staging store for the workspace, including its workspace-scoped configuration. Item-level scoping is achieved by choosing which workspace to work in, not by selecting items at publish.

#### Scenario: Workspace publish

- **WHEN** two entities have pending changes in a workspace and the user publishes it
- **THEN** both go live and lose all staged state

#### Scenario: Publish releases the entity

- **WHEN** a workspace tracking a staged entity is published
- **THEN** the entity's workspace tracking is removed and the entity can again be saved outside Canvas

#### Scenario: Publishing staged configuration

- **WHEN** a workspace holding a page region as workspace-scoped configuration is published
- **THEN** the staged values are applied to live configuration and the staged copy is removed

#### Scenario: Invalid-data draft blocks publish

- **WHEN** a workspace holds a draft in the invalid-data store that the storage layer still rejects at publish
- **THEN** the publish is refused with that item's violation and nothing is written to live

### Requirement: Staged configuration writes invalidate cache tags as live writes do

A staged write of workspace-scoped configuration SHALL invalidate the same cache tags a live write of that configuration would (the configuration's own tag and its entity type's list tag). Because cache tags are not partitioned per workspace, cache entries carrying those tags in every partition, including live, are dropped. Canvas SHALL NOT suppress this invalidation. Canvas SHALL bound it by flushing at most one staged write per target per request.

#### Scenario: Live caches after a staged template edit

- **WHEN** an editor auto-saves a content template inside a named workspace
- **THEN** cached live output of entities rendered through that template is invalidated and is rebuilt from live configuration on the next live request, unchanged in content

### Requirement: Dependent staged entities follow their host item

Entities implicitly edited by staging a Canvas item (for example the URL alias entity written when a page with a changed path is staged) SHALL be staged in the workspace with it, SHALL NOT leak to the live site before the host item is published, SHALL NOT appear as separate entries in the pending-changes list, and SHALL be published and discarded together with their host item, leaving no tracking behind.

#### Scenario: Page with a changed URL alias

- **WHEN** an editor changes a page's URL alias in Canvas and later publishes the page
- **THEN** the live alias is unchanged until publish, the pending-changes list shows only the page, and after publish neither the page nor its alias remains tracked in the staging workspace

### Requirement: Per-language auto-saves are preserved

Auto-save staging SHALL keep pending changes of different translations of the same entity independently addressable at the storage level: each translation's pending change SHALL be separately keyed, restored, and hash-compared. Because symmetric translations share component-tree structure, the publish and discard unit is the entity's translation group: publishing or discarding an entity SHALL carry every staged translation of it together, and no translation's staged data SHALL ever be dropped without being either published or explicitly discarded.

#### Scenario: Two translations pending

- **WHEN** an editor publishes a page whose English and Finnish translations both hold pending changes
- **THEN** both translations are published together and all of the entity's staging is cleared, with neither translation's edits lost

### Requirement: Legacy key-value auto-saves migrate losslessly

Existing key-value auto-save rows SHALL migrate into workspace staging lazily on first access and eagerly through a post-update pass. Valid config rows SHALL migrate into workspace-scoped configuration; data that no primary store can hold SHALL migrate into the invalid-data store. Invalid-data store rows that hold valid configuration drafts SHALL likewise be promoted into workspace-scoped configuration of the workspace recorded on the row. Migration SHALL preserve the payload, the owning editor, and the last-edit time, and SHALL remove the source row only after the staged copy is durable. Any temporary access relaxation needed to switch workspaces during migration SHALL be confined to the update process and MUST NOT be observable by regular site traffic.

#### Scenario: Upgrade with pending work

- **WHEN** a site updates while an editor has an unpublished key-value auto-save
- **THEN** after the update the pending change appears unchanged in the editor and pending-changes list, attributed to the same editor with the original timestamp

#### Scenario: Upgrade with a pending config draft

- **WHEN** a site updates while an editor has an unpublished, valid key-value config auto-save
- **THEN** after the update the pending change is staged as workspace-scoped configuration and appears unchanged in the editor and pending-changes list, attributed to the same editor with the original timestamp

### Requirement: Discarding clears every staging store

Discarding a pending change SHALL remove its workspace-tracked revisions, workspace-scoped configuration, invalid-data store entries, buffer rows, pruning bookkeeping, and caches. Workspace-scoped configuration that has no live copy SHALL be deleted; configuration that has one SHALL be reset to the live values. Discarding all pending changes SHALL do the same for every staged entity, including entities staged only as workspace revisions or only as workspace-scoped configuration.

#### Scenario: Discard all

- **WHEN** a user discards all pending changes
- **THEN** the pending-changes list is empty and stays empty on reload, and previously staged entities can be edited outside Canvas again

#### Scenario: Discard a staged config item

- **WHEN** a user discards a pending change held as workspace-scoped configuration
- **THEN** the staged configuration is removed, live configuration is unchanged, and the item disappears from the pending-changes list
