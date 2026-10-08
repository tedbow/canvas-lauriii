# Comparison: `workspaces-split` vs `workspaces-simplify`

Two branches integrate Canvas auto-save with core Workspaces and
`workspace_config`. Both fork from the 1.x merge base `7c871000`.

- **A** = `workspaces-split` at `cdb46dbfe` (2026-10-07 17:17).
- **B** = `workspaces-simplify` at `02012b4a`. B applies the plan in
  [`workspace-simplification-review.md`](workspace-simplification-review.md)
  to A.

This document records what each branch does, verified by reading the code
on 2026-10-08, and where B's review document or ADR 0017 describe behavior
the code does not have. Line numbers refer to the files at the commits
above. Core is Drupal 11.3.10; `workspace_config` is at `d32a425` in both
sites.

## 1. Fork point

B does not contain A's last 13 commits. B's `26283d6a` ("Collapse the update
path", 14:46) is A's `b11f5c0d4`. A's commits after it, absent from B:

| A commit | Change | Effect of its absence in B |
|---|---|---|
| `3ebc8e795` | Load staged and base config entities override-free (`loadOverrideFree()`, A `src/AutoSave/Workspace/WorkspaceAutoSave.php:1746-1765`). | B uses `loadUnchanged()` / `loadConfigEntityByName()` (B `WorkspaceAutoSave.php:908, 1131, 1446, 1514`). In a non-default-language request the language override is read as the base config. |
| `d798d06d1` | Skip symmetric synchronization when a non-default translation is saved as a draft (A `WorkspaceContentEntityPersist.php:47-67`, `ComponentTreeFieldSymmetricalTranslationSynchronizer.php:46-49`). | B's synchronizer has no guard (B same file `:40-45`); the `save()` in `persistContentEntity()` (B `:599`) lets `content_translation` presave re-sync a non-default translation draft from the default translation. |
| `ef662f77a` | Translation delete drops only that translation from the staged revision (A `WorkspaceAutoSave.php:1468-1504`). | B's `AutoSaveHooks::entityTranslationDelete()` (B `:54-62`) calls `delete($translation)` → `deleteEntity()` → `discardTrackedRevisions()` for the whole entity (B `WorkspaceAutoSave.php:1293-1305, 1242-1255`). Sibling translations' drafts are discarded. |
| `cdb46dbfe` | `canvas_update_11201()` re-keys `canvas.form_violations` and `canvas.component_instance_form_violations` under `canvas_default:` (A `canvas.install:224-238`, tested in `CanvasWorkspacesAutoSaveUpdate11201Test:71-85`). | B reads workspace-prefixed keys (`AutoSaveManager::getAutoSaveKey()` B `:482-494`) and never migrates the 1.x rows; they become unreachable after the update. |
| `bacce853b` | Translation tests aligned with whole-workspace publishing. | B's `ApiAutoSaveControllerTranslationTest::testPublishingNonDefaultTranslationPreservesDefaultTranslation` still expects 403 `UnexpectedItemInPublishRequest`; A expects 200. |
| `e1f17eabb` | Drop the duplicated `workspaceManager()` accessor. | B `src/Controller/ApiConfigControllers.php:80-82` keeps it. |
| `962400b2b`, `8855a523f`, `fa47d75cc`, `c783177dd`, `a4bb32b99` | Test provisioning fixes. | Partly N/A (snapshot, buffer); the rest port as is. |
| `92e0ff773` | Flush buffered translation rows. | N/A: B has no buffer. |

None of these depend on A's storage design. All port to B.

## 2. Staging model

| | A | B |
|---|---|---|
| Stores | Pending revision, `workspace_config` row, `canvas_auto_save_snapshot` entity, `PendingContentAutoSaveBuffer` key-value row (also the metadata sidecar), `AutoSaveRevisionPruner` key-value state. | Pending revision, `workspace_config` row, `AutoSaveFallbackStore` key-value row (`canvas.auto_save.{workspace}`, 1.x row shape), metadata collection `canvas.auto_save_meta.{workspace}`. |
| Content write | `WorkspaceContentEntityPersist::persist()` (A `:51-93`): `save()` in the workspace, `recordAndPrune()`, delete shadowing snapshot; any `\Throwable` → snapshot. On `canvas.api.*` routes the write is enqueued in the buffer first (`shouldDeferContentPersistToTerminate()` A `WorkspaceAutoSave.php:868-875`). | `persistContentEntity()`: `save()` in the workspace, `pruneToLatestRevision()`, `recordPrimaryPersist()`; any `\Throwable` → fallback row, plus a placeholder pending revision of the unchanged entity when nothing is tracked yet (`claimEntityForWorkspace()`), so the entity is locked to the workspace from its first auto-save. Always synchronous. |
| Component-tree config write (content template, pattern, page variant) | `WorkspaceConfigEntityPersist::persist()` (A `:84-139`) → `workspace_config` row; exception → snapshot. Deferred on `canvas.api.*` routes. | `persistConfigEntity()` (B `:560-586`) → `workspace_config` row; exception → fallback row. Synchronous. |
| Code component, asset library, brand kit, `StagedConfigUpdate`, `StagedLanguageConfigOverride` | Snapshot entity (A `:608-611`). | Fallback row (B `retainFallbackDraft()` `:676-702`). Review decisions 3 and 4. |
| Write-time switch | `executeInStagingWorkspace()` (user-checked, A `:313-319`). | `executeInWorkspaceUnchecked()` (B `:537`). A user without `view` access to the workspace still writes there. |
| Retention | Log-spaced history (`AutoSaveRevisionPruner`). No API or UI reads it. | Latest only: every previously tracked revision is deleted after the save (B `:637-654`). |
| Content read | Memory cache → buffer → snapshot → `isEntityTrackedInStagingWorkspace()` + unchecked switch in + `executeOutsideWorkspace()` for Live (A `:499-543`). | Memory cache → fallback row → `loadTrackedRevision()` (tracker + `loadRevision()`, no switch) + `executeOutsideWorkspace()` for Live (B `:422-456, 384-401`). |
| Pending list | Snapshots + tracked content (one switch in, then `executeOutsideWorkspace()` per entity, A `:1174-1248`) + staged config + buffer rows. | Fallback rows + tracked content (one unchecked switch, then `loadUnchangedBase()` per entity, B `:1049-1112`) + staged config. The review's "Live loaded once per request" is not implemented. |
| Workspace switch call sites (`src/` + `canvas_workflows` + install) | 38 (26 in `WorkspaceAutoSave.php`). | 27 (12 in `WorkspaceAutoSave.php`). |
| `executeInWorkspaceUnchecked()` + `view` grant in `hook_workspace_access` | Present. | Present and used more widely (staged writes, publish subscriber, pending list). |
| `isPublishTimeStaging()` latch, `hook_canvas_workspace_staged_write()` | Present. | Present: five config entity types still bypass the entity save. |
| Asset side effects for drafts (`CanvasAssetStorage::doSave()`, `JavaScriptComponent::postSave()`, `AssetLibrary::postSave()`, `BrandKit::postSave()`) | Run once, at publish, when the snapshot is saved. | Run once, at publish, when the fallback row is saved. Files are byte-identical in A and B; the review's "skipped while a workspace is active" gate does not exist. |

### Deferred write buffer (A only)

`ApiLayoutController::get()`, `patch()` and `post()` call
`getAutoSaveHashesAfterFlush()` before responding (A `:162, 443, 543`), and
`ApiContentAutoSaveControllers::patch()` flushes at `:130`. On those routes
every content auto-save is a key-value write, a lock acquisition, a key-value
read and a reconstruction, followed by the synchronous entity save. The
deferral reaches kernel terminate only for `ApiConfigAutoSaveControllers::patch()`,
`PageVariant` saves and the translation/override saves in
`ApiLayoutController::buildLayoutAndModel()`; `flushKey()` logs and swallows
their failures outside the request's error handling (A
`DeferredAutoSaveFlusher.php:205-210`).

## 3. Publish

| | A | B |
|---|---|---|
| Entry point | `CanvasWorkspacePublisher::publish()` (A `:84-183`): flush buffers, list, per-item access + conflict + validation, throw `WorkspacePublishValidationException`; then one transaction around `executePublishTimeStaging()` (snapshots saved, content templates enabled) and `$workspace->publish()`. | `CanvasWorkspacePublisher::publish()` (B `:67-94`): transaction, `$workspace->publish()`. |
| Validation and access | Only through the Canvas publisher: Canvas API and cron (`WorkspaceScheduledPublish.php:64`). Core Workspaces UI and direct `$workspace->publish()` run only the snapshot gate (`AutoSaveWorkspacePublishSubscriber:50-68`). | `AutoSaveWorkspacePublishSubscriber::onPrePublish()` (priority 90): validation, per-item access, `stageFallbackDrafts()` for config rows. Uniform across Canvas API, core UI, cron: core does not catch subscriber exceptions (`WorkspacePublisher.php:57-62`). |
| Failure response | 500 with pointer `error`, whole publish rolled back (`ApiAutoSaveControllerTest::testPost`). | 422 with one error per item, pointer = auto-save key. |
| Content templates | Created disabled, enabled at publish (`ContentTemplate::autoSavePublish()`), `isUnpublishedWorkspaceCreation()` in the view builder, `workspace` cache context. | Created enabled; none of the above. |

### Storage-rejected content drafts at publish

A content draft the storage layer refused at save time (most often an
invalid component tree: `ComponentTreeItem::preSave()` validates component
inputs and throws) is not a workspace revision, so core's publish cannot
promote it. Core captures `$tracked_entities` before dispatching
`WorkspacePrePublishEvent` and promotes only that list (core
`WorkspacePublisher.php:56-72`); a revision saved inside the event would be
left untracked and dropped by the post-publish cleanup.

A stages its snapshot rows before `$workspace->publish()` (A `:157-164`), so
core's list includes them; the cost is that non-Canvas publishes must be
refused by the snapshot gate.

B records the storage layer's message on the fallback row
(`AutoSaveFallbackStore::STORAGE_ERROR_KEY`) and, when the entity is not yet
tracked, saves a placeholder pending revision of its unchanged state so
core's tracker — and with it core's lock, Canvas's cross-workspace lock and
the `canvas_workflows` presave demotion — knows the entity from the first
auto-save; the row shadows the placeholder, and the next accepted draft
replaces it through latest-only pruning. `stageFallbackDrafts()` stages
config rows only (`workspace_config` applies them at priority 0). For a
content row, `validateItem()` runs entity validation first — the
per-property violations are the actionable reasons — and, when the draft
validates cleanly, reports the recorded storage message as a per-item
violation. Either way the publish is blocked until the editor re-saves the
draft (which retries the primary store; `AutoSaveManager::saveEntity()`
bypasses its identical-payload no-op while a rejection is recorded) or
discards it. `ApiAutoSaveControllerTest::testPost` covers the refuse, re-save
and publish sequence with the `canvas_force_publish_error` test module's
state toggle.

## 4. Conflicts

Concurrent tabs: identical (`AutoSaveValidateTrait`, no diff).

External edits:

| | A | B |
|---|---|---|
| Core `EntityWorkspaceConflict` lock | `CanvasAwareEntityWorkspaceConflictConstraintValidator` (A `:43-50`) exempts Live saves of entities tracked only in Main. | Core lock unchanged; holds from the first auto-save even when that draft is storage-rejected (placeholder revision, section 3). |
| `EntityChanged` | `CanvasAwareEntityChangedConstraintValidator` compares against Live. | Core compares against the staged revision; `ClientDataToEntityConverter` raises `changed` to request time (B `:225-237`). |
| Detection and resolution | `original_hash` in the buffer sidecar, `getUnresolvedConflict()` (Page only), GET `/auto-saves/pending` 409, `conflictViolation()` at publish, `PATCH ... {resolved_conflict_id}`, `ConflictResolutionOutcomeEnum`, the `/conflict` UI, bell and toast notifications. All active only with `canvas_dev_cd` installed. This is 1.x code; A's diff against the merge base touches none of it. | Removed. `canvas_dev_cd` is `hidden: true, lifecycle: obsolete` and uninstalled by `canvas_update_11201()`. |
| Programmatic Live save (Drush, migrate, `$entity->save()`) | Not validated, not detected unless `canvas_dev_cd` + Page; staged revision promoted over the Live edit at publish (core `checkConflictsOnTarget()` is a no-op). | Same. Review decision 2 says these are refused at publish through `getDifferringRevisionIdsOnTarget()`; nothing in B `src/` or `modules/` calls it. |

User-visible difference in B: an editor whose session is in Live cannot
save the node form or a JSON:API PATCH for an entity with a Canvas draft in
Main ("being edited in the Main workspace") until the draft is published or
discarded. An editor whose session is in Main (the state after opening
Canvas) saves a further pending revision, which Canvas reads as the draft, in
both branches. ADR 0014 consequence 6 accepted this.

B validates client data inside the staging workspace
(`ClientDataToEntityConverter.php:103-109` →
`AutoSaveManager::executeInStagingWorkspace()`) so the kept lock passes for
entities already drafted there. With a workspace active this is a
pass-through; in kernel tests and CLI it is one unchecked switch.

## 5. Update path from 1.x

Both: `canvas_update_11201()` installs `workspaces` and `workspace_config`
and creates the Main workspace; `canvas_update_11202()` makes
`canvas_page.owner` revisionable; `canvas_post_update_0031` batches the 1.x
`canvas.auto_save` rows through `LegacyAutoSaveMigrator`;
`canvas_post_update_0032` maps permissions.

A additionally installs the snapshot entity type and re-keys the two form
violation collections. B additionally uninstalls `canvas_dev_cd` and moves
rows that are not content or component-tree config into
`canvas.auto_save.canvas_default` unchanged.

Neither `deleteAll()` nor `canvas_module_preuninstall()` enumerates other
workspaces: A leaves their snapshot rows, B their `canvas.auto_save.{ws}`
collections. The review's decision 1 ("uninstall enumerates
`collection LIKE 'canvas.auto_save.%'`") is not implemented.

Both branches' `CanvasWorkspacesAutoSaveUpdate11201Test` cannot run locally:
`workspace_config`'s `cache.memory` decorator type-hints
`MemoryCacheInterface`, which `UpdateKernel` does not provide (upstream;
review decision 7).

## 6. `canvas_workflows`

Identical in both except one comment. Depends on `hook_entity_presave`,
`hook_canvas_workspace_normalize_alter`,
`hook_canvas_workspace_staged_write`, `WorkspaceAutoSave::isPublishTimeStaging()`
and `CanvasWorkspacePublisher`. The review's "coupling shrinks to two hooks"
did not happen because decisions 3 and 4 keep five entity types off the
entity-save path.

## 7. UI and HTTP API

Identical in both: `ui/src/components/workspaces/*` (switcher, status badge,
lock notice), whole-workspace publish, review transitions, scheduling, the
side-by-side review page, `ApiWorkspaceController`, `canvas.routing.yml`,
`canvas_workflows.routing.yml`, Cypress tests.

Removed in B (all 1.x code gated on `canvas_dev_cd`): `ui/src/features/conflict/*`,
`ConflictBanner`, conflict state on change rows and groups, the `conflicts`
slice state, the synthetic conflict notification in `NotificationBell` and
`NotificationToastManager` with its `localStorage` fingerprints,
`conflictResolution.spec.ts`, and the Vitest/RTL tests for each.

HTTP API in B: `GET /canvas/api/v0/auto-saves/pending` is always 200;
`PATCH /canvas/api/v0/content/auto-save/{type}/{id}` takes `status` only;
`GET /canvas/api/v0/layout/{type}/{id}?autoSaved=false` is honored without
the dev flag (A honored it only with `canvas_dev_cd`); the `updated` layout
response property is gone; `ErrorCodesEnum` loses codes 4, 5 and 6;
`drupalSettings.canvas.devConflictDetectionMode` is gone. The review page is
gated on `canvas_dev_mode` instead of `canvas_dev_cd`.

## 8. Size

| | A | B |
|---|---|---|
| `src/` lines | 70,429 | 68,415 |
| `WorkspaceAutoSave.php` | 1,767 | 1,517 (the review estimated about 500) |
| `AutoSaveManager.php` | 1,321 | 1,129 |
| `CanvasWorkspacePublisher.php` | 297 | 96 |
| `AutoSaveWorkspacePublishSubscriber.php` | 84 | 193 |
| Classes only in this branch | 15 (1,385 lines): snapshot entity + schema + repository, buffer, flusher, pruner, two persist services, four constraint classes, `ConflictResolutionOutcomeEnum`, `AutoSaveEntityConflictConstraint` | 1 (145 lines): `AutoSaveFallbackStore` |
| Extra services in `canvas.services.yml` | 6 | 1 |
| `ui/src` vs merge base | +1,660 / −441 | +1,717 / −3,542 |

## 9. Tests

Static: tests only in A are `AutoSaveRevisionPrunerTest`,
`DeferredAutoSaveFlusherTest`, `WorkspaceInvariantStagingStoreTest` and
`CanvasWorkspaceConfigTestTrait`; each covers a component B does not have.
B's `WorkspaceAutoSaveStagingTest::testOnlyLatestStagedRevisionIsRetained`
covers the replacement retention rule. The fallback path is exercised through
three throwing fixtures (`canvas_force_publish_error` presave, non-revisionable
`entity_test`, config UUID mismatch), none at the database level.

Covered in B by `ApiAutoSaveControllerTest::testPost`: the core lock
refusing a Live save of a Main-drafted entity (the premise of review
decision 2), asserted on an entity whose only draft was storage-rejected.

Not covered in B: a fallback row written inside a
workspace being readable from Live with the `workspace_config` key-value
overlay active (B's kernel base no longer enables the overlay); the
publish-409 "legacy error" Playwright case; the "All changes published!"
RTL assertion.

Kernel runs on 2026-10-08, same sqlite environment for both:

| Test | A | B |
|---|---|---|
| `AutoSave/WorkspaceAutoSaveStagingTest` | OK, 11 tests, 301 assertions | OK, 10 tests, 259 assertions |
| `AutoSaveManagerTest` | OK, 19 tests, 594 assertions | OK, 15 tests, 400 assertions |
| `ApiLayoutControllerPatchTest` | 1 error, 1 failure (pre-existing, identical) | 1 error, 1 failure (pre-existing, identical) |
| `ApiAutoSaveControllerTest` | not finished | OK, 9 tests, 385 assertions |
| `WorkspaceReviewTest`, `ApiAutoSaveControllerTranslationTest` | not run | not run |

The shared failures are `testInvalid` (data provider cannot load
`TestSetupInterface.php`) and
`testPatchCleansUpOrphanedChildrenOnComponentEvolution` (stale
`workspace_config` cached-storage partition after a Live write; upstream).

## 10. Summary

Capabilities in A and not in B:

1. Live-session editing (node form, JSON:API) of an entity drafted in Main.
2. External-edit conflict detection and resolution (1.x, `canvas_dev_cd`, Page only).
3. The fixes in section 1.
4. Publishing a storage-rejected content draft in the same publish that
   stores it (section 3; B blocks the publish until the draft is re-saved
   or discarded).
5. Staged writes through core's user-checked workspace switch.
6. Snapshot gate refusing non-Canvas publishes.

Capabilities in B and not in A:

1. Validation, form violations and per-item access on every publish surface.
2. Per-item 422 on publish failure instead of a 500 rollback.
3. Content reads, discards and lock lookups without a workspace switch.
4. Latest-only retention with no bookkeeping store.
5. 1.x-shaped fallback rows: the 1.x rows that cannot become revisions move by collection rename.
6. `autoSaved=false` layout reads without a dev flag.
7. `canvas_dev_cd` retired.

Where B is simpler: one write path readable top-down in
`persistStagedEntity()`; two stores plus one metadata collection instead of
five; one validation path; no deferral machinery; no constraint overrides;
no conflict state machine; no snapshot entity type to install and migrate.

Where B moved rather than removed: snapshot rows became fallback rows with
the same lifecycle; the metadata sidecar became `canvas.auto_save_meta.*`;
publisher logic became subscriber logic; the unchecked switch, the
publish-time latch and the staged-write hook remain because five entity
types still stage outside the entity save.

Where B's documents and code disagree: review section 1 items 2, 3 and 9,
decision 1 (uninstall), decision 2 (`getDifferringRevisionIdsOnTarget()`),
and the "about 500 lines" estimate. ADR 0017 repeats the decision 2 claim.

Open items before cutting PRs from B:

1. Port the section 1 commits.
2. Implement the `getDifferringRevisionIdsOnTarget()` pre-publish check or
   remove the claim from the review and ADR 0017.
3. Add a kernel test for fallback-row visibility across the key-value
   overlay.
4. Reconcile the review document and ADR 0017 with the code.
