import { format } from 'date-fns';

import { getCanvasSettings } from '@/utils/drupal-globals';

// Whether the canvas_workflows module is installed: workspaces then carry a
// review state and may be scheduled for publishing, and the corresponding
// controls are shown.
export const isWorkspaceWorkflowsEnabled = (): boolean =>
  getCanvasSettings()?.workflowsExtensionAvailable === true;

// Formats a Unix timestamp (seconds) for scheduled publish labels.
export const formatScheduledDate = (timestamp: number) =>
  format(new Date(timestamp * 1000), 'MMM d, yyyy h:mm a');
