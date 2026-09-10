import { useCallback, useContext, useImperativeHandle, useLayoutEffect, useRef, useState, type CSSProperties, type ReactNode, type Ref } from 'react';
import { ConfigProvider, Select, type SelectProps } from 'antd';
import type { RefSelectProps } from 'antd/es/select';

type Geometry = { listHeight: number; placement: 'topLeft' | 'bottomLeft' | 'topRight' | 'bottomRight' };

/** Opt-in boundary: virtual-list's height prop and its visible viewport stay
 * coherent. Never shrink a virtual holder by mutating max-height alone. */
export function AdaptiveSelect<ValueType = unknown>({
  ref,
  listHeight = 256,
  popupRender,
  dropdownRender,
  placement,
  popupAlign,
  getPopupContainer,
  dropdownStyle,
  popupClassName,
  styles,
  classNames,
  ...props
}: SelectProps<ValueType> & {
  ref?: Ref<RefSelectProps>;
  /** Compatibility bridge for existing callers while Ant Select migrates to semantic popup props. */
  dropdownStyle?: CSSProperties;
  popupClassName?: string;
}) {
  const select = useRef<RefSelectProps>(null);
  useImperativeHandle(ref, () => select.current!, []);
  const content = useRef<HTMLDivElement>(null);
  const { getPrefixCls } = useContext(ConfigProvider.ConfigContext);
  const prefix = getPrefixCls('select', props.prefixCls);
  const [geometry, setGeometry] = useState<Geometry>({ listHeight, placement: placement ?? 'bottomLeft' });
  const measure = useCallback(() => {
    const trigger = select.current?.nativeElement;
    const body = content.current;
    const holder = body?.getElementsByClassName(`${prefix}-dropdown-list-holder`)[0] as HTMLElement | undefined;
    const popup = body?.closest(`.${prefix}-dropdown`) as HTMLElement | null;
    if (!trigger || !popup || !holder) return;
    const bounds = trigger.getBoundingClientRect();
    const viewport = window.visualViewport;
    const top = viewport?.offsetTop ?? 0;
    const bottom = top + (viewport?.height ?? window.innerHeight);
    const above = Math.max(0, bounds.top - top - 8);
    const below = Math.max(0, bottom - bounds.bottom - 8);
    // offsetHeight is untransformed, unlike enter/leave animation DOMRects.
    const chrome = Math.max(0, popup.offsetHeight - holder.offsetHeight);
    const naturalHeight = Math.min(listHeight, holder.scrollHeight || listHeight);
    const downward = below >= naturalHeight + chrome || (above < naturalHeight + chrome && below >= above);
    const next: Geometry = {
      listHeight: Math.max(1, Math.min(listHeight, Math.floor((downward ? below : above) - chrome))),
      placement: `${downward ? 'bottom' : 'top'}${placement?.endsWith('Right') ? 'Right' : 'Left'}`,
    };
    setGeometry(previous => previous.listHeight === next.listHeight && previous.placement === next.placement ? previous : next);
  }, [listHeight, placement, prefix]);

  const styleObject = styles && typeof styles === 'object' ? styles : undefined;
  const classNameObject = classNames && typeof classNames === 'object' ? classNames : undefined;
  const resolvedStyles = dropdownStyle
    ? {
      ...styleObject,
      popup: {
        ...styleObject?.popup,
        root: {
          ...styleObject?.popup?.root,
          ...dropdownStyle,
        },
      },
    }
    : styles;
  const resolvedClassNames = popupClassName
    ? {
      ...classNameObject,
      popup: {
        ...classNameObject?.popup,
        root: [
          classNameObject?.popup?.root,
          popupClassName,
        ].filter(Boolean).join(' '),
      },
    }
    : classNames;

  return <Select<ValueType> {...props} ref={select} styles={resolvedStyles} classNames={resolvedClassNames} listHeight={geometry.listHeight} placement={geometry.placement}
    popupAlign={popupAlign ?? { overflow: { adjustX: true, adjustY: true, shiftX: true } }}
    getPopupContainer={getPopupContainer ?? (() => document.body)}
    popupRender={menu => <PopupMeasurements contentRef={content} triggerRef={select} measure={measure}>
      {(popupRender ?? dropdownRender)?.(menu) ?? menu}
    </PopupMeasurements>}
  />;
}

function PopupMeasurements({ children, contentRef, triggerRef, measure }: {
  children: ReactNode;
  contentRef: React.RefObject<HTMLDivElement | null>;
  triggerRef: React.RefObject<RefSelectProps | null>;
  measure: () => void;
}) {
  useLayoutEffect(() => {
    let frame = 0;
    const schedule = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(measure); };
    const content = contentRef.current!;
    const resize = new ResizeObserver(schedule);
    resize.observe(content);
    if (triggerRef.current?.nativeElement) resize.observe(triggerRef.current.nativeElement);
    const mutation = new MutationObserver(schedule);
    mutation.observe(content, { childList: true, subtree: true, characterData: true });
    window.addEventListener('resize', schedule);
    document.addEventListener('scroll', schedule, true);
    window.visualViewport?.addEventListener('resize', schedule);
    window.visualViewport?.addEventListener('scroll', schedule);
    schedule();
    return () => {
      cancelAnimationFrame(frame); resize.disconnect(); mutation.disconnect();
      window.removeEventListener('resize', schedule);
      document.removeEventListener('scroll', schedule, true);
      window.visualViewport?.removeEventListener('resize', schedule);
      window.visualViewport?.removeEventListener('scroll', schedule);
    };
  }, [contentRef, triggerRef, measure]);
  // Also measure owner-driven updates while the portal remains mounted.
  useLayoutEffect(measure);
  return <div ref={contentRef} data-adaptive-select="true">{children}</div>;
}
