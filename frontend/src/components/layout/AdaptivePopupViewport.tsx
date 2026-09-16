import { useEffect } from 'react';

export interface PopupViewportBounds {
  top: number;
  bottom: number;
}

export function fitOpenPopupToViewport(
  popup: HTMLElement,
  viewport: PopupViewportBounds,
  gap = 8,
) {
  const popupRect = popup.getBoundingClientRect();
  const opensUpward = Array.from(popup.classList).some((name) => name.includes('placement-top'));
  const rawAvailable = opensUpward
    ? popupRect.bottom - viewport.top - gap
    : viewport.bottom - popupRect.top - gap;
  const availableHeight = Math.max(0, Math.floor(rawAvailable));

  popup.style.setProperty('--ui-popup-available-height', `${availableHeight}px`);

  // Bespoke dropdownRender implementations can add their own header/footer
  // and an outer fixed height. Keep those legacy popups scrollable as well as
  // their inner virtual holder. AdaptiveSelect owns its exact listHeight, so
  // leave that boundary untouched and only constrain ordinary Ant popups.
  if (!popup.querySelector('[data-adaptive-select]')) {
    // Remember only the authored inline max-height. The value written by this
    // function must not become the new baseline, otherwise a short viewport
    // followed by resize/zoom-out would keep the popup permanently collapsed.
    if (!Object.prototype.hasOwnProperty.call(popup.dataset, 'uiPopupConfiguredMaxHeight')) {
      popup.dataset.uiPopupConfiguredMaxHeight = popup.style.maxHeight;
    }
    const configuredMaxHeight = Number.parseFloat(popup.dataset.uiPopupConfiguredMaxHeight || '');
    const maxHeight = Number.isFinite(configuredMaxHeight)
      ? Math.min(configuredMaxHeight, availableHeight)
      : availableHeight;
    popup.style.maxHeight = `${Math.max(1, maxHeight)}px`;
    popup.style.overflowY = 'auto';
    popup.style.overscrollBehavior = 'contain';
  }

  const popupPrefix = Array.from(popup.classList).find(name => name.endsWith('-dropdown')
    && popup.getElementsByClassName(`${name}-list-holder`).length > 0);
  const selectHolder = popupPrefix
    ? popup.getElementsByClassName(`${popupPrefix}-list-holder`)[0] as HTMLElement
    : popup.querySelector<HTMLElement>('.rc-virtual-list-holder');
  // Nonvirtual lists expose their listbox inside the holder. Virtual lists
  // expose a separate hidden a11y listbox; their owner must update listHeight.
  if (selectHolder?.querySelector('[role="listbox"]') && !popup.querySelector('[data-adaptive-select]')) {
    const holderHeight = selectHolder.getBoundingClientRect().height;
    const popupChromeHeight = Math.max(0, popupRect.height - holderHeight);
    selectHolder.style.maxHeight = `${Math.max(0, Math.floor(availableHeight - popupChromeHeight))}px`;
    selectHolder.style.overflowY = 'auto';
    selectHolder.style.overscrollBehavior = 'contain';
  }

  const dropdownMenu = popup.querySelector<HTMLElement>('.ant-dropdown-menu');
  if (dropdownMenu) {
    dropdownMenu.style.maxHeight = `${availableHeight}px`;
    dropdownMenu.style.overflowY = 'auto';
    dropdownMenu.style.overscrollBehavior = 'contain';
  }
}

function getViewportBounds(): PopupViewportBounds {
  const visualViewport = window.visualViewport;
  if (visualViewport) {
    return {
      top: visualViewport.offsetTop,
      bottom: visualViewport.offsetTop + visualViewport.height,
    };
  }

  return { top: 0, bottom: document.documentElement.clientHeight || window.innerHeight };
}

function fitAllOpenPopups() {
  const selector = [
    '.ant-select-dropdown:not(.ant-select-dropdown-hidden)',
    '.ant-dropdown:not(.ant-dropdown-hidden)',
  ].join(',');
  const viewport = getViewportBounds();
  document.querySelectorAll<HTMLElement>(selector).forEach((popup) => {
    fitOpenPopupToViewport(popup, viewport);
  });
}

export function AdaptivePopupViewport() {
  useEffect(() => {
    let frame = 0;
    const scheduleFit = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(fitAllOpenPopups);
    };

    const observer = new MutationObserver(scheduleFit);
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['class'],
    });

    window.addEventListener('resize', scheduleFit);
    document.addEventListener('scroll', scheduleFit, true);
    window.visualViewport?.addEventListener('resize', scheduleFit);
    window.visualViewport?.addEventListener('scroll', scheduleFit);
    scheduleFit();

    return () => {
      cancelAnimationFrame(frame);
      observer.disconnect();
      window.removeEventListener('resize', scheduleFit);
      document.removeEventListener('scroll', scheduleFit, true);
      window.visualViewport?.removeEventListener('resize', scheduleFit);
      window.visualViewport?.removeEventListener('scroll', scheduleFit);
    };
  }, []);
  return null;
}
