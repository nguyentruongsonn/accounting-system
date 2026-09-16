import dayjs from 'dayjs';

/**
 * Format a date string/object to clean Vietnamese DD/MM/YYYY
 * Eliminates ISO 8601 artifacts like T00:00:00.000000Z
 */
export const formatDate = (date: any): string => {
    if (!date) return '';
    if (typeof date === 'string') {
        // Strip out any trailing ISO time/zone before parsing if needed
        const cleanStr = date.replace(/T.*$/, '');
        if (/^\d{4}-\d{2}-\d{2}$/.test(cleanStr)) {
            const [y, m, d] = cleanStr.split('-');
            return `${d}/${m}/${y}`;
        }
        if (/^\d{2}\/\d{2}\/\d{4}$/.test(date)) {
            return date;
        }
    }
    const d = dayjs(date);
    return d.isValid() ? d.format('DD/MM/YYYY') : String(date).replace(/T.*$/, '');
};

/**
 * Format timestamp with full time DD/MM/YYYY HH:mm
 */
export const formatDateTime = (date: any): string => {
    if (!date) return '';
    const d = dayjs(date);
    return d.isValid() ? d.format('DD/MM/YYYY HH:mm') : String(date);
};
