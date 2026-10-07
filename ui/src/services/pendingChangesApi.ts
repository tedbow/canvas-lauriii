// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';

import {
  setErrors,
  setPreviousPendingChanges,
} from '@/components/review/PublishReview.slice';
import { baseQuery } from '@/services/baseQuery';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

interface Owner {
  name: string;
  avatar: string | null;
  uri: string;
  id: number;
}

export interface PendingChange {
  owner: Owner;
  entity_type: string;
  entity_id: string | number;
  data_hash: string;
  langcode: string;
  label: string;
  updated: number;
}

export type PendingChanges = {
  [x: string]: PendingChange;
};

interface SuccessResponse {
  message: string;
}

export interface ApiErrorEntry {
  code?: number;
  detail: string;
  source: {
    pointer: string;
  };
  meta?: ApiErrorMeta;
}

export interface ApiErrorMeta {
  entity_type?: string;
  entity_id?: string | number;
  label?: string;
}

export interface ErrorResponse {
  errors: Array<ApiErrorEntry>;
}

type DiscardPendingChangeArg = PendingChange & {
  pointer?: string;
};

export enum STATUS_CODE {
  CONFLICT = 409,
  UNPROCESSABLE_ENTITY = 422,
}

export interface PendingChangesResponse {
  data: PendingChanges;
}

// Define a service using a base URL and expected endpoints
export const pendingChangesApi = createApi({
  reducerPath: 'pendingChangesApi',
  baseQuery,
  tagTypes: ['PendingChanges'],
  endpoints: (builder) => ({
    getAllPendingChanges: builder.query<PendingChanges, void>({
      query: () => `/canvas/api/v0/auto-saves/pending`,
      transformResponse: (response: PendingChangesResponse) => response.data,
      providesTags: () => [{ type: 'PendingChanges', id: 'LIST' }],
    }),
    publishAllPendingChanges: builder.mutation<
      SuccessResponse | ErrorResponse,
      void
    >({
      query: () => ({
        url: `/canvas/api/v0/auto-saves/publish`,
        method: 'POST',
        // The endpoint publishes the whole active workspace, so the body is
        // intentionally empty.
        body: {},
      }),
      async onQueryStarted(_, { dispatch, getState, queryFulfilled }) {
        // Snapshot the pending changes before the request resolves so a
        // refused publish can restore them below.
        const pendingChangesBeforePublish =
          pendingChangesApi.endpoints.getAllPendingChanges.select(undefined)(
            getState() as any,
          ).data;
        try {
          await queryFulfilled;

          dispatch(
            pendingChangesApi.util.updateQueryData(
              'getAllPendingChanges',
              undefined,
              (draft) => {
                // Publishing covers the whole workspace, so all pending
                // changes are gone after a successful publish.
                Object.keys(draft).forEach((key) => {
                  delete draft[key];
                });
                return draft;
              },
            ),
          );

          // Invalidate the layout query cache of the current entity to ensure that the autoSaves hash is updated
          // ALSO, For example Drupal has hook_entity_presave which allows altering an entity before it is saved.
          // Canvas will not be aware of any changes made in custom code here, therefore if Canvas doesn't re-request
          // after publishing, the auto-save request could wipe out any changes that were made in
          // any hook_entity_presave code
          dispatch(
            componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]),
          );
          dispatch(setPreviousPendingChanges());
          dispatch(setErrors());
        } catch (error: any) {
          dispatch(setErrors(error.error?.data));

          // A pre-publish gate refused the publish: the pending changes are
          // unchanged, so restore the snapshot.
          // @todo https://www.drupal.org/i/3503404
          if (error.error?.status === STATUS_CODE.CONFLICT) {
            dispatch(setPreviousPendingChanges(pendingChangesBeforePublish));
          }
        }
      },
    }),
    discardPendingChange: builder.mutation<
      SuccessResponse | ErrorResponse,
      DiscardPendingChangeArg
    >({
      query: (change: PendingChange) => ({
        url: `/canvas/api/v0/auto-saves/${change.entity_type}/${change.entity_id}`,
        method: 'DELETE',
      }),
      async onQueryStarted(change, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;
          if (change.pointer) {
            dispatch(
              pendingChangesApi.util.updateQueryData(
                'getAllPendingChanges',
                undefined,
                (draft) => {
                  delete draft[change.pointer as string];
                },
              ),
            );
          }
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );

          // Reset errors
          dispatch(setPreviousPendingChanges());
          dispatch(setErrors());
        } catch (error: any) {
          dispatch(
            setErrors({
              errors: [
                {
                  code: 0,
                  detail:
                    error?.error?.data?.message ??
                    'Failed to discard pending change',
                  source: { pointer: '' },
                  meta: {
                    entity_type: change.entity_type,
                    entity_id: change.entity_id,
                    label: change.label,
                  },
                },
              ],
            }),
          );
        }
      },
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const {
  useGetAllPendingChangesQuery,
  usePublishAllPendingChangesMutation,
  useDiscardPendingChangeMutation,
} = pendingChangesApi;
