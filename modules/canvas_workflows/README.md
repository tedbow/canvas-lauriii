# Drupal Canvas Workflows

Adds a review workflow and scheduled publishing to [Drupal Canvas](https://www.drupal.org/project/canvas)
workspaces. Without this module, a workspace publishes as soon as someone with publish access asks for it.

## What it adds

- **Review workflow.** Each workspace carries a review state of a workflow of the `canvas_workspace_review`
  type. States flag whether they are *approved for publishing*; the workflow's initial state is where staged
  writes send a workspace back to. The module ships a default workflow (Draft → In review → Approved with
  *Send for review*, *Approve* and *Send back to draft* transitions). Reshape it, or define more, at
  `/admin/config/workflow/workflows`; point a workspace at a workflow through its *Review workflow* field.
- **Per-transition permissions.** Every transition of every review workflow gets a
  `use {workflow} transition {transition}` permission, as in Content Moderation.
- **Publish gate.** A workspace whose *Require review before publishing* field is set cannot be published
  (from Canvas, the core Workspaces UI, or cron) until it sits in an approved state. Named workspaces require
  review by default; the Main workspace never does. Any staged write into a workspace beyond the initial state
  demotes it and cancels its schedule.
- **Scheduled publishing.** A publisher can schedule an approved workspace; cron publishes it through the
  same validated pipeline as the publish button, on the scheduler's behalf. A failure cancels the schedule and
  records the error on the workspace.
- **Editor UI.** The Canvas editor shows the review badge, transition buttons, and schedule controls when this
  module is installed (`drupalSettings.canvas.workflowsExtensionAvailable`).

## How it plugs into Canvas

- `hook_entity_base_field_info()` adds the review and schedule base fields to the workspace entity.
- `hook_canvas_workspace_staged_write()` and `hook_entity_presave()` demote the review state on staged writes.
- `hook_canvas_workspace_normalize_alter()` adds the review and schedule keys to the workspace API
  representation; the `status` and `schedule` endpoints are documented in `openapi.yml` here.
- A `WorkspacePrePublishEvent` subscriber enforces the review gate; a `WorkspacePostPublishEvent` subscriber
  resets the Main workspace after a publish.

## Uninstalling

Core refuses to uninstall a module whose base fields hold data. The Main workspace stores none, but named
workspaces store their *Require review* flag and review state: publish or delete them before uninstalling.
Workspaces that existed before the module was installed store no value and do not require review until the
flag is set on the workspace form.
