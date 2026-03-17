import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export interface FeatureFlags {
  ALLOW_HTML_DESCRIPTION: string;
  DISABLE_LOCATION_FIELD: string;
  DISABLE_URL_FIELD: string;
  DISABLE_PRIORITY_FIELD: string;
  DISABLE_PARTICIPANTS_FIELD: string;
}

const DEFAULTS: FeatureFlags = {
  ALLOW_HTML_DESCRIPTION: 'Y',
  DISABLE_LOCATION_FIELD: 'N',
  DISABLE_URL_FIELD: 'N',
  DISABLE_PRIORITY_FIELD: 'N',
  DISABLE_PARTICIPANTS_FIELD: 'N',
};

export function useFeatureFlags(): FeatureFlags {
  const { data } = useQuery({
    queryKey: ['featureFlags'],
    queryFn: async () => {
      const { data } = await apiFetch<FeatureFlags>('/config/features');
      return data ?? DEFAULTS;
    },
    staleTime: 5 * 60 * 1000, // 5 minutes
    gcTime: 10 * 60 * 1000,
  });

  return data ?? DEFAULTS;
}
