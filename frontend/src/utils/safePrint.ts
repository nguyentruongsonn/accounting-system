const BASE_PRINT_STYLES = `
    @page { size: A4; margin: 12mm; }
    html, body { margin: 0; padding: 0; background: #fff; }
    body { color: #000; }
    .no-print { display: none !important; }
`;

/**
 * Prints a React-rendered DOM subtree without converting it back into HTML.
 * The sandbox deliberately omits allow-scripts, so cloned event attributes
 * cannot execute inside the print document.
 */
export const printElementSafely = (
    source: HTMLElement,
    title: string,
    componentStyles = '',
    options: { allowWholePageFallback?: boolean } = {},
) => {
    const frame = document.createElement('iframe');
    frame.setAttribute('title', 'Bản in chứng từ');
    frame.setAttribute('sandbox', 'allow-same-origin allow-modals');
    frame.style.position = 'fixed';
    frame.style.width = '1px';
    frame.style.height = '1px';
    frame.style.right = '0';
    frame.style.bottom = '0';
    frame.style.border = '0';
    frame.style.opacity = '0';

    document.body.appendChild(frame);

    const printDocument = frame.contentDocument;
    const printWindow = frame.contentWindow;

    if (!printDocument || !printWindow) {
        frame.remove();
        if (options.allowWholePageFallback === false) throw new Error('Không thể tạo bản in báo cáo');
        window.print();
        return;
    }

    printDocument.title = title;
    printDocument.documentElement.lang = 'vi';

    const style = printDocument.createElement('style');
    style.textContent = `${BASE_PRINT_STYLES}\n${componentStyles}`;
    printDocument.head.appendChild(style);
    printDocument.body.appendChild(source.cloneNode(true));

    const cleanup = () => frame.remove();
    printWindow.addEventListener('afterprint', cleanup, { once: true });

    window.setTimeout(() => {
        printWindow.focus();
        printWindow.print();
        window.setTimeout(cleanup, 1_000);
    }, 50);
};
