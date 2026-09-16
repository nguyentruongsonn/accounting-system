const DEFAULT_AUTH_BOOTSTRAP_TIMEOUT_MS = 5000;

export function withAuthBootstrapTimeout<T>(
  operation: Promise<T>,
  timeoutMs = DEFAULT_AUTH_BOOTSTRAP_TIMEOUT_MS,
): Promise<T> {
  let timeoutId: ReturnType<typeof setTimeout>;

  const timeout = new Promise<never>((_, reject) => {
    timeoutId = setTimeout(() => reject(new Error('Auth bootstrap timed out')), timeoutMs);
  });

  return Promise.race([operation, timeout]).finally(() => clearTimeout(timeoutId));
}
