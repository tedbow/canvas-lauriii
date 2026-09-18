import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import PublishReview from '@/components/review/PublishReview';

import type React from 'react';
import type { UnpublishedChange } from '@/types/Review';

let conflictUxEnabled = true;

vi.mock('@/features/conflict/conflictUtils', () => ({
  isConflictUxEnabled: () => conflictUxEnabled,
}));

vi.mock('@/components/PermissionCheck', () => ({
  default: ({ children }: any) => <>{children}</>,
}));

const baseChange: UnpublishedChange = {
  pointer: 'canvas_page:1:en',
  label: 'Page 1',
  updated: 1_777_000_000,
  entity_type: 'canvas_page',
  data_hash: 'hash-1',
  entity_id: 1,
  langcode: 'en',
  owner: {
    name: 'Editor',
    avatar: null,
    id: 2,
    uri: '/user/2',
  },
};

const renderReview = (
  changes: UnpublishedChange[],
  overrides: Partial<React.ComponentProps<typeof PublishReview>> = {},
) => {
  const store = makeStore();
  const props = {
    changes,
    conflictCount: changes.filter((change) => change.hasConflict).length,
    errors: undefined,
    onOpenChangeCallback: vi.fn(),
    onPublishClick: vi.fn(),
    onDiscardClick: vi.fn(),
    onResolveConflict: vi.fn(),
    onViewClick: vi.fn(),
    isViewChangeAvailable: (change: UnpublishedChange) =>
      change.entity_type === 'canvas_page' && !change.hasConflict,
    isPublishing: false,
    isDiscarding: false,
    isUpdating: false,
    ...overrides,
  };

  const result = render(
    <AppWrapper store={store} location="/" path="*">
      <PublishReview {...props} />
    </AppWrapper>,
  );

  return { ...result, props };
};

describe('PublishReview conflict UI', () => {
  beforeEach(() => {
    conflictUxEnabled = true;
  });

  it('marks conflicted rows and counts them in the banner', async () => {
    const user = userEvent.setup();
    renderReview([
      baseChange,
      {
        ...baseChange,
        pointer: 'canvas_page:2:en',
        label: 'Page 2',
        entity_id: 2,
        hasConflict: true,
      },
    ]);

    await user.click(screen.getByTestId('canvas-publish-review'));

    expect(screen.getByTestId('conflict-banner')).toHaveTextContent(
      '1 conflict to resolve',
    );
    expect(screen.getByTestId('change-conflict-icon')).toBeInTheDocument();
    expect(screen.getByText('2 unpublished changes')).toBeInTheDocument();
  });

  it('shows the published state once the pending changes clear after publishing', async () => {
    const user = userEvent.setup();
    const { props, rerender } = renderReview([baseChange]);

    await user.click(screen.getByTestId('canvas-publish-review'));
    await user.click(screen.getByRole('button', { name: 'Publish now' }));

    expect(props.onPublishClick).toHaveBeenCalledWith();

    const store = makeStore();
    rerender(
      <AppWrapper store={store} location="/" path="*">
        <PublishReview {...props} changes={[]} conflictCount={0} />
      </AppWrapper>,
    );

    expect(screen.getByRole('button', { name: 'Published' })).toBeDisabled();
    expect(screen.getByText('All changes published!')).toBeInTheDocument();
  });

  it('closes the review and resolves the first conflicted row from the banner', async () => {
    const user = userEvent.setup();
    const conflictedChange = {
      ...baseChange,
      pointer: 'canvas_page:2:en',
      label: 'Page 2',
      entity_id: 2,
      hasConflict: true,
    };
    const { props } = renderReview([baseChange, conflictedChange]);

    await user.click(screen.getByTestId('canvas-publish-review'));
    await user.click(
      screen.getByRole('button', { name: 'Resolve 1 conflict' }),
    );

    expect(props.onResolveConflict).toHaveBeenCalledWith(conflictedChange);
    expect(props.onOpenChangeCallback).toHaveBeenLastCalledWith(false);
    await waitFor(() => {
      expect(
        screen.queryByTestId('canvas-publish-reviews-content'),
      ).not.toBeInTheDocument();
    });
  });

  it('treats conflicted pending changes as normal rows when conflict UX is disabled', async () => {
    const user = userEvent.setup();
    conflictUxEnabled = false;
    renderReview([{ ...baseChange, hasConflict: true }]);

    await user.click(screen.getByTestId('canvas-publish-review'));

    expect(screen.queryByTestId('conflict-banner')).not.toBeInTheDocument();
    expect(
      screen.queryByTestId('change-conflict-icon'),
    ).not.toBeInTheDocument();
    expect(screen.getByText('1 unpublished change')).toBeInTheDocument();
  });

  it('opens the side-by-side review for a viewable change from the row menu', async () => {
    const user = userEvent.setup();
    const { props } = renderReview([
      baseChange,
      {
        ...baseChange,
        pointer: 'js_component:hero:en',
        label: 'Hero',
        entity_id: 'hero',
        entity_type: 'js_component',
      },
    ]);

    await user.click(screen.getByTestId('canvas-publish-review'));

    const [pageMenu, heroMenu] = screen.getAllByRole('button', {
      name: 'More options',
    });

    // The JS component is not viewable per `isViewChangeAvailable`.
    await user.click(heroMenu);
    expect(
      screen.queryByRole('menuitem', { name: 'Review changes' }),
    ).not.toBeInTheDocument();
    await user.keyboard('{Escape}');

    await user.click(pageMenu);
    await user.click(screen.getByRole('menuitem', { name: 'Review changes' }));

    expect(props.onViewClick).toHaveBeenCalledWith(baseChange);
  });

  it('hides the side-by-side review action when conflict UX is disabled', async () => {
    const user = userEvent.setup();
    conflictUxEnabled = false;
    renderReview([baseChange]);

    await user.click(screen.getByTestId('canvas-publish-review'));
    await user.click(screen.getByRole('button', { name: 'More options' }));

    expect(
      screen.queryByRole('menuitem', { name: 'Review changes' }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByRole('menuitem', { name: 'Discard changes' }),
    ).toBeInTheDocument();
  });
});
