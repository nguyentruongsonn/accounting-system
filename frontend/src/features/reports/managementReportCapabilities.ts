import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';

export type ManagementReportCapability = {
  schema: 'management-report-capability.v1';
  key: string | null;
  label: string | null;
  route: string | null;
  api_path?: string | null;
  http_method?: string | null;
  status: string;
  implementation_status?: 'operational_draft' | 'not_implemented' | string;
  available: boolean;
  read_only: boolean;
  required_permission: string | null;
  authorized_for_current_actor: boolean;
  statutory_or_appendix_iv_certified: boolean;
  production_ready: boolean;
  definition_version: string | null;
  reason: string | null;
  /**
   * An optional, server-published execution boundary.  This is intentionally
   * separate from the legacy endpoint fields: callers may not construct a v2
   * URL merely because a report key is known locally.
   */
  v2_execution?: {
    available: boolean;
    route: string | null;
    api_path: string | null;
    http_method: string | null;
    status: string;
    definition_version: string | null;
    production_ready: boolean;
    /**
     * Explicit proof from the server that source/adjustment completeness has
     * been assessed for this executable v2 definition.  Absence is not
     * permission to use v2.
     */
    completeness_ready?: boolean;
    /** Typed sources still required before the server can execute this v2 report. */
    incomplete_sources?: string[];
    reason: string | null;
  };
};

export type ManagementReportCapabilityManifest = {
  meta: {
    manifest_version: string;
    classification: string;
    read_only: boolean;
    statutory_or_appendix_iv_certified: boolean;
    disclaimer: string;
  };
  capabilities: ManagementReportCapability[];
};

/**
 * The server is the authority both for availability and the current actor's
 * permission. Consumers must not recreate a local allow-list when this query
 * is unavailable.
 */
export const useManagementReportCapabilities = () => useQuery({
  queryKey: ['management-report-capabilities'],
  queryFn: async () => {
    const { data } = await api.get('/reports/management-capabilities');
    return data as ManagementReportCapabilityManifest;
  },
  retry: 1,
});
