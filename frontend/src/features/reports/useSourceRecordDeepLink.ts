import { useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { parseSourceRecordId } from './commercialReportSource';

type SourceRecord = { id: number | string };

export function useSourceRecordDeepLink<T extends SourceRecord>({
  records,
  isLoading,
  isError,
  onOpen,
  onMissing,
}: {
  records: T[];
  isLoading: boolean;
  isError: boolean;
  onOpen: (record: T) => void;
  onMissing: () => void;
}): void {
  const [searchParams, setSearchParams] = useSearchParams();
  const rawSourceId = searchParams.get('source_id');

  useEffect(() => {
    if (rawSourceId === null || isLoading || isError) return;
    const sourceId = parseSourceRecordId(rawSourceId);
    const record = sourceId === null
      ? undefined
      : records.find((candidate) => Number(candidate.id) === sourceId);
    if (record) onOpen(record);
    else onMissing();

    const nextParams = new URLSearchParams(searchParams);
    nextParams.delete('source_id');
    setSearchParams(nextParams, { replace: true });
  }, [isError, isLoading, onMissing, onOpen, rawSourceId, records, searchParams, setSearchParams]);
}
