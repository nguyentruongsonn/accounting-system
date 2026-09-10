import type { ManagementReportCapability } from './managementReportCapabilities';

export type AgingV2Execution = {
  apiPath: string;
  definitionVersion: string | null;
  reason: string | null;
};

/**
 * The capability manifest is the sole authority for selecting the v2
 * execution path.  In particular, a locally known v2 route is not enough:
 * the report capability, the current actor's authorization, and source /
 * adjustment completeness must have been affirmed by the server.
 */
export function executableAgingV2(capability: ManagementReportCapability | undefined): AgingV2Execution | null {
  const v2 = capability?.v2_execution;

  if (
    capability?.available !== true
    || capability.authorized_for_current_actor !== true
    || v2?.available !== true
    || v2.completeness_ready !== true
    || v2.http_method !== 'GET'
    || typeof v2.api_path !== 'string'
    || !/^\/api\/v2\/management-reports\/(?:ap-aging|ar-aging)$/.test(v2.api_path)
  ) {
    return null;
  }

  return {
    apiPath: v2.api_path,
    definitionVersion: v2.definition_version,
    reason: v2.reason,
  };
}

/**
 * Axios is deliberately configured with an /api/v1 base URL and rejects
 * absolute URLs. Use the server-published path only after the capability
 * check, while changing the base to the same configured origin for this one
 * v2 call. The URL itself remains relative, so bearer handling/interceptors
 * remain in effect and a manifest cannot redirect a request cross-origin.
 */
export function v2RequestConfig(apiPath: string): { baseURL: string; url: string } {
  if (!/^\/api\/v2\/management-reports\/(?:ap-aging|ar-aging)$/.test(apiPath)) {
    throw new Error('Đường dẫn v2 của báo cáo tuổi nợ không hợp lệ.');
  }

  const configuredBase = String(
    import.meta.env.VITE_API_URL
      || (import.meta.env.PROD ? window.location.origin : 'http://localhost:8000/api/v1'),
  );
  const origin = new URL(configuredBase, window.location.origin).origin;

  return { baseURL: origin, url: apiPath.slice(1) };
}
