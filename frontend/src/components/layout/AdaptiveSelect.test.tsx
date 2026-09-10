import { ConfigProvider } from 'antd';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AdaptiveSelect } from './AdaptiveSelect';
import { AccountSelect } from '../misa/AccountSelect';
import { createRef } from 'react';
import type { RefSelectProps } from 'antd/es/select';

const box = (top: number, height: number) => ({ top, bottom: top + height, left: 20, right: 240, width: 220, height, x: 20, y: top, toJSON: () => ({}) });
afterEach(() => vi.restoreAllMocks());
describe('owner-sized Select', () => {
  it('shifts a wide account popup inside a narrow viewport when neither horizontal alignment fits', async () => {
    vi.spyOn(window, 'innerHeight', 'get').mockReturnValue(500);
    vi.spyOn(document.documentElement, 'clientWidth', 'get').mockReturnValue(400);
    vi.spyOn(document.documentElement, 'clientHeight', 'get').mockReturnValue(500);
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function(this: HTMLElement) {
      const trigger = this.classList.contains('ant-select');
      const popup = this.classList.contains('ant-select-dropdown');
      const left = trigger ? 228 : 0; const width = trigger ? 138 : popup ? 384 : 400;
      return { ...box(trigger ? 260 : 0, trigger ? 36 : popup ? 250 : 500), left, right: left + width, x: left, width };
    });
    vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockImplementation(function(this: HTMLElement) { return this.classList.contains('ant-select') ? 138 : this.classList.contains('ant-select-dropdown') ? 384 : 400; });
    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(function(this: HTMLElement) { return this.classList.contains('ant-select') ? 36 : this.classList.contains('ant-select-dropdown') ? 250 : 216; });
    render(<AccountSelect accounts={[{ code: '1111', name: 'Account' }]}/>);
    fireEvent.mouseDown(screen.getByRole('combobox'));
    await waitFor(() => {
      const popup = document.querySelector<HTMLElement>('.ant-select-dropdown')!;
      const left = popup.style.left !== 'auto' ? Number.parseFloat(popup.style.left) : 400 - Number.parseFloat(popup.style.right) - 384;
      expect(left).toBeGreaterThanOrEqual(0);
      expect(left + 384).toBeLessThanOrEqual(400);
    });
  });
  it('preserves the Select imperative ref used by form focus handling', () => {
    const ref = createRef<RefSelectProps>();
    // Ref is an existing Select contract; the adaptive boundary must retain it.
    render(<AdaptiveSelect {...{ ref }} options={[{ value: 'posted', label: 'Posted' }]}/>);
    expect(ref.current?.nativeElement).toContainElement(screen.getByRole('combobox'));
    ref.current?.focus();
    expect(screen.getByRole('combobox')).toHaveFocus();
  });
  it.each(['ant', 'tenant'])('keeps %s virtual height coherent with available space and recomputes while open', async prefix => {
    let triggerTop = 180;
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function(this: HTMLElement) {
      return this.classList.contains(`${prefix}-select`) ? box(triggerTop, 34) : box(0, this.offsetHeight);
    });
    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(function(this: HTMLElement) {
      if (this.classList.contains(`${prefix}-select-dropdown`)) return 264;
      if (this.classList.contains(`${prefix}-select-dropdown-list-holder`)) return 256;
      return 34;
    });
    vi.spyOn(window, 'innerHeight', 'get').mockReturnValue(400);
    const { container } = render(<ConfigProvider prefixCls={prefix}><AdaptiveSelect open aria-label="Adaptive" options={Array.from({ length: 100 }, (_, value) => ({ value, label: `Option ${value}` }))}/></ConfigProvider>);
    const holder = () => document.querySelector<HTMLElement>(`.${prefix}-select-dropdown-list-holder`)!;
    // below = 178, above = 172; chrome=8 => height=170. Updating the
    // owner's listHeight must drive this actual library holder, not max-height CSS.
    await waitFor(() => expect(holder().style.maxHeight).toBe('170px'));
    expect(container.querySelector('[role="combobox"]')).toHaveAttribute('aria-expanded', 'true');
    triggerTop = 350;
    fireEvent(window, new Event('resize'));
    await waitFor(() => expect(holder().style.maxHeight).toBe('256px'));
    expect(screen.getByRole('combobox', { name: 'Adaptive' })).toBeInTheDocument();
  });
  it('adapts the non-report account selector and preserves the selected account record', async () => {
    vi.spyOn(window, 'innerHeight', 'get').mockReturnValue(400);
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function(this: HTMLElement) {
      return this.classList.contains('ant-select') ? box(180, 34) : box(0, this.offsetHeight);
    });
    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(function(this: HTMLElement) {
      if (this.classList.contains('ant-select-dropdown')) return 294;
      if (this.classList.contains('ant-select-dropdown-list-holder')) return 256;
      return 34;
    });
    const accounts = Array.from({ length: 100 }, (_, n) => ({ code: `111${n}`, name: `Account ${n}` }));
    const changed = vi.fn();
    render(<AccountSelect accounts={accounts} onChange={changed}/>);
    fireEvent.mouseDown(screen.getByRole('combobox'));
    await waitFor(() => expect(document.querySelector<HTMLElement>('.ant-select-dropdown-list-holder')?.style.maxHeight).toBe('140px'));
    fireEvent.change(screen.getByRole('combobox'), { target: { value: 'Account 99' } });
    await waitFor(() => expect(screen.getByText('Account 99')).toBeInTheDocument());
    fireEvent.click(screen.getByText('Account 99'));
    expect(changed).toHaveBeenCalledWith('11199', accounts[99]);
  });
  it('preserves keyboard navigation to the final option and closing callbacks', async () => {
    const changed = vi.fn(); const opened = vi.fn();
    render(<AdaptiveSelect onChange={changed} onOpenChange={opened} options={Array.from({ length: 100 }, (_, value) => ({ value, label: `Option ${value}` }))}/>);
    const input = screen.getByRole('combobox');
    fireEvent.mouseDown(input);
    await waitFor(() => expect(input).toHaveAttribute('aria-expanded', 'true'));
    fireEvent.keyDown(input, { key: 'ArrowUp', keyCode: 38, which: 38 });
    fireEvent.keyDown(input, { key: 'Enter', keyCode: 13, which: 13 });
    expect(changed).toHaveBeenCalledWith(99, expect.objectContaining({ value: 99 }));
    await waitFor(() => expect(opened).toHaveBeenLastCalledWith(false));
  });
});
