import { ConfigProvider, Select } from 'antd';
import { render, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { fitOpenPopupToViewport } from './AdaptivePopupViewport';

const rect = (top: number, height: number) => ({ top, bottom: top + height, left: 0, right: 220, width: 220, height, x: 0, y: top, toJSON: () => ({}) });
describe('real Ant Design Select viewport integration', () => {
  it.each(['ant', 'tenant'])('fits the actual %s-prefixed nonvirtual holder, not a fabricated rc class', async prefix => {
    render(<ConfigProvider prefixCls={prefix}><Select open virtual={false} options={Array.from({ length: 100 }, (_, value) => ({ value, label: `Option ${value}` }))}/></ConfigProvider>);
    const popup = document.querySelector<HTMLElement>(`.${prefix}-select-dropdown`)!;
    const holder = popup.querySelector<HTMLElement>(`.${prefix}-select-dropdown-list-holder`)!;
    await waitFor(() => expect(holder).not.toBeNull());
    popup.getBoundingClientRect = () => rect(200, 264);
    holder.getBoundingClientRect = () => rect(204, 256);
    fitOpenPopupToViewport(popup, { top: 0, bottom: 400 });
    expect(popup.style.maxHeight).toBe('192px');
    expect(popup.style.overflowY).toBe('auto');
    expect(holder.style.maxHeight).toBe('184px');
    expect(holder.style.overflowY).toBe('auto');
  });
  it('does not CSS-shrink an actual virtual Select whose internal height is unchanged', () => {
    render(<Select open options={Array.from({ length: 100 }, (_, value) => ({ value, label: `Option ${value}` }))}/>);
    const popup = document.querySelector<HTMLElement>('.ant-select-dropdown')!;
    const holder = popup.querySelector<HTMLElement>('.ant-select-dropdown-list-holder')!;
    popup.getBoundingClientRect = () => rect(200, 264);
    holder.getBoundingClientRect = () => rect(204, 256);
    fitOpenPopupToViewport(popup, { top: 0, bottom: 400 });
    expect(holder.style.maxHeight).toBe('256px');
    expect(holder.style.overflowY).toBe('hidden');
  });
});
