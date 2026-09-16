export const resolveNullableString = (
    fieldName: string,
    formValues: Record<string, any>,
    formInstance: any,
    editRecordVal?: any
): string | null => {
    // 1. Explicitly present in validated form values
    if (Object.hasOwn(formValues, fieldName)) {
        const val = formValues[fieldName];
        if (val === undefined || val === null || val === '') {
            return null;
        }
        return String(val);
    }

    // 2. User interacted with field and it is now empty
    if (typeof formInstance?.isFieldTouched === 'function' && formInstance.isFieldTouched(fieldName)) {
        const val = formInstance.getFieldValue(fieldName);
        if (val === undefined || val === null || val === '') {
            return null;
        }
        return String(val);
    }

    // 3. Present in form store
    const storeVal = formInstance?.getFieldValue?.(fieldName);
    if (storeVal !== undefined) {
        if (storeVal === null || storeVal === '') {
            return null;
        }
        return String(storeVal);
    }

    // 4. Actually absent from form, preserve existing edit record value if valid
    if (editRecordVal !== undefined && editRecordVal !== null && editRecordVal !== '') {
        return String(editRecordVal);
    }

    return null;
};

export const resolveNullableNumber = (
    fieldName: string,
    formValues: Record<string, any>,
    formInstance: any,
    editRecordVal?: any
): number | null => {
    // 1. Explicitly present in validated form values
    if (Object.hasOwn(formValues, fieldName)) {
        const val = formValues[fieldName];
        if (val === undefined || val === null || val === '') {
            return null;
        }
        const n = Number(val);
        return isNaN(n) ? null : n;
    }

    // 2. User interacted with field and it is now empty
    if (typeof formInstance?.isFieldTouched === 'function' && formInstance.isFieldTouched(fieldName)) {
        const val = formInstance.getFieldValue(fieldName);
        if (val === undefined || val === null || val === '') {
            return null;
        }
        const n = Number(val);
        return isNaN(n) ? null : n;
    }

    // 3. Present in form store
    const storeVal = formInstance?.getFieldValue?.(fieldName);
    if (storeVal !== undefined) {
        if (storeVal === null || storeVal === '') {
            return null;
        }
        const n = Number(storeVal);
        return isNaN(n) ? null : n;
    }

    // 4. Actually absent from form, preserve existing edit record value if valid
    if (editRecordVal !== undefined && editRecordVal !== null && editRecordVal !== '') {
        const n = Number(editRecordVal);
        return isNaN(n) ? null : n;
    }

    return null;
};
