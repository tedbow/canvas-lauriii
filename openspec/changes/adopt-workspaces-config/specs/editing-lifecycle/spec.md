# editing-lifecycle

## Purpose

Define the draft, preview, and publish model shared by everything edited in Canvas. Canonical detail lives in ADR 0014 (staging) and ADR 0017 (workspaces as the unit of publish) in the canvas module.

## ADDED Requirements

### Requirement: Edits are continuously auto-saved as pending changes

The Canvas editor SHALL persist changes continuously without an explicit save action. Auto-saved states are intermediate: they MAY be invalid and SHALL be persisted without passing validation; no auto-save SHALL be refused or dropped because its data is invalid. Pending changes SHALL NOT affect live site output. While a content entity has pending Canvas changes in a named workspace, validated saves of it in any other workspace or in live (for example the entity's own edit form or validated API writes) are rejected by core Workspaces' one-workspace-per-entity rule, and Canvas staged writes from another workspace are rejected naming the owning workspace; publishing or discarding the pending change releases the entity. An entity tracked only in the Main workspace remains saveable in live. Configuration has no such lock: each workspace holds its own staged copy, and an outside edit surfaces as a conflict against the draft's base rather than as a rejected save.

#### Scenario: Half-finished work survives

- **WHEN** an editor leaves mid-edit with a required prop still empty
- **THEN** the draft persists and is restored on return, and the live page is unchanged

#### Scenario: Outside edit while staged

- **WHEN** a user submits the node edit form for an entity that has pending Canvas changes in a named workspace, with no workspace or a different workspace active
- **THEN** the save is rejected with a message identifying the owning workspace, and succeeds again after the pending change is published or discarded

### Requirement: Preview reflects pending changes

Previewing inside the editor SHALL render the pending (auto-saved) state, composed with the pending state of anything else it depends on (for example page regions and code component working copies). Outside the editor, any route rendered while a workspace is active SHALL reflect that workspace's staged content and staged configuration, so the site itself is the workspace's preview; the live site SHALL show none of it.

#### Scenario: Cross-entity preview

- **WHEN** a page and a page region both have pending changes
- **THEN** the editor preview shows both together while the live site shows neither

#### Scenario: Site preview inside a workspace

- **WHEN** an editor drafts a content template in a named workspace and then browses a listing of that bundle on the site with that workspace active
- **THEN** the listing renders through the drafted template, and the same listing without the workspace active renders through the core display

### Requirement: Publishing is explicit, per workspace, and validated

Publishing SHALL be an explicit step that publishes the active workspace as a whole. Every tracked item SHALL be validated and access checked at publish time; one invalid item SHALL block the publish, with violations reported per item, and discarding that item unblocks it. Scoping what goes live together is done by choosing which workspace to work in.

#### Scenario: Invalid draft blocks the workspace

- **WHEN** a user attempts to publish a workspace that tracks a pending change failing validation
- **THEN** the publish is refused with that item's validation errors and no tracked item goes live; discarding the item allows the publish to proceed

### Requirement: Concurrent edits are detected

Canvas SHALL detect when a pending change no longer matches the state it was based on (another user published or changed the same item) and SHALL surface the conflict instead of silently overwriting.

#### Scenario: Stale draft

- **WHEN** editor B publishes an item while editor A holds a pending change based on the older state
- **THEN** editor A is informed of the conflict before their change can be published

### Requirement: Pending changes are attributed accurately

The pending-changes list SHALL report, for each pending change, the user who made the most recent staged edit and the time of that edit. Attribution SHALL NOT fall back to the content entity's owner or to the current request time, and SHALL survive migration of pending changes between storage backends.

#### Scenario: Editor differs from author

- **WHEN** editor B auto-saves a change to a page authored by user A
- **THEN** the pending-changes list attributes the pending change to editor B with the time B made the edit
