import { v4 as uuidv4 } from 'uuid';
import { createSlice } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';
import type {
  ErrorResponse,
  PendingChanges,
} from '@/services/pendingChangesApi';
import type { AutoSavesHashRecord } from '@/types/AutoSaves';

export interface postPreviewSignalSliceState {
  postPreviewCompleted: boolean;
  previousPendingChanges?: PendingChanges;
  errors?: ErrorResponse;
  autoSavesHash: AutoSavesHashRecord;
  clientInstanceId: string;
}

const initialState: postPreviewSignalSliceState = {
  postPreviewCompleted: false,
  autoSavesHash: {},
  clientInstanceId: uuidv4(),
};

export const publishReviewSlice = createSlice({
  name: 'publishReview',
  initialState,
  reducers: {
    setPostPreviewCompleted(state, action: PayloadAction<boolean>) {
      state.postPreviewCompleted = action.payload;
    },
    setPreviousPendingChanges(
      state,
      action: PayloadAction<PendingChanges | undefined>,
    ) {
      state.previousPendingChanges = action.payload;
    },
    setErrors(state, action: PayloadAction<ErrorResponse | undefined>) {
      state.errors = action.payload;
    },
    setAutoSavesHash(state, action: PayloadAction<AutoSavesHashRecord>) {
      state.autoSavesHash = action.payload;
    },
    addOrUpdateAutoSavesHash(
      state,
      action: PayloadAction<AutoSavesHashRecord>,
    ) {
      // Merge new hashes into the existing state
      state.autoSavesHash = {
        ...state.autoSavesHash,
        ...action.payload,
      };
    },
  },
  selectors: {
    selectPostPreviewCompletedStatus: (postPreviewSignal): boolean => {
      return postPreviewSignal.postPreviewCompleted;
    },
    selectPreviousPendingChanges: (state): PendingChanges | undefined => {
      return state?.previousPendingChanges;
    },
    selectErrors: (state): ErrorResponse | undefined => {
      return state?.errors;
    },
    selectAutoSavesHash: (state): AutoSavesHashRecord => {
      return state?.autoSavesHash;
    },
    selectClientInstanceId: (state): string => {
      return state?.clientInstanceId;
    },
  },
});

export const {
  setPostPreviewCompleted,
  setPreviousPendingChanges,
  setErrors,
  setAutoSavesHash,
  addOrUpdateAutoSavesHash,
} = publishReviewSlice.actions;

export const {
  selectPostPreviewCompletedStatus,
  selectPreviousPendingChanges,
  selectErrors,
  selectAutoSavesHash,
  selectClientInstanceId,
} = publishReviewSlice.selectors;
