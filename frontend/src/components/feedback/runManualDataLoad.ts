import { toast } from './toast';

export type ManualDataLoadMessages = {
  success: string;
  failure: string;
};

type QueryResultLike = {
  isError?: boolean;
  error?: unknown;
};

const isQueryErrorResult = (value: unknown): value is QueryResultLike & { isError: true } => {
  if (typeof value !== 'object' || value === null || !('isError' in value)) {
    return false;
  }

  return (value as QueryResultLike).isError === true;
};

const findQueryError = (value: unknown): unknown | null => {
  if (Array.isArray(value)) {
    for (const item of value) {
      const error = findQueryError(item);
      if (error !== null) return error;
    }
    return null;
  }

  return isQueryErrorResult(value) ? value.error ?? new Error('Data load failed.') : null;
};

const resolveErrorMessage = (reason: unknown, fallback: string) => {
  if (reason instanceof Error && reason.message) {
    return reason.message;
  }

  if (typeof reason === 'string' && reason.trim()) {
    return reason;
  }

  if (typeof reason === 'object' && reason !== null && 'message' in reason) {
    const message = (reason as { message?: unknown }).message;
    if (typeof message === 'string' && message.trim()) {
      return message;
    }
  }

  return fallback;
};

export async function runManualDataLoad<T>(
  action: () => Promise<T> | T,
  messages: ManualDataLoadMessages,
): Promise<boolean> {
  try {
    const result = await action();

    if (result === false) {
      throw new Error(messages.failure);
    }

    const queryError = findQueryError(result);
    if (queryError !== null) {
      throw queryError;
    }

    toast.success(messages.success);
    return true;
  } catch (reason) {
    toast.error(resolveErrorMessage(reason, messages.failure));
    return false;
  }
}
