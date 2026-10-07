# Review: a simpler cut of the `workspaces-split` branch

Scope: `workspaces-split` (merge base `7c871000`, 142 commits, 175 files,
+12,681/−2,164). Back end only (`src/`, `canvas_workflows`, install and
update code: +6,253/−910). The React UI was skimmed and is not in scope of
the verdicts below. Targets a new 2.x branch, so no 1.x API compatibility is
assumed. Core is Drupal 11.3.10; `workspace_config` was read at commit
`d32a425` (2026-08-04) from a sibling checkout (it is not installed in this
site).

Line numbers refer to the files as they are on the branch.

## 1. The simplest viable architecture

The user-facing behavior the branch must keep is listed in
`openspec/changes/adopt-workspaces-config/specs/auto-save-staging/spec.md`
and ADR 0017: auto-saves are never refused and may be invalid; drafts are per
translation; dirty state is derived; concurrent-tab and external-edit
conflicts are detected; the workspace is the unit of publish; config drafts
stage through `workspace_config`; review and scheduling live in
`canvas_workflows`; cross-workspace locks and a workspace switcher are in the
UI.

All of that is achievable with one rule: **a staged write is `$entity->save()`
in the active workspace, and nothing else.** Core Workspaces turns the save
into a pending revision (`WorkspaceProviderBase::entityPresave()`, lines
119-166); `workspace_config` turns a config save into a `workspace_config`
row (`WorkspaceConfigDatabaseStorage::write()`, lines 179-215). Neither layer
validates on save: there is no `validate()` call anywhere in
`EntityStorageBase`, `ContentEntityStorageBase` or
`SqlContentEntityStorage`, so "drafts may be invalid" is already satisfied by
the primary stores. The branch's five stores, two persist services, a buffer,
a flusher, a pruner, a snapshot entity and a workspace-invariant key-value
factory all exist to hedge against cases that either cannot occur or are
better handled by returning an error for that one write.

The resulting shape:

1. **Dependencies and bootstrap (keep as is).** `workspaces` and
   `workspace_config` hard dependencies; Main workspace `canvas_default`;
   `CanvasController` activates it when negotiation yields none
   (`src/Controller/CanvasController.php:120-127`); Canvas API routes never
   force a workspace. Every Canvas config entity type is declared
   workspace-safe through `hook_workspace_config_safe_list_alter()` (today
   only `canvas.page_variant.*` is added; `workspace_config` already lists the
   rest, `WorkspaceConfigSchemaHooks.php:44-54`).

2. **Write path.** `AutoSaveManager::saveEntity()` keeps its idempotency and
   revert-to-live logic (`src/AutoSave/AutoSaveManager.php:215-290`) and then
   calls `$entity->save()` synchronously. Content becomes a tracked pending
   revision; component-tree config entities, code components, asset
   libraries, brand kits and staged config updates become `workspace_config`
   rows. The only Canvas-specific write-time code is: stamp revision user and
   time (`WorkspaceAutoSave::stampAutoSaveWorkspaceRevisionMetadata()`,
   keep), delete the previously tracked revision of the same entity in the
   same workspace (latest-only retention, about ten lines), and record the
   client instance id and the verbatim draft `path` value in one small
   key-value collection keyed by the auto-save key. Side effects of asset
   entity saves (`CanvasAssetStorage::doSave()` file generation, the
   `library_info` invalidation in `JavaScriptComponent::postSave()`,
   `AssetLibrary::postSave()`, `BrandKit::postSave()`) are skipped while a
   workspace is active; `workspace_config` applies the config to Live outside
   any workspace at publish (`WorkspaceConfigPublishingSubscriber.php:166`), so
   the side effects run exactly once, at publish.

3. **Read path.** `getAutoSaveEntity($entity)` loads the entity in the active
   workspace (core negotiation already returns the staged revision or staged
   config), loads the Live copy once per request outside the workspace, and
   compares the normalized translation against the Live translation. Equal
   means "no draft". Configuration created inside the workspace with no Live
   copy is always a draft and reports a constant starting point.

4. **Pending list.** `WorkspaceTrackerInterface::getTrackedEntities()` for
   content plus the `workspace_config` rows for config, each run through the
   same comparison. This is what
   `WorkspaceAutoSave::appendWorkspaceTrackedContentEntities()` and
   `::appendStagedWorkspaceConfig()` already do; they become the only path.

5. **Discard.** `RevisionableStorageInterface::deleteRevision()` on the
   tracked revisions (no workspace switch needed; core's own
   `EntityOperations::entityDelete()` calls the tracker without switching),
   plus the existing `discardWorkspaceStagedConfig()` logic for config.

6. **Publish.** The controller calls `$workspace->publish()`. A Canvas
   `WorkspacePrePublishEvent` subscriber at priority 100 validates every
   tracked content entity (`validate()` plus recorded form violations) and
   every tracked config object (typed data), checks per-item update access,
   and throws `WorkspacePublishValidationException` when anything fails. Core
   does not catch exceptions thrown by subscribers
   (`WorkspacePublisher::publish()`, lines 56-62), so this gates every surface
   (Canvas API, core Workspaces UI, cron) uniformly, which the branch only
   achieves for the Canvas API today.

7. **Conflicts.** Concurrent tabs: unchanged (`AutoSaveValidateTrait`, hash
   plus starting point plus client id). External edits: either keep core's
   `EntityWorkspaceConflict` lock untouched (an entity with a Canvas draft
   cannot be saved through a form or JSON:API outside its workspace, which ADR
   0014 consequence 6 already accepted), or detect them at publish with core's
   `WorkspacePublisherInterface::getDifferringRevisionIdsOnTarget()`
   (`WorkspacePublisher.php:147-174`). Either option removes the stored
   `original_hash`, `conflict_id`, `resolveConflict()`, the retention
   sidecar and the `canvas_dev_cd` flag.

8. **Update path.** One `canvas_update_N()` (enable both modules, make
   `canvas_page.owner` revisionable, create the Main workspace on the default
   provider, map permissions) and one batched `canvas_post_update_N()` that
   reads `canvas.auto_save` rows and saves each reconstructed entity inside
   `canvas_default` while switched to user 1 through the account switcher
   (`administer workspaces` passes `WorkspaceProviderBase::checkAccess()`,
   lines 48-50, so no provider hack is needed on web `update.php`).

9. **`canvas_workflows`.** Unchanged in function. Its coupling to `canvas`
   shrinks to `hook_canvas_workspace_normalize_alter()` and
   `hook_entity_presave()`; the staged-write hook and the publish-time latch
   disappear with the stores that needed them.

## 2. Component verdicts

| Component | Verdict | Reason | Risk of the verdict |
|---|---|---|---|
| `src/Entity/CanvasAutoSaveSnapshot.php`, `src/Entity/Storage/CanvasAutoSaveSnapshotStorageSchema.php`, `src/AutoSave/Workspace/AutoSaveSnapshotRepository.php` (348 lines) | **Remove** | Three uses: (a) config entity types that are not `ComponentTreeConfigEntityBase` (`WorkspaceAutoSave.php:566-569`), which `workspace_config` already lists as workspace-safe (`WorkspaceConfigSchemaHooks.php:46-54`); the stated reason is save side effects, which are gated in one place (section 1, item 2). (b) Content the storage layer rejects. The only test case is `entity_test`, a non-revisionable type core refuses (`WorkspaceAutoSaveStagingTest.php:166-195`); Canvas does not expose such hosts. A genuine SQL rejection (for example a 300-character title in a `varchar(255)` column) is a per-write error; returning 422 and keeping the previous draft is simpler than a second storage system. (c) Invalid payloads: a myth, since storage does not validate. With it go `hasSnapshotStaging()`, `loadStagedEntitiesOfType()`, snapshot staging at publish, `canvas_post_update_0033`, `workspaceHasSnapshotRows()` and the pre-publish snapshot gate. | A storage-rejected write becomes a visible error instead of a silent retention. Needs a human decision (open question 1). |
| `src/AutoSave/Workspace/PendingContentAutoSaveBuffer.php` (83) and `DeferredAutoSaveFlusher.php` (226) | **Remove** | The deferral never reaches kernel terminate on the routes it was built for: `ApiLayoutController::get()`, `::patch()` and `::post()` all call `getAutoSaveHashesAfterFlush()` before responding (`ApiLayoutController.php:162,443,543,555-558`), and `ApiContentAutoSaveControllers::patch()` flushes at line 130. So every content auto-save is a key-value write, a lock acquisition, a key-value read and a reconstruction, followed by the synchronous entity save it was meant to avoid. The buffer also doubles as the metadata sidecar (client id, `original_hash`, draft path, conflict retention, `config_base_hash`); that role moves to one small map. Spec task 2.6 ("verify PATCH latency does not regress") is still unchecked. | None for latency (the write is already synchronous). Config drafts on `canvas.api.*` routes are the one path that currently defers; they would gain one config save per PATCH, which is what the Live path did before the branch. |
| `src/AutoSave/Workspace/AutoSaveRevisionPruner.php` (120) plus key-value state plus 154 test lines | **Simplify** to latest-only | Log-spaced history is listed in ADR 0014 as a bonus ("+TO An auto-save history exists"); the key-value era kept one snapshot. Keeping only the newest tracked revision is one `getTrackedEntities()` call before save and one `deleteRevision()` after, inside the persist. No bookkeeping, no switch (`deleteRevisionInWorkspace()` at line 110-118 switches for no reason). | Loses recovery of earlier drafts, which no UI exposes. |
| `CanvasServiceProvider::registerWorkspaceInvariantKeyValueFactory()` (`src/CanvasServiceProvider.php:138-180`), `STAGING_KEY_VALUE_SERVICE` autowiring in six services, `tests/src/Kernel/Traits/CanvasWorkspaceConfigTestTrait.php`, `WorkspaceInvariantStagingStoreTest.php`, the rewrites in `canvas_post_update_0010/0026/0031/0034` | **Remove** | The premise ("workspace_config decorates keyvalue so that every collection becomes a per-workspace overlay", comment at lines 147-152) stopped being true in `workspace_config` commit `a670b91` (2026-08-03): the overlay now applies only to `config.entity.key_store.*`, `entity.definitions.bundle_field_map` and collections added through `hook_workspace_config_key_value_collections_alter()` (`WorkspaceConfigKeyValueInformation.php:30-43`; its doc says "Pass-through is the default"). Canvas's own test already records this (`WorkspaceInvariantStagingStoreTest.php:53-58`). No Canvas collection is overlaid. | Requires pinning `drupal/workspace_config` at or after `a670b91`; `composer.json` currently says `^1.0@dev`, which does not pin. |
| Hash-based dirty state (`WorkspaceAutoSave::loadWorkspaceStagedContentAutoSave()` lines 457-501, `appendWorkspaceTrackedContentEntities()` lines 1134-1208, `loadWorkspaceStagedConfigAutoSave()` lines 889-914) | **Keep the comparison, simplify the code** | "Tracked in the workspace" cannot replace it: a workspace revision carries every translation, and `revision_translation_affected` means "changed since the previous revision", not "differs from Live" (`ContentEntityStorageBase.php:1168-1176`). The Live comparison is the only thing that implements per-translation drafts and the spec's "undo back to live values" scenario. But it is implemented twice (per-entity and per-list) with different helpers, and the per-list variant loads Live inside a workspace switch per entity. One helper, Live loaded once per request. | None. |
| `CONFIG_BASE_HASH_KEY` / `ensureConfigBaseRecorded()` (`WorkspaceAutoSave.php:59-69, 945-977`), spec D7 | **Remove** | Config created inside a workspace has no Live copy; "reverted to the original" has no meaning for it. Report it as always pending and give it a constant starting point. The branch records the first staged copy as a fake base only to keep `autoSaveStartingPoint` stable, which a constant also does. | Discarding a never-published template deletes it (already the behavior of `discardWorkspaceStagedConfig()` at line 1457-1462). |
| External-edit conflict detection: `original_hash`, `AUTO_SAVE_CONFLICT_KEY`, `getUnresolvedConflict()`, `resolveConflict()`, `advanceStagedEntryOriginalHash()`, `ConflictResolutionOutcomeEnum`, `conflictViolation()` in the publisher, `canvas_dev_cd` | **Remove** (needs decision) | Exists only because `CanvasAwareEntityWorkspaceConflictConstraintValidator` (lines 42-50) exempts Live saves of Main-tracked entities from core's lock. Keep core's lock and the problem disappears for every validated save. Programmatic Live saves (Drush, config sync) bypass validation either way; for those, core already computes the answer in `getDifferringRevisionIdsOnTarget()` (`WorkspacePublisher.php:147-174`), which a pre-publish subscriber can call to refuse the publish. Note `checkConflictsOnTarget()` is a no-op in core (lines 138-142), so without that check core would overwrite the Live edit. | Editors lose "edit a Canvas-drafted page in the node form" until the draft is published or discarded (ADR 0014 consequence 6 already accepted this). Open question 2. |
| `CanvasAwareEntityChangedConstraint*` (115 lines) and the `changed` injection in `ClientDataToEntityConverter.php:198-225, 376-384` | **Remove the constraint override, keep the converter change** | Two fixes for one race. The converter's request-time `changed` already makes core's comparison pass ("the request time can never be behind a flush", line 212), and with the flusher gone the cross-request flush race is the ordinary two-tabs case that `validateAutoSaves()` handles. | None identified. |
| `WorkspaceAutoSaveHooks::entityTypeBuild()` (lines 23-43) marking every `canvas*` config entity type workspace-ignored | **Simplify** | `workspace_config` already sets `IgnoredWorkspaceHandler` on every managed config entity type (`WorkspaceConfigEntityHooks.php:49-67`). Canvas only needs to add its unlisted types (`canvas.color.*`, segments, page variants, overrides) to the safe list through the alter hook it already implements (line 56-59), so they stage like the rest. | None. |
| `WorkspaceConfigEntityPersist.php` (158) and `WorkspaceContentEntityPersist.php` (72) | **Remove** | Both exist to catch exceptions and fall back to snapshots, and to maintain the metadata sidecar. With snapshots and the sidecar gone, each is `$entity->save()` plus the latest-only prune. The `isStagingWrite()` latch (lines 34-67) is needed only because `AutoSaveManager::onCanvasConfigEntitySave()` cannot tell a staged write from an outside edit; with staging being the config save itself, that listener ignores saves made inside a workspace (it already does at lines 1153-1159) and the latch is unnecessary. | None. |
| `WorkspaceAutoSave::executeInWorkspaceUnchecked()` / `executeInStagingWorkspaceUnchecked()` (lines 139-146, 239-293), `isUncheckedSwitchInto()`, the `view` grant in `WorkspaceAutoSaveRevisionHooks::workspaceAccess()` (lines 74-83) | **Remove** | Grants the current user temporary view access so bookkeeping can switch into a workspace. None of the callers need to switch: discarding revisions is `deleteRevision()` (core's `EntityOperations::entityDelete()` at lines 124-133 does the equivalent with no switch), lock info only needs the tracked revision id, and the pending list runs in the already-active workspace. Of the 34 switch call sites in `src/`, 19 are in this file. | None. |
| `ComponentSourceManager::generateComponents()` reentrancy guard and `executeOutsideWorkspace()` (`src/ComponentSource/ComponentSourceManager.php:53-63, 104-110, 150-192`); `BlockManagerDecorator::clearCachedDefinitions()` memo (`src/Block/BlockManagerDecorator.php:28-74`) | **Keep (small), but the cause is upstream and in Canvas's switch count** | The cascade is real: every `doSwitchWorkspace()` dispatches `WorkspaceSwitchEvent` (`WorkspaceManager.php:161-162`), `workspace_config` reacts by calling `EntityFieldManager::clearCachedFieldDefinitions()` on every switch (`WorkspaceConfigSubscriber.php:25-34`), Layout Builder reacts to that by clearing block plugin definitions, and Canvas regenerates Components. Running generation outside the workspace is correct (generated Components must never stage). The guard is 10 lines. The frequency problem is Canvas's own: cutting switches from 34 to the handful that must load Live makes the memo mostly moot. File an upstream `workspace_config` issue: clearing field definitions on every switch is far heavier than the field map change it protects against. | None. |
| `src/Workspace/CanvasWorkspacePublisher.php` (291) and the publish-time staging latch (`WorkspaceAutoSave::isPublishTimeStaging()` / `executePublishTimeStaging()`, lines 146-182) | **Replace** with a pre-publish subscriber (about 120 lines) and a direct `$workspace->publish()` in the controller | Of its five steps, flush buffers and stage snapshots vanish with those stores; `finalizeWorkspaceStagedConfig()` exists for the "content templates are created disabled" rule that spec task 2.10 already plans to retire; `stageLanguageOverrides()` goes if overrides stage natively (open question 4). Validation and access checks stay but move into `WorkspacePrePublishEvent` so core UI and cron publishes are validated too (today they are not: only the snapshot gate runs for them, `AutoSaveWorkspacePublishSubscriber.php:50-68`). The outer transaction (lines 146-164) is worth keeping as five lines in the controller: core's revision promotion has its own transaction, `workspace_config` rolls config back to a checkpoint, but nothing makes the two atomic together. | Core wraps a stopped publish's reason as a string (`WorkspacePublisher.php:60-62`); structured violations must travel as an exception thrown from the subscriber, which core does not catch. Verified at lines 56-62. |
| `AutoSaveWorkspacePublishSubscriber.php` (84) | **Simplify** | Pre-publish gate goes with snapshots. Post-publish cleanup shrinks to clearing the metadata map and form violations, and deleting a named workspace. | None. |
| `hook_canvas_workspace_staged_write()` (`canvas.api.php:145-165`, `AutoSaveManager::invokeStagedWriteHook()` lines 317-326) | **Remove** | Documented purpose: cover "snapshot row, deferred buffer row, or key-value entry" writes that do not pass through an entity save. Once every staged write is an entity save, `canvas_workflows` already handles it in `hook_entity_presave()` (`CanvasWorkflowsHooks.php:59-81`). | None. |
| `hook_canvas_workspace_normalize_alter()`, `WorkspaceNormalizer`, `ApiWorkspaceController` | **Keep** | Thin, correct wrappers over core. `countPendingChanges()` drops its snapshot count. | None. |
| `WorkspaceAutoSave::migrateStagingKey()` (lines 701-805) and `AutoSaveManager::migrateLangcode()` | **Simplify** | Two thirds of it re-keys buffer rows and snapshot rows. What remains is re-staging the revision under the new langcode and moving the client id and form violations. | None. |
| Draft `path` recording (`DRAFT_PATH_KEY`, `applyRecordedDraftPath()`, lines 638-665) and dependent `path_alias` discard (lines 1377-1416) | **Keep** | Real core limitation: the computed `path` field resolves through alias storage, and a draft that cleared its alias cannot express that as a revision. Small, and the recorded value fits in the metadata map. Core issue worth filing: workspace-aware alias resolution for cleared aliases. | None. |
| `LegacyAutoSaveMigrator.php` (63), `CanvasWorkspaceProvider.php` (84), `canvas_update_11201/11202/11203`, `canvas_post_update_0031/0032/0033/0034` (`canvas.install:95-192`, `canvas.post_update.php:293-508`), `CanvasWorkspacesAutoSaveUpdate11201Test.php` | **Replace** with one update hook and one post-update | These encode the abandoned phased shipping: 11201 installs the snapshot schema and a workspace on a legacy provider so 0031 can switch into it from web `update.php`; 0032 flips the provider and relabels; 11202 adds a `workspace` column to snapshot rows and re-prefixes five key-value collections; 0033 promotes snapshot rows into `workspace_config`; 0034 drains what 0031 left. None of that history exists on any site (ADR amendment: "Phase 1 (MR 1056) has not merged"). `CanvasWorkspaceProvider` exists only to grant `view` during `update.php`; switching to user 1 through `AccountSwitcherInterface` achieves the same without a provider. | One straight migration must still handle the pre-1.0 rows without an `id` that `importLegacyArray()` guards against (lines 509-528); carry those twelve lines. |
| `modules/canvas_workflows` (about 1,100 source lines, 347 test lines) | **Keep**, reduce coupling | Already optional and already split. Core has no workspace review; building on core Workflows is the right call and the per-transition permissions mirror `content_moderation`. Depends on three `canvas` extension points; after this cut it needs one (`hook_canvas_workspace_normalize_alter()`) plus the ordinary `hook_entity_presave()`. `demoteOnStagedWrite()` loses its `isPublishTimeStaging()` check because core's publish promotes entities with `setSyncing(TRUE)` (`WorkspacePublisher.php:84`), which the presave hook already skips (`CanvasWorkflowsHooks.php:65`). | None. |
| `CanvasAwareEntityWorkspaceConflictConstraint*` (92) | **Remove** if open question 2 is answered "keep core's lock"; otherwise keep | See the conflict-detection row. | See open question 2. |
| UI: `ui/src/components/workspaces/*`, `ui/src/components/review/*`, `ui/src/services/workspacesApi.ts` (+1,548/−435) | **Keep** (skimmed) | The API surface they consume (list/create/activate/delete, review transitions, schedule, `lockedInWorkspace`, whole-workspace publish) survives this cut unchanged. The `PublishReview.conflict` flow shrinks or disappears with open question 2. | None beyond the conflict UI. |

## 3. Estimated reduction

Branch additions in the staging and publish layer (from `git diff --numstat`):

| Area | Added now | After this cut |
|---|---|---|
| `src/AutoSave/Workspace/*` | 2,649 | about 500 (one `WorkspaceAutoSave`: persist, load, list, discard, delete, lock info, langcode re-key, draft path) |
| `src/Workspace/*` | 456 | about 200 (normalizer, two exceptions, pre-publish validator) |
| Snapshot entity and schema | 180 | 0 |
| Publish subscriber, hooks, constraint overrides, service provider | 445 | about 120 |
| `canvas.install` and `canvas.post_update.php` additions | 412 | about 150 |
| `AutoSaveManager.php` delta (+255/−207) | 255 | about 120 (drops conflict resolution, staged-write hook, sidecar lookups) |
| `ComponentSourceManager`, `BlockManagerDecorator` | 111 | 111 |
| **Back-end staging subtotal** | **about 4,500** | **about 1,200** |
| Tests that only exist for removed parts (`AutoSaveRevisionPrunerTest`, `DeferredAutoSaveFlusherTest`, `WorkspaceInvariantStagingStoreTest`, `CanvasWorkspaceConfigTestTrait`, `CanvasWorkspacesAutoSaveUpdate11201Test`, the snapshot cases in `WorkspaceAutoSaveStagingTest`) | about 720 | 0 |

Net: roughly **3,300 fewer source lines and 700 fewer test lines**, about a
quarter of the whole branch and about two thirds of its back-end staging
code. `canvas_workflows`, the controllers, the UI and the ADRs are mostly
untouched; the ADRs need the amendments implied by the verdicts (no snapshot
store, no deferred buffer, no log-spaced history, uniform pre-publish
validation).

Not counted: `src/AutoSave/Workspace/WorkspaceAutoSave.php` holds 19 of the
34 workspace switch call sites in `src/`; the cut leaves about six
(`executeOutsideWorkspace()` for Live loads, component generation, content and
translation deletes, the publish transaction).

## 4. Open questions that need a human decision

1. **May a storage-rejected auto-save return an error?** The spec says "no
   auto-save may be refused". The only real cases are SQL-level rejections
   (column length, type) for content; config storage rejects nothing it can
   serialize. If a 422 for that one write (previous draft intact, client
   keeps its state) is acceptable, the snapshot entity, both persist services
   and the snapshot gate go. If not, the snapshot entity stays as the one
   fallback and the rest of the cut still applies.

2. **Lock or detect?** Keep core's `EntityWorkspaceConflict` lock (a page with
   a Canvas draft cannot be saved in the node form or via JSON:API until the
   draft is published or discarded; ADR 0014 consequence 6 accepted this),
   or keep the Main-workspace exemption and with it hash-based external-edit
   detection, `resolveConflict()` and the conflict UI. The first removes
   roughly 400 lines across `AutoSaveManager`, `WorkspaceAutoSave`, the
   constraint override and the UI's conflict flow. Programmatic Live saves
   are detectable at publish either way through
   `getDifferringRevisionIdsOnTarget()`.

3. **Asset side effects inside a workspace.** Gating
   `CanvasAssetStorage::doSave()`, the `library_info` invalidations and the
   brand kit font usage sync on `hasActiveWorkspace()` means drafts of code
   components, asset libraries and brand kits stage as `workspace_config` rows
   like everything else, and the draft preview keeps reading compiled strings
   from the entity (`ApiConfigAutoSaveControllers::getCss()/getJs()`, lines
   50-72). Confirm there is no consumer that needs draft asset files on disk.

4. **`StagedLanguageConfigOverride` and `StagedConfigUpdate`.**
   `workspace_config` supports config collections (`collection` column,
   `createCollection()`, `WorkspaceConfigDatabaseStorage.php:412-420`), so
   language overrides written inside a workspace stage natively; and a
   `StagedConfigUpdate` applied inside a workspace stages its target config
   natively. Both entity types exist because there was no config staging.
   Retiring them is a larger change than this review covers but removes
   `stageLanguageOverrides()`, `groupConfigEntityAutoSaves()` and the
   `StagedConfigEntityStorageTrait` indirection. Decide whether that is in
   scope for 2.x.

5. **Content templates "created disabled".** Spec task 2.10 defers retiring
   it. With staged creation it guards nothing, and keeping it costs
   `finalizeWorkspaceStagedConfig()`, `isUnpublishedWorkspaceCreation()` in
   the view builder and the `workspace` cache context on templated renders.
   Recommend retiring it in the same 2.x cut.

6. **Pin `drupal/workspace_config`.** The invariant key-value factory is only
   removable with `workspace_config` at or after commit `a670b91`
   (2026-08-03). `^1.0@dev` does not guarantee that; pin a tagged release or
   a commit in `composer.json`.

7. **Upstream issues to file** (not blockers, but they drive two of the
   remaining workarounds): `workspace_config` clearing entity field
   definitions on every workspace switch
   (`WorkspaceConfigSubscriber.php:25-34`); core's computed `path` field not
   representing a cleared alias on a pending revision.
