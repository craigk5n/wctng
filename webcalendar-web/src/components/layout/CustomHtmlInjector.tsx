import { useEffect } from 'react';
import { useCustomHtml } from '../../hooks/useCustomHtml';

export function CustomHeader() {
  const config = useCustomHtml();

  if (!config?.header_html) return null;

  return (
    <div
      data-testid="custom-header"
      dangerouslySetInnerHTML={{ __html: config.header_html }}
    />
  );
}

export function CustomTrailer() {
  const config = useCustomHtml();

  if (!config?.trailer_html) return null;

  return (
    <div
      data-testid="custom-trailer"
      dangerouslySetInnerHTML={{ __html: config.trailer_html }}
    />
  );
}

export function CustomCssInjector() {
  const config = useCustomHtml();

  useEffect(() => {
    if (!config?.custom_css) return;

    const styleId = 'wctng-custom-css';
    let style = document.getElementById(styleId) as HTMLStyleElement | null;

    if (!style) {
      style = document.createElement('style');
      style.id = styleId;
      document.head.appendChild(style);
    }

    style.textContent = config.custom_css;

    return () => {
      style?.remove();
    };
  }, [config?.custom_css]);

  return null;
}
