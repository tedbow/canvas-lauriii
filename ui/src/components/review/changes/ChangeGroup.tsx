import { useCallback, useMemo } from 'react';
import { kebabCase } from 'lodash';
import { Box, Checkbox, Flex, Text } from '@radix-ui/themes';

import { getGroupLabel } from '../utils';
import ChangeRow from './ChangeRow';

import type { UnpublishedChange } from '@/types/Review';

import styles from './ChangeGroup.module.css';

interface ChangeGroupProps {
  entityType: string;
  changes: UnpublishedChange[];
  isBusy: boolean;
  // When false, the group and its rows render without selection checkboxes.
  selectable?: boolean;
  selectedChanges?: UnpublishedChange[];
  setSelectedChanges?: (changes: UnpublishedChange[]) => void;
  onDiscardClick: (change: UnpublishedChange) => void;
  onViewClick?: (change: UnpublishedChange) => void;
  isViewChangeAvailable?: (change: UnpublishedChange) => boolean;
  pageStatusMap?: Record<
    string,
    { status: boolean; isNew?: boolean; hasUnsavedStatusChange?: boolean }
  >;
}

const ChangeGroup = ({
  entityType,
  changes,
  isBusy,
  selectable = true,
  selectedChanges = [],
  setSelectedChanges,
  onDiscardClick,
  onViewClick,
  isViewChangeAvailable,
  pageStatusMap,
}: ChangeGroupProps) => {
  const showCheckboxes = selectable && !!setSelectedChanges;

  const isGroupSelected = useMemo(() => {
    if (!changes.length) {
      return false;
    }

    const groupSelectionCount = changes.filter((change) =>
      selectedChanges.some((selected) => selected.pointer === change.pointer),
    ).length;

    if (groupSelectionCount === 0) return false;
    if (groupSelectionCount < changes.length) return 'indeterminate';
    return true;
  }, [changes, selectedChanges]);

  const handleGroupSelection = useCallback(() => {
    if (!setSelectedChanges) {
      return;
    }
    const groupPointers = changes.map((change) => change.pointer);
    // If the group is fully selected, deselect all changes in the group
    if (isGroupSelected === true) {
      setSelectedChanges(
        selectedChanges.filter(
          (change) => !groupPointers.includes(change.pointer),
        ),
      );
      return;
    }
    // If the group is not fully selected, select remaining changes in the group
    setSelectedChanges([
      ...selectedChanges,
      ...changes.filter(
        (change) =>
          !selectedChanges.some(
            (selected) => selected.pointer === change.pointer,
          ),
      ),
    ]);
  }, [isGroupSelected, changes, selectedChanges, setSelectedChanges]);

  const groupLabel = getGroupLabel(entityType);

  return (
    <Box data-testid="pending-change-group">
      <Text as="label" size="1">
        <Flex as="div" direction="row" align="center" gap="2" mb="2">
          {showCheckboxes && (
            <Checkbox
              size="1"
              disabled={isBusy || changes.length === 0}
              checked={isGroupSelected}
              onCheckedChange={handleGroupSelection}
              aria-label={`Select all changes in ${groupLabel}`}
            />
          )}
          {groupLabel}
        </Flex>
      </Text>
      <ul className={styles.changeList}>
        {changes.map((change: UnpublishedChange) => (
          <ChangeRow
            key={`${kebabCase(change.label + change.updated)}`}
            change={change}
            isBusy={isBusy}
            selectable={selectable}
            selectedChanges={selectedChanges}
            setSelectedChanges={setSelectedChanges}
            onDiscardClick={onDiscardClick}
            onViewClick={onViewClick}
            isViewChangeAvailable={isViewChangeAvailable}
            pageStatusMap={pageStatusMap}
          />
        ))}
      </ul>
    </Box>
  );
};

export default ChangeGroup;
