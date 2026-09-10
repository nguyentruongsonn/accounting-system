/**
 * Tiện ích chuyển đổi số tiền thành chữ tiếng Việt chuẩn kế toán
 */

const defaultNumbers = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];

function readThreeDigits(threeDigits: number, showZeroHundred: boolean): string {
    const hundred = Math.floor(threeDigits / 100);
    const remainder = threeDigits % 100;
    const ten = Math.floor(remainder / 10);
    const one = remainder % 10;
    let result = '';

    if (hundred > 0 || showZeroHundred) {
        result += `${defaultNumbers[hundred]} trăm `;
        if (ten === 0 && one > 0) {
            result += 'lẻ ';
        }
    }

    if (ten > 0 && ten !== 1) {
        result += `${defaultNumbers[ten]} mươi `;
        if (ten > 0 && one === 1) {
            result += 'mốt ';
        }
    } else if (ten === 1) {
        result += 'mười ';
        if (one === 1) {
            result += 'một ';
        }
    }

    if (one > 0) {
        if (one === 5 && ten > 0) {
            result += 'lăm ';
        } else if (ten !== 1 && one === 1 && (hundred > 0 || showZeroHundred)) {
            result += 'mốt ';
        } else if (ten === 0 || (ten > 1 && one !== 1)) {
            result += `${defaultNumbers[one]} `;
        }
    }

    return result.trim();
}

export function readMoneyToVietnameseWords(amount: number | string | null | undefined): string {
    const num = Number(amount);
    if (!num || isNaN(num) || num === 0) {
        return 'Không đồng';
    }

    if (num < 0) {
        return `Âm ${readMoneyToVietnameseWords(Math.abs(num)).toLowerCase()}`;
    }

    const units = ['', 'nghìn', 'triệu', 'tỷ', 'nghìn tỷ', 'triệu tỷ'];
    let tempNum = Math.floor(num);
    const groups: number[] = [];

    while (tempNum > 0) {
        groups.push(tempNum % 1000);
        tempNum = Math.floor(tempNum / 1000);
    }

    let result = '';
    for (let i = groups.length - 1; i >= 0; i--) {
        const group = groups[i];
        if (group > 0) {
            const showZeroHundred = i < groups.length - 1;
            const groupText = readThreeDigits(group, showZeroHundred);
            result += `${groupText} ${units[i]} `;
        }
    }

    result = result.trim().replace(/\s+/g, ' ');
    if (result) {
        result = result.charAt(0).toUpperCase() + result.slice(1);
        result += ' đồng chẵn.';
    }

    return result;
}
