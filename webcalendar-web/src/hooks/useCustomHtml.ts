import { useEffect, useState } from 'react';
import { apiFetch } from '../api/client';

interface CustomHtmlConfig {
  header_html: string;
  trailer_html: string;
  custom_css: string;
}

const CACHE_KEY = 'wctng_custom_html';
const STALE_TIME = 5 * 60 * 1000; // 5 minutes

interface CachedData {
  data: CustomHtmlConfig;
  timestamp: number;
}

export function useCustomHtml(): CustomHtmlConfig | null {
  const [config, setConfig] = useState<CustomHtmlConfig | null>(() => {
    try {
      const cached = localStorage.getItem(CACHE_KEY);
      if (cached) {
        const parsed = JSON.parse(cached) as CachedData;
        if (Date.now() - parsed.timestamp < STALE_TIME) {
          return parsed.data;
        }
      }
    } catch {
      // ignore
    }
    return null;
  });

  useEffect(() => {
    void (async () => {
      const { data } = await apiFetch<CustomHtmlConfig>('/config/custom-html');
      if (data) {
        setConfig(data);
        try {
          localStorage.setItem(CACHE_KEY, JSON.stringify({ data, timestamp: Date.now() }));
        } catch {
          // storage full
        }
      }
    })();
  }, []);

  return config;
}
