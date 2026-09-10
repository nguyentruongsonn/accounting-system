type ApiErrorPayload = {
  error?: unknown;
  message?: unknown;
  errors?: Record<string, unknown>;
};

/**
 * Read the public API error contract without treating an absent message as a
 * successful/empty operation. Field-level details are preferred because the
 * backend uses them for actionable mapping and validation diagnostics.
 */
export function getApiErrorMessage(error: unknown, fallback: string): string {
  const value = error as { response?: { data?: ApiErrorPayload }; message?: unknown } | undefined;
  const payload = value?.response?.data;
  const fieldDetails = Object.values(payload?.errors ?? {})
    .flatMap((entry) => Array.isArray(entry) ? entry : [entry])
    .filter((entry): entry is string => typeof entry === 'string' && entry.trim() !== '');
  const detail = fieldDetails[0];
  if (detail) return detail;

  if (typeof payload?.message === 'string' && payload.message.trim() !== '') return payload.message;
  if (typeof payload?.error === 'string' && payload.error.trim() !== '') return payload.error;
  if (typeof value?.message === 'string' && value.message.trim() !== '') return value.message;
  return fallback;
}
