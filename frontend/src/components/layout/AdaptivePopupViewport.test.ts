import { afterEach, describe, expect, it } from 'vitest';
import { fitOpenPopupToViewport } from './AdaptivePopupViewport';

const rect = (top: number, bottom: number): DOMRect => ({
  top,
  bottom,
  left: 0,
  right: 320,
  width: 320,
  height: bottom - top,
  x: 0,
  y: top,
  toJSON: () => ({}),
});

describe('adaptive popup viewport', () => {
  afterEach(() => document.body.replaceChildren());

  it('limits a downward Select list to the remaining viewport and enables scrolling', () => {
    const popup = document.createElement('div');
    popup.className = 'ant-select-dropdown ant-select-dropdown-placement-bottomLeft';
    const holder = document.createElement('div');
    holder.className = 'rc-virtual-list-holder';
    const listbox = document.createElement('div');
    listbox.setAttribute('role', 'listbox');
    holder.append(listbox);
    popup.append(holder);
    document.body.append(popup);

    popup.getBoundingClientRect = () => rect(420, 720);
    holder.getBoundingClientRect = () => rect(450, 690);

    fitOpenPopupToViewport(popup, { top: 0, bottom: 600 }, 8);

    expect(popup.style.getPropertyValue('--ui-popup-available-height')).toBe('172px');
    expect(popup.style.maxHeight).toBe('172px');
    expect(popup.style.overflowY).toBe('auto');
    expect(holder.style.maxHeight).toBe('112px');
    expect(holder.style.overflowY).toBe('auto');
  });

  it('re-expands after a resize instead of treating its previous cap as authored CSS', () => {
    const popup = document.createElement('div');
    popup.className = 'ant-select-dropdown ant-select-dropdown-placement-bottomLeft';
    document.body.append(popup);

    popup.getBoundingClientRect = () => rect(100, 200);
    fitOpenPopupToViewport(popup, { top: 0, bottom: 220 }, 8);
    expect(popup.style.maxHeight).toBe('112px');

    fitOpenPopupToViewport(popup, { top: 0, bottom: 700 }, 8);
    expect(popup.style.maxHeight).toBe('592px');
  });

  it('uses the space above after the popup flips upward', () => {
    const popup = document.createElement('div');
    popup.className = 'ant-dropdown ant-dropdown-placement-topLeft';
    const menu = document.createElement('ul');
    menu.className = 'ant-dropdown-menu';
    popup.append(menu);
    document.body.append(popup);

    popup.getBoundingClientRect = () => rect(-80, 480);

    fitOpenPopupToViewport(popup, { top: 0, bottom: 600 }, 8);

    expect(popup.style.getPropertyValue('--ui-popup-available-height')).toBe('472px');
    expect(menu.style.maxHeight).toBe('472px');
    expect(menu.style.overflowY).toBe('auto');
  });
});
