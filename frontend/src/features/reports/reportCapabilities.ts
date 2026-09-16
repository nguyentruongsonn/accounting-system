import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';

export type ReportCapability = {
  key: string;
  label: string;
  category: string;
  status: string;
  implementation_status: string;
  available: boolean;
  appendix_iv_certified: boolean;
  definition_version: string | null;
  route: string | null;
  reason: string | null;
};

export type ReportCapabilityManifest = {
  meta: {
    disclaimer: string;
    manifest_version?: string;
    appendix_iv_certified?: boolean;
  };
  capabilities: ReportCapability[];
};

/**
 * Report labels are shown in an internal-management workspace. Strip legacy
 * statutory form codes from the display label while retaining the server key
 * and route for compatibility.
 */
export function displayInternalReportLabel(capability: Pick<ReportCapability, 'label'>): string {
  return capability.label.replace(/\s*\([A-Z]\d+[A-Z-]*\)\s*$/, '').trim();
}

export function resolveInternalReportRoute(capability: Pick<ReportCapability, 'route'>): string | null {
  const route = String(capability.route ?? '').trim();
  if (route === '') return null;
  return route.startsWith('/') ? route : '/' + route;
}

function asRecord(value: unknown, label: string): Record<string, unknown> {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error(`Máy chủ không trả về ${label} hợp lệ.`);
  }
  return value as Record<string, unknown>;
}

function requiredString(value: unknown, label: string): string {
  if (typeof value !== 'string' || value.trim() === '') throw new Error(`Máy chủ trả về ${label} không hợp lệ.`);
  return value;
}

function nullableString(value: unknown, label: string): string | null {
  if (value === null || value === undefined) return null;
  return requiredString(value, label);
}

/** Validate the server capability manifest before a workspace filters it. */
export function parseReportCapabilityManifest(value: unknown): ReportCapabilityManifest {
  const payload = asRecord(value, 'manifest báo cáo');
  const rawMeta = asRecord(payload.meta, 'metadata manifest báo cáo');
  if (!Array.isArray(payload.capabilities)) {
    throw new Error('Máy chủ không trả về danh sách capability báo cáo hợp lệ.');
  }
  const seen = new Set<string>();
  const capabilities = payload.capabilities.map((value, index): ReportCapability => {
    const row = asRecord(value, `capability báo cáo tại dòng ${index + 1}`);
    const key = requiredString(row.key, `mã capability báo cáo tại dòng ${index + 1}`);
    if (seen.has(key)) throw new Error(`Capability báo cáo bị lặp: ${key}.`);
    seen.add(key);
    if (typeof row.available !== 'boolean' || typeof row.appendix_iv_certified !== 'boolean') {
      throw new Error(`Capability báo cáo tại dòng ${index + 1} có cờ trạng thái không hợp lệ.`);
    }
    return {
      key,
      label: requiredString(row.label, `nhãn capability báo cáo tại dòng ${index + 1}`),
      category: requiredString(row.category, `nhóm capability báo cáo tại dòng ${index + 1}`),
      status: requiredString(row.status, `trạng thái capability báo cáo tại dòng ${index + 1}`),
      implementation_status: requiredString(row.implementation_status, `trạng thái triển khai capability tại dòng ${index + 1}`),
      available: row.available,
      appendix_iv_certified: row.appendix_iv_certified,
      definition_version: nullableString(row.definition_version, `phiên bản định nghĩa capability tại dòng ${index + 1}`),
      route: nullableString(row.route, `route capability báo cáo tại dòng ${index + 1}`),
      reason: nullableString(row.reason, `lý do capability báo cáo tại dòng ${index + 1}`),
    };
  });
  if (rawMeta.appendix_iv_certified !== undefined && typeof rawMeta.appendix_iv_certified !== 'boolean') {
    throw new Error('Metadata manifest báo cáo có cờ chứng nhận không hợp lệ.');
  }

  return {
    meta: {
      disclaimer: requiredString(rawMeta.disclaimer, 'disclaimer manifest báo cáo'),
      ...(rawMeta.manifest_version === undefined ? {} : { manifest_version: requiredString(rawMeta.manifest_version, 'phiên bản manifest báo cáo') }),
      ...(rawMeta.appendix_iv_certified === undefined ? {} : { appendix_iv_certified: rawMeta.appendix_iv_certified }),
    },
    capabilities,
  };
}

/**
 * The server is the authority for report availability.  Consumers must treat
 * an unavailable or unverified manifest as fail-closed rather than restoring
 * a local catalogue of report forms.
 */
export const useReportCapabilities = (enabled = true) => useQuery({
  queryKey: ['report-capabilities'],
  queryFn: async () => {
    const { data } = await api.get('/reports/capabilities');
    return parseReportCapabilityManifest(data);
  },
  enabled,
  retry: 1,
});

export const REPORT_CAPABILITY_FALLBACK_DISCLAIMER =
  'Không xác minh được trạng thái báo cáo từ máy chủ; không báo cáo nào được tự coi là khả dụng hoặc đã chứng nhận.';
