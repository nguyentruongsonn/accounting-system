import React, { useEffect, useState } from 'react';
import { Alert, Form, Input, InputNumber, Select, Button, Tabs, Tag, Space, Radio, Upload, Popconfirm } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import { 
    PlusOutlined, 
    DeleteOutlined, 
    CloseOutlined,
    EditOutlined,
    PictureOutlined
} from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { 
    QuickAddItemCategoryModal, 
    QuickAddUnitModal, 
    QuickAddWarehouseModal 
} from './';
import ModalFrame from '../layout/ModalFrame';
import { MisaButton } from './MisaButton';

interface QuickAddItemModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: (newItem: any) => void;
}

const NATURE_OPTIONS = [
    { value: 'goods', label: 'Hàng hóa', code: 'HH', desc: 'Sản phẩm mua và bán lại cho khách hàng', hasWarehouse: true },
    { value: 'service', label: 'Dịch vụ', code: 'DV', desc: 'Dịch vụ mà bạn cung cấp cho khách hàng', hasWarehouse: false },
    { value: 'material', label: 'Nguyên vật liệu', code: 'NVL', desc: 'Nguyên liệu đầu vào dùng cho HĐ SX, XD, DV', hasWarehouse: true },
    { value: 'product', label: 'Thành phẩm', code: 'TP', desc: 'Sản phẩm đầu ra của quá trình sản xuất', hasWarehouse: true },
    { value: 'tool', label: 'Công cụ dụng cụ', code: 'CCDC', desc: 'CCDC mua về nhập kho chưa đưa vào sử dụng', hasWarehouse: true },
    { value: 'combo', label: 'Combo sản phẩm', code: 'HH', desc: 'Các sản phẩm, hàng hóa được bán theo combo', hasWarehouse: false },
];

export const QuickAddItemModal: React.FC<QuickAddItemModalProps> = ({
    open,
    onCancel,
    onSuccess
}) => {
    const [form] = Form.useForm();
    const queryClient = useQueryClient();
    const [itemNature, setItemNature] = useState<string>('goods');
    const [activeTab, setActiveTab] = useState<string>('1');
    const [isNatureModalOpen, setIsNatureModalOpen] = useState(false);

    // Sub-modal states
    const [isCategoryModalOpen, setIsCategoryModalOpen] = useState(false);
    const [isUnitModalOpen, setIsUnitModalOpen] = useState(false);
    const [isWarehouseModalOpen, setIsWarehouseModalOpen] = useState(false);

    // Pricing Dialog States
    const [isFixedPurchasePriceModalOpen, setIsFixedPurchasePriceModalOpen] = useState(false);
    const [isLatestPurchasePriceModalOpen, setIsLatestPurchasePriceModalOpen] = useState(false);
    const [isSalePriceModalOpen, setIsSalePriceModalOpen] = useState(false);

    // Fixed Purchase Prices List state
    const [fixedPurchasePrices, setFixedPurchasePrices] = useState<any[]>([
        { currency: 'VND', unit: 'Cái', price: 0 }
    ]);
    const [latestPurchasePrices, setLatestPurchasePrices] = useState<any[]>([
        { currency: 'VND', unit: 'Cái', price: 0 }
    ]);

    // Discount policy state
    const [discountPolicy, setDiscountPolicy] = useState<string>('none');

    // Formula state
    const [formulaTemplate, setFormulaTemplate] = useState<string>('custom');
    const [formulaText, setFormulaText] = useState<string>('');

    // Queries
    const accountsQuery = useQuery({
        queryKey: ['accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            const rows = Array.isArray(data)
                ? data
                : data && typeof data === 'object' && Array.isArray((data as { data?: unknown }).data)
                    ? (data as { data: unknown[] }).data
                    : null;
            if (!Array.isArray(rows)) throw new Error('Invalid account catalogue response');
            return rows;
        },
        enabled: open
    });
    const { data: accounts = [] } = accountsQuery;

    const categoriesQuery = useQuery({
        queryKey: ['item-categories'],
        queryFn: async () => {
            const { data } = await api.get('/master/item-categories');
            const rows = Array.isArray(data)
                ? data
                : data && typeof data === 'object' && Array.isArray((data as { data?: unknown }).data)
                    ? (data as { data: unknown[] }).data
                    : null;
            if (!Array.isArray(rows)) throw new Error('Invalid item-category catalogue response');
            return rows;
        },
        enabled: open
    });
    const { data: categories = [] } = categoriesQuery;

    const unitsQuery = useQuery({
        queryKey: ['units'],
        queryFn: async () => {
            const { data } = await api.get('/master/units');
            const rows = Array.isArray(data)
                ? data
                : data && typeof data === 'object' && Array.isArray((data as { data?: unknown }).data)
                    ? (data as { data: unknown[] }).data
                    : null;
            if (!Array.isArray(rows)) throw new Error('Invalid unit catalogue response');
            return rows;
        },
        enabled: open
    });
    const { data: units = [] } = unitsQuery;

    const warehousesQuery = useQuery({
        queryKey: ['warehouses'],
        queryFn: async () => {
            const { data } = await api.get('/master/warehouses');
            const rows = Array.isArray(data)
                ? data
                : data && typeof data === 'object' && Array.isArray((data as { data?: unknown }).data)
                    ? (data as { data: unknown[] }).data
                    : null;
            if (!Array.isArray(rows)) throw new Error('Invalid warehouse catalogue response');
            return rows;
        },
        enabled: open
    });
    const { data: warehouses = [] } = warehousesQuery;

    const itemsQuery = useQuery({
        queryKey: ['inventory-items'],
        queryFn: async () => {
            const { data } = await api.get('/inventory/items');
            const rows = Array.isArray(data)
                ? data
                : data && typeof data === 'object' && Array.isArray((data as { data?: unknown }).data)
                    ? (data as { data: unknown[] }).data
                    : null;
            if (!Array.isArray(rows)) throw new Error('Invalid inventory item catalogue response');
            return rows;
        },
        enabled: open
    });
    const { data: items = [] } = itemsQuery;

    const catalogueQueries = [accountsQuery, categoriesQuery, unitsQuery, warehousesQuery, itemsQuery];
    const catalogueError = catalogueQueries.find((query) => query.isError);
    const retryCatalogues = () => {
        void Promise.all(catalogueQueries.map((query) => query.refetch()));
    };

    const accountList = accounts;
    const categoryList = categories;
    const unitList = units;
    const warehouseList = warehouses;
    const itemList = items;
    const accountOptions = accountList
        .filter((account: any) => account && (account.code || account.account_code))
        .map((account: any) => ({
            value: account.code || account.account_code,
            label: `${account.code || account.account_code} - ${account.name || account.account_name || ''}`,
        }));

    const currentNatureConfig = NATURE_OPTIONS.find(n => n.value === itemNature) || NATURE_OPTIONS[0];

    // Nature flags
    const isGoods = itemNature === 'goods';
    const isService = itemNature === 'service';
    const isProduct = itemNature === 'product';
    const isCombo = itemNature === 'combo';

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const payload = {
                item_type: itemNature,
                type: itemNature,
                code: values.code,
                name: values.name,
                category_codes: Array.isArray(values.category_codes) ? values.category_codes : (values.category_codes ? [values.category_codes] : []),
                category_code: Array.isArray(values.category_codes) ? values.category_codes[0] : values.category_codes,
                category_name: currentNatureConfig.label,
                unit: values.unit || 'Cái',
                tax_reduction: values.tax_reduction || 'unspecified',
                warranty_period: Number(values.warranty_period) || 0,
                warranty_unit: values.warranty_unit || 'Tháng',
                warehouse_location: values.warehouse_location || null,
                minimum_stock: Number(values.minimum_stock) || 0,
                origin: values.origin || null,
                description: values.description || null,
                purchase_description: values.purchase_description || null,
                sale_description: values.sale_description || null,
                feature_type: values.feature_type || 'normal',

                default_warehouse: values.default_warehouse || undefined,
                inventory_account: values.inventory_account || undefined,
                revenue_account: values.revenue_account || undefined,
                discount_account: values.discount_account || undefined,
                rebate_account: values.rebate_account || undefined,
                return_account: values.return_account || undefined,
                cost_account: values.cost_account || undefined,
                purchase_discount_rate: Number(values.purchase_discount_rate) || 0,
                fixed_purchase_price: Number(values.fixed_purchase_price) || 0,
                latest_purchase_price: Number(values.latest_purchase_price) || 0,
                cost_price: Number(values.fixed_purchase_price) || Number(values.cost_price) || 0,
                sale_price: Number(values.sale_price) || 0,
                selling_price: Number(values.sale_price) || 0,
                sale_price_1: Number(values.sale_price_1) || 0,
                sale_price_2: Number(values.sale_price_2) || 0,
                sale_price_3: Number(values.sale_price_3) || 0,
                fixed_sale_price: Number(values.fixed_sale_price) || 0,
                vat_rate: values.vat_rate ?? '10',
                import_tax_rate: Number(values.import_tax_rate) || 0,
                export_tax_rate: Number(values.export_tax_rate) || 0,
                special_consumption_tax_group: values.special_consumption_tax_group || null,

                combo_details: values.combo_details || [],
                unit_conversions: values.unit_conversions || [],
                tier_discounts: values.tier_discounts || [],
                discount_policy: discountPolicy,
                quantity_formula: formulaText || values.quantity_formula || null,
                custom_fields: {
                    field_1: values.custom_field_1,
                    field_2: values.custom_field_2,
                    field_3: values.custom_field_3,
                    field_4: values.custom_field_4,
                    field_5: values.custom_field_5,
                }
            };
            const { data } = await api.post('/inventory/items', payload);
            return data;
        },
        onSuccess: (data, variables: any) => {
            if (!data || data.id === undefined || data.id === null) {
                message.error('Máy chủ không trả về vật tư hàng hóa đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success(`Đã thêm vật tư hàng hóa ${data.code} thành công!`);
            queryClient.invalidateQueries({ queryKey: ['inventory-items'] });
            if (onSuccess) onSuccess(data);
            if (variables._keepOpen) {
                initForm();
            } else {
                onCancel();
            }
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra khi lưu vật tư hàng hóa!');
        }
    });

    const initForm = async () => {
        form.resetFields();
        let nextCode = '';
        try {
            const { data } = await api.get('/inventory/items/next-code');
            if (data?.next_code || data?.code || data?.data?.next_code || data?.data?.code) {
                nextCode = data.next_code || data.code || data.data.next_code || data.data.code;
            }
        } catch (e) {
            // Numbering is server policy; keep the field empty when it is unavailable.
        }

        form.setFieldsValue({
            code: nextCode,
            category_codes: [],
            unit: undefined,
            tax_reduction: 'unspecified',
            warranty_period: 0,
            warranty_unit: 'Tháng',
            minimum_stock: 0,
            feature_type: 'normal',
            vat_rate: '10',
            default_warehouse: undefined,
            inventory_account: undefined,
            revenue_account: undefined,
            discount_account: undefined,
            rebate_account: undefined,
            return_account: undefined,
            cost_account: undefined,
            purchase_discount_rate: 0,
            fixed_purchase_price: 0,
            latest_purchase_price: 0,
            sale_price: 0,
            sale_price_1: 0,
            sale_price_2: 0,
            sale_price_3: 0,
            fixed_sale_price: 0,
            import_tax_rate: 0,
            export_tax_rate: 0,
            unit_conversions: [],
            tier_discounts: [],
            combo_details: []
        });
        setDiscountPolicy('none');
        setFormulaText('');
        setFormulaTemplate('custom');
        setItemNature('goods');
        setActiveTab('1');
    };

    useEffect(() => {
        if (open) {
            initForm();
        }
    }, [open]);

    const handleNatureChange = (nature: string) => {
        setItemNature(nature);
        const config = NATURE_OPTIONS.find(n => n.value === nature) || NATURE_OPTIONS[0];
        form.setFieldsValue({
            inventory_account: undefined,
            revenue_account: undefined,
            discount_account: undefined,
            rebate_account: undefined,
            return_account: undefined,
            cost_account: undefined,
            category_codes: nature === 'combo' ? [] : [config.code]
        });
        setIsNatureModalOpen(false);
    };

    const handleFormulaTemplateChange = (val: string) => {
        setFormulaTemplate(val);
        if (val === 'rect') {
            setFormulaText('ChieuDai * ChieuRong * ChieuCao');
        } else if (val === 'cylinder') {
            setFormulaText('3.1416 * BanKinh * BanKinh * ChieuCao');
        } else if (val === 'area') {
            setFormulaText('ChieuDai * ChieuRong');
        } else if (val === 'weight') {
            setFormulaText('TheTich * TyTrong');
        } else {
            setFormulaText('');
        }
    };

    const handleInsertVariable = (varName: string) => {
        setFormulaText(prev => `${prev} ${varName}`.trim());
    };

    const handleSave = (keepOpen: boolean = false) => {
        form.validateFields().then(values => {
            mutation.mutate({ ...values, _keepOpen: keepOpen });
        });
    };

    // Prepare Tabs based on Nature
    const getTabItems = () => {
        const items = [
            {
                key: '1',
                label: '1. Thông tin ngầm định',
                children: (
                    <div className="misa-p-8-4">
                        {/* 4 EQUAL-WIDTH COLUMNS BALANCED GRID PER MISA AMIS (gridTemplateColumns: repeat(4, 1fr)) */}
                        <div className="misa-grid-4col-equal">
                            {/* CỘT 1 (25% Width) */}
                            <div className="misa-flex-col-gap12-wfull">
                                {/* Kho ngầm định (Ẩn với Dịch vụ & Combo) */}
                                {!isService && !isCombo && (
                                    <div>
                                        <div className="misa-field-label">Kho ngầm định</div>
                                        <div className="misa-input-group misa-w-full">
                                            <Form.Item name="default_warehouse" noStyle>
                                                <Select 
                                                    showSearch 
                                                    variant="borderless" 
                                                    className="misa-w-full"
                                                    options={warehouseList.length > 0 ? warehouseList.map((w: any) => ({
                                                        value: w.code,
                                                        label: `${w.code} - ${w.name}`
                                                    })) : []}
                                                    notFoundContent="Chưa có kho từ máy chủ"
                                                />
                                            </Form.Item>
                                            <button type="button" className="misa-plus-btn" onClick={() => setIsWarehouseModalOpen(true)}>
                                                <PlusOutlined className="misa-font-11" />
                                            </button>
                                        </div>
                                    </div>
                                )}

                                {/* Tài khoản kho (Ẩn với Dịch vụ & Combo) */}
                                {!isService && !isCombo && (
                                    <div>
                                        <div className="misa-field-label">Tài khoản kho</div>
                                        <Form.Item name="inventory_account" noStyle>
                                            <Select 
                                                showSearch 
                                                className="misa-input misa-w-full"
                                                options={accountOptions}
                                                notFoundContent="Chưa có tài khoản từ máy chủ"
                                            />
                                        </Form.Item>
                                    </div>
                                )}

                                {/* TK Doanh thu */}
                                <div>
                                    <div className="misa-field-label">TK doanh thu</div>
                                    <Form.Item name="revenue_account" noStyle>
                                        <Select 
                                            showSearch 
                                            className="misa-input misa-w-full"
                                            options={accountOptions}
                                            notFoundContent="Chưa có tài khoản từ máy chủ"
                                        />
                                    </Form.Item>
                                </div>

                                {/* TK Chiết khấu */}
                                <div>
                                    <div className="misa-field-label">TK chiết khấu</div>
                                    <Form.Item name="discount_account" noStyle>
                                        <Select 
                                            className="misa-input misa-w-full"
                                            options={accountOptions}
                                            notFoundContent="Chưa có tài khoản từ máy chủ"
                                        />
                                    </Form.Item>
                                </div>
                            </div>

                            {/* CỘT 2 (25% Width) */}
                            <div className="misa-flex-col-gap12-wfull">
                                {/* TK Giảm giá */}
                                <div>
                                    <div className="misa-field-label">TK giảm giá</div>
                                    <Form.Item name="rebate_account" noStyle>
                                        <Select 
                                            className="misa-input misa-w-full"
                                            options={accountOptions}
                                            notFoundContent="Chưa có tài khoản từ máy chủ"
                                        />
                                    </Form.Item>
                                </div>

                                {/* TK Trả lại */}
                                <div>
                                    <div className="misa-field-label">TK trả lại</div>
                                    <Form.Item name="return_account" noStyle>
                                        <Select 
                                            className="misa-input misa-w-full"
                                            options={accountOptions}
                                            notFoundContent="Chưa có tài khoản từ máy chủ"
                                        />
                                    </Form.Item>
                                </div>

                                {/* TK Chi phí (Ẩn với Combo) */}
                                {!isCombo && (
                                    <div>
                                        <div className="misa-field-label">TK Chi phí</div>
                                        <Form.Item name="cost_account" noStyle>
                                            <Select 
                                                showSearch 
                                                className="misa-input misa-w-full"
                                                options={accountOptions}
                                                notFoundContent="Chưa có tài khoản từ máy chủ"
                                            />
                                        </Form.Item>
                                    </div>
                                )}

                                {/* Tỷ lệ CKMH (Ẩn với Combo) */}
                                {!isCombo && (
                                    <div>
                                        <div className="misa-field-label">Tỷ lệ CKMH (%)</div>
                                        <Form.Item name="purchase_discount_rate" noStyle initialValue={0}>
                                            <InputNumber className="misa-input misa-w-full" min={0} />
                                        </Form.Item>
                                    </div>
                                )}
                            </div>

                            {/* CỘT 3 (25% Width) */}
                            <div className="misa-flex-col-gap12-wfull">
                                {/* Đơn giá mua cố định (Ẩn với Combo) */}
                                {!isCombo && (
                                    <div>
                                        <div className="misa-field-label">Đơn giá mua cố định</div>
                                        <div className="misa-flex-gap4-wfull">
                                            <Form.Item name="fixed_purchase_price" noStyle initialValue={0}>
                                                <InputNumber 
                                                    className="misa-input misa-w-calc-32" 
                                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                />
                                            </Form.Item>
                                            <button 
                                                type="button" 
                                                className="misa-btn-tool misa-btn-ellipsis-28" 
                                                onClick={() => setIsFixedPurchasePriceModalOpen(true)}
                                                title="Danh sách đơn giá mua cố định"
                                            >
                                                ...
                                            </button>
                                        </div>
                                    </div>
                                )}

                                {/* Đơn giá mua gần nhất (Ẩn với Combo) */}
                                {!isCombo && (
                                    <div>
                                        <div className="misa-field-label">Đơn giá mua gần nhất</div>
                                        <div className="misa-flex-gap4-wfull">
                                            <Form.Item name="latest_purchase_price" noStyle initialValue={0}>
                                                <InputNumber 
                                                    className="misa-input misa-w-calc-32" 
                                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                />
                                            </Form.Item>
                                            <button 
                                                type="button" 
                                                className="misa-btn-tool misa-btn-ellipsis-28" 
                                                onClick={() => setIsLatestPurchasePriceModalOpen(true)}
                                                title="Danh sách đơn giá mua gần nhất"
                                            >
                                                ...
                                            </button>
                                        </div>
                                    </div>
                                )}

                                {/* Đơn giá bán */}
                                <div>
                                    <div className="misa-field-label">Đơn giá bán</div>
                                    <div className="misa-flex-gap4-wfull">
                                        <Form.Item name="sale_price" noStyle initialValue={0}>
                                            <InputNumber 
                                                className="misa-input misa-w-calc-32" 
                                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                            />
                                        </Form.Item>
                                        <button 
                                            type="button" 
                                            className="misa-btn-tool misa-btn-ellipsis-28" 
                                            onClick={() => setIsSalePriceModalOpen(true)}
                                            title="Nhập đơn giá bán chi tiết"
                                        >
                                            ...
                                        </button>
                                    </div>
                                </div>
                            </div>

                            {/* CỘT 4 (25% Width) */}
                            <div className="misa-flex-col-gap12-wfull">
                                {/* Thuế suất GTGT */}
                                <div>
                                    <div className="misa-field-label">Thuế suất GTGT (%)</div>
                                    <Form.Item name="vat_rate" noStyle initialValue="10">
                                        <Select 
                                            className="misa-input misa-w-full"
                                            options={[
                                                { value: '0', label: '0%' },
                                                { value: '5', label: '5%' },
                                                { value: '8', label: '8%' },
                                                { value: '10', label: '10%' },
                                                { value: 'KCT', label: 'KCT - Không chịu thuế' },
                                                { value: 'KTT', label: 'KTT - Không tính thuế' },
                                            ]}
                                        />
                                    </Form.Item>
                                </div>

                                {/* Thuế suất NK (Ẩn với Dịch vụ & Combo) */}
                                {!isService && !isCombo && (
                                    <div>
                                        <div className="misa-field-label">Thuế suất thuế NK (%)</div>
                                        <Form.Item name="import_tax_rate" noStyle initialValue={0}>
                                            <InputNumber className="misa-input misa-w-full" min={0} />
                                        </Form.Item>
                                    </div>
                                )}

                                {/* Thuế suất XK (Ẩn với Dịch vụ & Combo) */}
                                {!isService && !isCombo && (
                                    <div>
                                        <div className="misa-field-label">Thuế suất thuế XK (%)</div>
                                        <Form.Item name="export_tax_rate" noStyle initialValue={0}>
                                            <InputNumber className="misa-input misa-w-full" min={0} />
                                        </Form.Item>
                                    </div>
                                )}

                                {/* Nhóm HHDV chịu thuế TTĐB */}
                                <div>
                                    <div className="misa-field-label">Nhóm HHDV chịu thuế TTĐB</div>
                                    <Form.Item name="special_consumption_tax_group" noStyle>
                                        <Select 
                                            className="misa-input misa-w-full" 
                                            placeholder="Chọn nhóm thuế TTĐB"
                                            allowClear
                                            options={[
                                                { value: '1', label: 'Thuốc lá điếu, xì gà' },
                                                { value: '2', label: 'Rượu các loại' },
                                                { value: '3', label: 'Bia các loại' },
                                                { value: '4', label: 'Xe ô tô dưới 24 chỗ' },
                                            ]}
                                        />
                                    </Form.Item>
                                </div>
                            </div>
                        </div>
                    </div>
                )
            }
        ];

        // Nature specific tabs
        if (!isService && !isCombo) {
            items.push(
                {
                    key: '2',
                    label: '2. Chiết khấu bán hàng',
                    children: (
                        <div className="misa-flex-col-gap10">
                            <div className="apple-muted-text">
                                Thiết lập chiết khấu thương mại theo số lượng bán trên chứng từ bán hàng. VD: Số lượng từ 100 - 200 thì chiết khấu 5%
                            </div>

                            {/* 4 Standard MISA AMIS Discount Policy Options */}
                            <div className="misa-w-260 misa-mb-8">
                                <div className="misa-field-label">Chiết khấu bán hàng</div>
                                <Select 
                                    value={discountPolicy}
                                    onChange={setDiscountPolicy}
                                    className="misa-input misa-w-full"
                                    options={[
                                        { value: 'none', label: 'Không chiết khấu' },
                                        { value: 'percent', label: '% chiết khấu' },
                                        { value: 'amount', label: 'Số tiền chiết khấu' },
                                        { value: 'unit_amount', label: 'Số tiền CK/1 đơn vị SL' },
                                    ]}
                                />
                            </div>

                            {/* Dynamic Grid based on Selected Discount Policy */}
                            {discountPolicy === 'none' ? (
                                <div className="misa-box-empty-slate">
                                    Vật tư hàng hóa không áp dụng chính sách chiết khấu bán hàng. Chọn loại chiết khấu để thiết lập các bậc số lượng.
                                </div>
                            ) : (
                                <Form.List name="tier_discounts">
                                    {(fields, { add, remove }) => (
                                        <div className="misa-table-border-wrapper">
                                            <table className="misa-grid-table">
                                                <thead>
                                                    <tr>
                                                        <th className="misa-th-action">#</th>
                                                        <th className="misa-th-w220-right">Số lượng từ</th>
                                                        <th className="misa-th-w220-right">Số lượng đến</th>
                                                        <th className="misa-th-w200-right">
                                                            {discountPolicy === 'percent' && '% chiết khấu'}
                                                            {discountPolicy === 'amount' && 'Số tiền chiết khấu'}
                                                            {discountPolicy === 'unit_amount' && 'Số tiền CK/1 đơn vị SL'}
                                                        </th>
                                                        <th className="misa-th-action"></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {fields.length === 0 ? (
                                                        <tr>
                                                            <td colSpan={5} className="misa-td-empty-muted-24">
                                                                Chưa có mức chiết khấu nào. Bấm "Thêm dòng" để thiết lập.
                                                            </td>
                                                        </tr>
                                                    ) : (
                                                        fields.map((field, index) => (
                                                            <tr key={field.key}>
                                                                <td className="text-center">{index + 1}</td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'from_qty']} noStyle initialValue={1}>
                                                                        <InputNumber variant="borderless" className="misa-input-number-right misa-w-full" min={0} />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'to_qty']} noStyle initialValue={100}>
                                                                        <InputNumber variant="borderless" className="misa-input-number-right misa-w-full" min={0} />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'discount_value']} noStyle initialValue={discountPolicy === 'percent' ? 5 : 50000}>
                                                                        <InputNumber 
                                                                            variant="borderless" 
                                                                            className="misa-input-number-right misa-w-full"
                                                                            formatter={discountPolicy !== 'percent' ? (v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')) : undefined}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="text-center">
                                                                    <button type="button" className="misa-btn-icon-danger" onClick={() => remove(index)}>
                                                                        <DeleteOutlined />
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        ))
                                                    )}
                                                </tbody>
                                            </table>
                                            <div className="misa-table-action-footer">
                                                <Button size="small" icon={<PlusOutlined />} onClick={() => add()} className="misa-btn-tool">
                                                    Thêm dòng
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </Form.List>
                            )}
                        </div>
                    )
                },
                {
                    key: '3',
                    label: '3. Đơn vị chuyển đổi',
                    children: (
                        <div className="misa-flex-col-gap10">
                            <div className="apple-muted-text">
                                Trường hợp vật tư, hàng hóa sử dụng đơn vị tính khác nhau khi nhập/xuất thì khai báo thêm các đơn vị tính ở đây. VD: 1 tạ = 100kg, 1 két bia = 20 chai...
                            </div>

                            <Form.List name="unit_conversions">
                                {(fields, { add, remove }) => (
                                    <div className="misa-table-border-wrapper">
                                        <table className="misa-grid-table">
                                            <thead>
                                                <tr>
                                                    <th className="misa-th-action">#</th>
                                                    <th className="misa-th-w170">Đơn vị chuyển đổi</th>
                                                    <th className="misa-th-w110-right">Tỷ lệ CĐ</th>
                                                    <th className="misa-th-w120">Phép tính</th>
                                                    <th className="misa-th-minw170">Mô tả</th>
                                                    <th className="misa-th-w120-right">Đơn giá bán 1</th>
                                                    <th className="misa-th-w120-right">Đơn giá bán 2</th>
                                                    <th className="misa-th-w120-right">Đơn giá bán 3</th>
                                                    <th className="misa-th-w120-right">Đơn giá CĐ</th>
                                                    <th className="misa-th-action"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {fields.length === 0 ? (
                                                    <tr>
                                                        <td colSpan={10} className="misa-td-empty-muted-24">
                                                            Chưa có đơn vị chuyển đổi nào. Bấm "Thêm dòng" để khai báo.
                                                        </td>
                                                    </tr>
                                                ) : (
                                                    fields.map((field, index) => (
                                                        <tr key={field.key}>
                                                            <td className="text-center">{index + 1}</td>

                                                            {/* Đơn vị chuyển đổi (Select dropdown with + button) */}
                                                            <td>
                                                                <div className="misa-flex-align-gap2">
                                                                    <Form.Item name={[field.name, 'unit_name']} noStyle initialValue={unitList?.[0]?.name || 'Thùng'}>
                                                                        <Select 
                                                                            showSearch 
                                                                            variant="borderless" 
                                                                            className="misa-w-full"
                                                                            placeholder="Chọn ĐVT..."
                                                                            options={unitList.map((u: any) => ({ value: u.name, label: u.name }))}
                                                                            onChange={(val) => {
                                                                                const cur = form.getFieldValue('unit_conversions') || [];
                                                                                const mainUnit = form.getFieldValue('unit') || 'Cái';
                                                                                const rate = cur[index]?.conversion_rate || 24;
                                                                                const op = cur[index]?.operator || '*';
                                                                                cur[index].description = `1 ${val} = ${rate} ${op === '*' ? '*' : '/'} ${mainUnit}`;
                                                                                form.setFieldsValue({ unit_conversions: [...cur] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                    <button 
                                                                        type="button" 
                                                                        className="misa-plus-btn-18" 
                                                                        onClick={() => setIsUnitModalOpen(true)}
                                                                        title="Thêm ĐVT mới"
                                                                    >
                                                                        <PlusOutlined />
                                                                    </button>
                                                                </div>
                                                            </td>

                                                            {/* Tỷ lệ chuyển đổi */}
                                                            <td>
                                                                <Form.Item name={[field.name, 'conversion_rate']} noStyle initialValue={24}>
                                                                    <InputNumber 
                                                                        variant="borderless" 
                                                                        className="misa-input-number-right misa-w-full" 
                                                                        min={0.01}
                                                                        onChange={(val) => {
                                                                            const cur = form.getFieldValue('unit_conversions') || [];
                                                                            const mainUnit = form.getFieldValue('unit') || 'Cái';
                                                                            const unitName = cur[index]?.unit_name || 'Thùng';
                                                                            const op = cur[index]?.operator || '*';
                                                                            cur[index].description = `1 ${unitName} = ${val} ${op === '*' ? '*' : '/'} ${mainUnit}`;
                                                                            form.setFieldsValue({ unit_conversions: [...cur] });
                                                                        }}
                                                                    />
                                                                </Form.Item>
                                                            </td>

                                                            {/* Phép tính (Dropdown) */}
                                                            <td>
                                                                <Form.Item name={[field.name, 'operator']} noStyle initialValue="*">
                                                                    <Select 
                                                                        variant="borderless" 
                                                                        className="misa-w-full"
                                                                        options={[
                                                                            { value: '*', label: 'Phép nhân (*)' },
                                                                            { value: '/', label: 'Phép chia (/)' },
                                                                        ]}
                                                                        onChange={(val) => {
                                                                            const cur = form.getFieldValue('unit_conversions') || [];
                                                                            const mainUnit = form.getFieldValue('unit') || 'Cái';
                                                                            const unitName = cur[index]?.unit_name || 'Thùng';
                                                                            const rate = cur[index]?.conversion_rate || 24;
                                                                            cur[index].description = `1 ${unitName} = ${rate} ${val === '*' ? '*' : '/'} ${mainUnit}`;
                                                                            form.setFieldsValue({ unit_conversions: [...cur] });
                                                                        }}
                                                                    />
                                                                </Form.Item>
                                                            </td>

                                                            {/* Mô tả (Auto update) */}
                                                            <td>
                                                                <Form.Item name={[field.name, 'description']} noStyle initialValue="1 Thùng = 24 * Cái">
                                                                    <input className="misa-table-input" />
                                                                </Form.Item>
                                                            </td>

                                                            {/* Đơn giá bán 1, 2, 3, Cố định */}
                                                            <td>
                                                                <Form.Item name={[field.name, 'sale_price_1']} noStyle initialValue={0}>
                                                                    <InputNumber variant="borderless" className="misa-input-number-right misa-w-full" formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} />
                                                                </Form.Item>
                                                            </td>
                                                            <td>
                                                                <Form.Item name={[field.name, 'sale_price_2']} noStyle initialValue={0}>
                                                                    <InputNumber variant="borderless" className="misa-input-number-right misa-w-full" formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} />
                                                                </Form.Item>
                                                            </td>
                                                            <td>
                                                                <Form.Item name={[field.name, 'sale_price_3']} noStyle initialValue={0}>
                                                                    <InputNumber variant="borderless" className="misa-input-number-right misa-w-full" formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} />
                                                                </Form.Item>
                                                            </td>
                                                            <td>
                                                                <Form.Item name={[field.name, 'fixed_price']} noStyle initialValue={0}>
                                                                    <InputNumber variant="borderless" className="misa-input-number-right misa-w-full" formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} />
                                                                </Form.Item>
                                                            </td>

                                                            <td className="text-center">
                                                                <button type="button" className="misa-btn-icon-danger" onClick={() => remove(index)}>
                                                                    <DeleteOutlined />
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    ))
                                                )}
                                            </tbody>
                                        </table>
                                        <div className="misa-table-action-footer">
                                            <Button size="small" icon={<PlusOutlined />} onClick={() => add()} className="misa-btn-tool">
                                                Thêm dòng
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </Form.List>
                        </div>
                    )
                },
                {
                    key: '4',
                    label: '4. Công thức tính số lượng',
                    children: (
                        <div className="misa-flex-col-gap12">
                            <div className="apple-muted-text">
                                Chức năng này cho phép thiết lập công thức tính toán Số lượng trên chứng từ. Dùng dấu "." để ngăn cách phần thập phân.
                            </div>

                            <div className="misa-grid-12col-gap12">
                                <div className="misa-col-span-6">
                                    <div className="misa-field-label">Công thức mẫu</div>
                                    <Select 
                                        value={formulaTemplate}
                                        onChange={handleFormulaTemplateChange}
                                        className="misa-input misa-w-full"
                                        options={[
                                            { value: 'custom', label: 'Tự thiết lập (Khác)' },
                                            { value: 'rect', label: 'Hình hộp chữ nhật (Dài * Rộng * Cao)' },
                                            { value: 'cylinder', label: 'Hình trụ tròn (Pi * R^2 * Cao)' },
                                            { value: 'area', label: 'Diện tích (Dài * Rộng)' },
                                            { value: 'weight', label: 'Khối lượng (Thể tích * Tỷ trọng)' },
                                        ]}
                                    />
                                </div>
                            </div>

                            <div>
                                <div className="misa-field-label">Biểu thức công thức</div>
                                <Input.TextArea 
                                    rows={3} 
                                    value={formulaText}
                                    onChange={e => setFormulaText(e.target.value)}
                                    placeholder="Nhập công thức toán học... (VD: ChieuDai * ChieuRong * ChieuCao / 1000)" 
                                    className="misa-input-monospace"
                                />
                            </div>

                            {/* Variable and operator quick insert buttons */}
                            <div className="misa-formula-toolbar">
                                <div className="misa-flex-align-gap8-wrap">
                                    <span className="misa-form-label-semibold">Chèn biến:</span>
                                    {['ChieuDai', 'ChieuRong', 'ChieuCao', 'BanKinh', 'SoLuong', 'TyTrong'].map(v => (
                                        <Button key={v} size="small" onClick={() => handleInsertVariable(v)} className="misa-btn-formula-var">
                                            {v}
                                        </Button>
                                    ))}
                                </div>
                                <div className="misa-flex-align-gap8-wrap">
                                    <span className="misa-form-label-semibold">Toán tử:</span>
                                    {['+', '-', '*', '/', '(', ')'].map(op => (
                                        <Button key={op} size="small" onClick={() => handleInsertVariable(op)} className="misa-btn-formula-op">
                                            {op}
                                        </Button>
                                    ))}
                                    <Button 
                                        size="small" 
                                        type="dashed"
                                        onClick={() => {
                                            try {
                                                if (!formulaText) {
                                                    message.info('Vui lòng nhập công thức');
                                                    return;
                                                }
                                                message.info('Đã nhập công thức; máy chủ sẽ kiểm tra khi lưu.');
                                            } catch (e) {
                                                message.error('Công thức không hợp lệ');
                                            }
                                        }}
                                        className="misa-btn-check-formula"
                                    >
                                        Kiểm tra công thức
                                    </Button>
                                </div>
                            </div>
                        </div>
                    )
                }
            );
        }

        // Additional info tab
        items.push({
            key: '5',
            label: isService ? '2. Thông tin bổ sung' : (isCombo ? '2. Thông tin bổ sung' : '5. Thông tin bổ sung'),
            children: (
                <div className="misa-grid-12col-gap-10-14">
                    <div className="misa-col-span-6">
                        <div className="misa-field-label">Trường mở rộng 1</div>
                        <Form.Item name="custom_field_1" noStyle>
                            <Input className="misa-input" />
                        </Form.Item>
                    </div>
                    <div className="misa-col-span-6">
                        <div className="misa-field-label">Trường mở rộng 2</div>
                        <Form.Item name="custom_field_2" noStyle>
                            <Input className="misa-input" />
                        </Form.Item>
                    </div>
                    <div className="misa-col-span-6">
                        <div className="misa-field-label">Trường mở rộng 3</div>
                        <Form.Item name="custom_field_3" noStyle>
                            <Input className="misa-input" />
                        </Form.Item>
                    </div>
                    <div className="misa-col-span-6">
                        <div className="misa-field-label">Trường mở rộng 4</div>
                        <Form.Item name="custom_field_4" noStyle>
                            <Input className="misa-input" />
                        </Form.Item>
                    </div>
                    <div className="misa-col-span-12">
                        <div className="misa-field-label">Trường mở rộng 5</div>
                        <Form.Item name="custom_field_5" noStyle>
                            <Input className="misa-input" />
                        </Form.Item>
                    </div>
                </div>
            )
        });

        return items;
    };

    return (
        <>
            <Modal
                title={
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingRight: 24 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                            <span style={{ fontSize: 17, fontWeight: 700, color: '#1e293b' }}>
                                Thông tin vật tư, hàng hóa, dịch vụ
                            </span>
                            <Tag color="green" style={{ fontSize: 12, fontWeight: 600, padding: '2px 8px' }}>
                                {currentNatureConfig.label} ({currentNatureConfig.code})
                            </Tag>
                            <Button 
                                type="link" 
                                size="small" 
                                icon={<EditOutlined />}
                                onClick={() => setIsNatureModalOpen(true)}
                                className="misa-btn-change-nature"
                            >
                                Thay đổi tính chất
                            </Button>
                        </div>
                    </div>
                }
                open={open}
                onCancel={onCancel}
                width={1200}
                centered={true}
                className="misa-clean-modal"
                zIndex={2200}
                closeIcon={<CloseOutlined className="misa-font-14" />}
                footer={
                    <div className="misa-modal-footer">
                        <div className="misa-modal-footer-hints">
                            <span>• Để theo dõi vật tư hàng hóa theo quy cách bạn có thể thiết lập tại đây</span>
                            <span>• Để phân bổ doanh thu nhận trước khi bán sản phẩm bạn có thể thiết lập tại đây</span>
                        </div>
                        <Space size={10}>
                            <MisaButton onClick={onCancel}>
                                Hủy
                            </MisaButton>
                            <MisaButton
                                onClick={() => handleSave(false)} 
                                loading={mutation.isPending}
                            >
                                Cất
                            </MisaButton>
                            <MisaButton
                                variant="primary"
                                onClick={() => handleSave(true)} 
                                loading={mutation.isPending}
                            >
                                Cất và Thêm
                            </MisaButton>
                        </Space>
                    </div>
                }
            >
                <ModalFrame>
                {catalogueError ? (
                    <Alert
                        className="misa-mb-12"
                        type="error"
                        showIcon
                        message="Không thể tải danh mục hàng hóa"
                        description="Một hoặc nhiều danh mục chưa được máy chủ trả về hợp lệ; không dùng dữ liệu mẫu thay thế."
                        action={<Button size="small" onClick={retryCatalogues}>Thử lại</Button>}
                    />
                ) : null}
                <Form form={form} layout="vertical">
                    {/* MASTER SECTION (LEFT: 75% INPUT FIELDS | RIGHT: 25% IMAGE UPLOAD) */}
                    <div className="misa-modal-master-box">
                        {/* LEFT 75% INPUT FORM */}
                        <div className="misa-flex-1">
                            <div className="misa-grid-12col-gap-10-14">
                                {/* Row 1: Tên (Full width) */}
                                <div className="misa-col-span-12">
                                    <div className="misa-field-label">Tên <span className="misa-text-danger">*</span></div>
                                    <Form.Item name="name" noStyle rules={[{ required: true, message: 'Nhập tên VTHH' }]}>
                                        <Input className="misa-input" placeholder="Tên vật tư, hàng hóa, dịch vụ..." autoFocus />
                                    </Form.Item>
                                </div>

                                {/* Row 2: Mã (3/12) & Nhóm VTHH (9/12) */}
                                <div className="misa-col-span-3">
                                    <div className="misa-field-label">Mã <span className="misa-text-danger">*</span></div>
                                    <Form.Item name="code" noStyle rules={[{ required: true, message: 'Nhập mã VTHH' }]}>
                                        <Input className="misa-input misa-input-blue-bold" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-span-9">
                                    <div className="misa-field-label">Nhóm VTHH, dịch vụ</div>
                                    <div className="misa-input-group">
                                        <Form.Item name="category_codes" noStyle>
                                            <Select 
                                                mode="multiple"
                                                showSearch 
                                                variant="borderless" 
                                                className="misa-w-full"
                                                placeholder="Chọn nhóm VTHH..."
                                                maxTagCount="responsive"
                                                options={categoryList.map((c: any) => ({
                                                    value: c.code,
                                                    label: `${c.code} - ${c.name}`
                                                }))}
                                            />
                                        </Form.Item>
                                        <button type="button" className="misa-plus-btn" onClick={() => setIsCategoryModalOpen(true)}>
                                            <PlusOutlined className="misa-font-11" />
                                        </button>
                                    </div>
                                </div>

                                {/* Row 3: Đơn vị tính & Giảm thuế */}
                                <div className={isCombo ? "misa-col-span-12" : "misa-col-span-6"}>
                                    <div className="misa-field-label">
                                        {isCombo ? 'Đơn vị tính' : 'Đơn vị tính chính'} <span className="misa-text-danger">*</span>
                                    </div>
                                    <div className="misa-input-group">
                                        <Form.Item name="unit" noStyle rules={[{ required: true, message: 'Chọn ĐVT' }]}>
                                            <Select 
                                                showSearch 
                                                variant="borderless" 
                                                className="misa-w-full"
                                                placeholder="Chọn ĐVT"
                                                options={unitList.map((u: any) => ({
                                                    value: u.name,
                                                    label: u.name
                                                }))}
                                                notFoundContent="Chưa có đơn vị tính từ máy chủ"
                                            />
                                        </Form.Item>
                                        <button type="button" className="misa-plus-btn" onClick={() => setIsUnitModalOpen(true)}>
                                            <PlusOutlined className="misa-font-11" />
                                        </button>
                                    </div>
                                </div>

                                {!isCombo && (
                                    <div className="misa-col-span-6">
                                        <div className="misa-flex-between-center">
                                            <span className="misa-field-label">Giảm thuế theo quy định</span>
                                            <a href="https://misa.vn" target="_blank" rel="noreferrer" className="misa-link-helper">Tra cứu giảm thuế</a>
                                        </div>
                                        <Form.Item name="tax_reduction" noStyle initialValue="unspecified">
                                            <Select 
                                                className="misa-input"
                                                options={[
                                                    { value: 'unspecified', label: 'Chưa xác định' },
                                                    { value: 'no_reduction', label: 'Không giảm thuế' },
                                                    { value: 'reduction_2pct', label: 'Có giảm thuế (8%)' },
                                                ]}
                                            />
                                        </Form.Item>
                                    </div>
                                )}

                                {/* Row 4: Thời hạn bảo hành & Số lượng tồn tối thiểu (Ẩn với Dịch vụ; SL tồn ẩn với Combo) */}
                                {!isService && (
                                    <div className={isCombo ? "misa-col-span-12" : "misa-col-span-6"}>
                                        <div className="misa-field-label">Thời hạn bảo hành</div>
                                        <div className="misa-flex-gap-4">
                                            <Form.Item name="warranty_period" noStyle initialValue={0}>
                                                <InputNumber className="misa-input" style={{ flex: 1, minWidth: 0 }} min={0} />
                                            </Form.Item>
                                            <Form.Item name="warranty_unit" noStyle initialValue="Tháng">
                                                <Select 
                                                    className="misa-input"
                                                    style={{ width: 110, flexShrink: 0 }}
                                                    options={[
                                                        { value: 'Tháng', label: 'Tháng' },
                                                        { value: 'Năm', label: 'Năm' },
                                                        { value: 'Ngày', label: 'Ngày' },
                                                    ]}
                                                />
                                            </Form.Item>
                                        </div>
                                    </div>
                                )}

                                {!isService && !isCombo && (
                                    <div className="misa-col-span-6">
                                        <div className="misa-field-label">Số lượng tồn tối thiểu</div>
                                        <Form.Item name="minimum_stock" noStyle initialValue={0}>
                                            <InputNumber className="misa-input misa-w-full" min={0} />
                                        </Form.Item>
                                    </div>
                                )}

                                {/* Row 5: Nguồn gốc & Mô tả (Ẩn với Dịch vụ) */}
                                {!isService && (
                                    <div className="misa-col-span-4">
                                        <div className="misa-field-label">Nguồn gốc</div>
                                        <Form.Item name="origin" noStyle>
                                            <Input className="misa-input" placeholder="Việt Nam, Nhật Bản..." />
                                        </Form.Item>
                                    </div>
                                )}

                                {!isService && (
                                    <div className="misa-col-span-8">
                                        <div className="misa-field-label">Mô tả</div>
                                        <Form.Item name="description" noStyle>
                                            <Input className="misa-input" placeholder="Mô tả chi tiết sản phẩm..." />
                                        </Form.Item>
                                    </div>
                                )}

                                {/* Row 6: Diễn giải khi mua & Diễn giải khi bán */}
                                {!isCombo && (
                                    <div className="misa-col-span-6">
                                        <div className="misa-field-label">Diễn giải khi mua</div>
                                        <Form.Item name="purchase_description" noStyle>
                                            <Input className="misa-input" placeholder="Diễn giải khi lập chứng từ mua..." />
                                        </Form.Item>
                                    </div>
                                )}

                                <div className={isCombo ? "misa-col-span-12" : "misa-col-span-6"}>
                                    <div className="misa-field-label">Diễn giải khi bán</div>
                                    <Form.Item name="sale_description" noStyle>
                                        <Input className="misa-input" placeholder="Diễn giải khi lập hóa đơn bán..." />
                                    </Form.Item>
                                </div>

                                {/* Row 7: Loại hàng hóa đặc trưng (Chỉ hiển thị với HH, DV, TP) */}
                                {(isGoods || isService || isProduct) && (
                                    <div className="misa-col-span-6">
                                        <div className="misa-field-label">Loại hàng hóa đặc trưng</div>
                                        <Form.Item name="feature_type" noStyle initialValue="normal">
                                            <Select 
                                                className="misa-input"
                                                options={[
                                                    { value: 'normal', label: 'Hàng hóa thông thường' },
                                                    { value: 'batch_expiry', label: 'Hàng hóa có số lô, hạn sử dụng' },
                                                    { value: 'serial_imei', label: 'Hàng hóa có Serial/IMEI' },
                                                    { value: 'specification', label: 'Hàng hóa có mã quy cách (Màu, Size)' },
                                                ]}
                                            />
                                        </Form.Item>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* RIGHT 25% PRODUCT IMAGE UPLOAD BOX */}
                        <div className="misa-product-image-upload-box">
                            <Upload
                                name="avatar"
                                listType="picture-card"
                                showUploadList={false}
                                beforeUpload={() => false}
                                className="misa-upload-transparent-120"
                            >
                                <div className="misa-text-center">
                                    <PictureOutlined className="misa-upload-picture-icon" />
                                    <div className="misa-upload-avatar-title">Ảnh đại diện</div>
                                    <div className="misa-upload-avatar-hint">Dung lượng &lt; 2MB</div>
                                </div>
                            </Upload>
                        </div>
                    </div>

                    {/* COMBO SẢN PHẨM: BẢNG MẶT HÀNG CHI TIẾT */}
                    {isCombo && (
                        <div className="misa-combo-details-box">
                            <div className="misa-section-title-13-bold">
                                Mặt hàng chi tiết trong Combo
                            </div>
                            <Form.List name="combo_details">
                                {(fields, { add, remove }) => (
                                    <div className="misa-table-border-wrapper">
                                        <table className="misa-grid-table">
                                            <thead>
                                                <tr>
                                                    <th className="misa-th-action">#</th>
                                                    <th className="misa-th-w220">Mã hàng</th>
                                                    <th className="misa-th-minw260">Tên hàng</th>
                                                    <th className="misa-th-w90">ĐVT</th>
                                                    <th className="misa-th-w120-right">Số lượng</th>
                                                    <th className="misa-th-action"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {fields.map((field, index) => (
                                                    <tr key={field.key}>
                                                        <td className="text-center">{index + 1}</td>
                                                        <td>
                                                            <Form.Item name={[field.name, 'item_id']} noStyle>
                                                                <Select 
                                                                    showSearch 
                                                                    variant="borderless" 
                                                                    className="misa-w-full"
                                                                    placeholder="Chọn mã hàng..."
                                                                    options={itemList.map((i: any) => ({
                                                                        value: i.id,
                                                                        label: i.code,
                                                                        name: i.name,
                                                                        unit: i.unit || 'Cái'
                                                                    }))}
                                                                    onChange={(_, opt: any) => {
                                                                        const cur = form.getFieldValue('combo_details') || [];
                                                                        cur[index].item_name = opt.name;
                                                                        cur[index].unit = opt.unit;
                                                                        form.setFieldsValue({ combo_details: [...cur] });
                                                                    }}
                                                                />
                                                            </Form.Item>
                                                        </td>
                                                        <td>
                                                            <Form.Item name={[field.name, 'item_name']} noStyle>
                                                                <input className="misa-table-input" readOnly />
                                                            </Form.Item>
                                                        </td>
                                                        <td>
                                                            <Form.Item name={[field.name, 'unit']} noStyle>
                                                                <input className="misa-table-input" readOnly />
                                                            </Form.Item>
                                                        </td>
                                                        <td>
                                                            <Form.Item name={[field.name, 'quantity']} noStyle initialValue={1}>
                                                                <InputNumber variant="borderless" className="misa-input-number-right misa-w-full" min={1} />
                                                            </Form.Item>
                                                        </td>
                                                        <td className="text-center">
                                                            <button type="button" className="misa-btn-icon-danger" onClick={() => remove(index)}>
                                                                <DeleteOutlined />
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                        <div className="misa-table-action-footer">
                                            <Button size="small" icon={<PlusOutlined />} onClick={() => add()} className="misa-btn-tool">
                                                Thêm dòng
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </Form.List>
                        </div>
                    )}

                    {/* TABS SECTION */}
                    <div className="misa-tabs-outer-box">
                        <Tabs 
                            activeKey={activeTab} 
                            onChange={setActiveTab}
                            items={getTabItems()}
                        />
                    </div>
                </Form>
                </ModalFrame>
            </Modal>

            {/* MODAL 1: DANH SÁCH ĐƠN GIÁ MUA CỐ ĐỊNH (Mở qua nút ...) */}
            <Modal
                title="Danh sách đơn giá mua cố định"
                open={isFixedPurchasePriceModalOpen}
                onCancel={() => setIsFixedPurchasePriceModalOpen(false)}
                width={720}
                footer={
                    <div className="misa-modal-footer-end-gap10">
                        <Button onClick={() => setIsFixedPurchasePriceModalOpen(false)}>Hủy</Button>
                        <Button 
                            type="primary" 
                            className="misa-btn-modal-save-add"
                            onClick={() => {
                                if (fixedPurchasePrices.length > 0) {
                                    form.setFieldValue('fixed_purchase_price', fixedPurchasePrices[0].price || 0);
                                }
                                setIsFixedPurchasePriceModalOpen(false);
                                message.info('Đã cập nhật đơn giá mua cố định trong biểu mẫu; chưa lưu máy chủ.');
                            }}
                        >
                            Đồng ý
                        </Button>
                    </div>
                }
            >
                <div className="misa-flex-col-gap10">
                    <div className="misa-table-border-wrapper">
                        <table className="misa-grid-table">
                            <thead>
                                <tr>
                                    <th className="misa-th-action">#</th>
                                    <th className="misa-th-w180">Loại tiền</th>
                                    <th className="misa-th-w220">Đơn vị tính</th>
                                    <th className="misa-th-w120-right">Đơn giá</th>
                                    <th className="misa-th-action"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {fixedPurchasePrices.map((row, idx) => (
                                    <tr key={idx}>
                                        <td className="text-center">{idx + 1}</td>
                                        <td>
                                            <Select 
                                                value={row.currency}
                                                variant="borderless"
                                                className="misa-w-full"
                                                onChange={(val) => {
                                                    const updated = [...fixedPurchasePrices];
                                                    updated[idx].currency = val;
                                                    setFixedPurchasePrices(updated);
                                                }}
                                                options={[
                                                    { value: 'VND', label: 'VND - Việt Nam Đồng' },
                                                    { value: 'USD', label: 'USD - Đô la Mỹ' },
                                                    { value: 'EUR', label: 'EUR - Đồng Euro' },
                                                ]}
                                            />
                                        </td>
                                        <td>
                                            <Select 
                                                value={row.unit}
                                                variant="borderless"
                                                className="misa-w-full"
                                                onChange={(val) => {
                                                    const updated = [...fixedPurchasePrices];
                                                    updated[idx].unit = val;
                                                    setFixedPurchasePrices(updated);
                                                }}
                                                options={unitList.map((u: any) => ({ value: u.name, label: u.name }))}
                                            />
                                        </td>
                                        <td>
                                            <InputNumber 
                                                variant="borderless"
                                                value={row.price}
                                                className="misa-input-number-right misa-w-full"
                                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                onChange={(val) => {
                                                    const updated = [...fixedPurchasePrices];
                                                    updated[idx].price = Number(val) || 0;
                                                    setFixedPurchasePrices(updated);
                                                }}
                                            />
                                        </td>
                                        <td className="text-center">
                                            <button 
                                                type="button" 
                                                className="misa-btn-icon-danger"
                                                onClick={() => setFixedPurchasePrices(fixedPurchasePrices.filter((_, i) => i !== idx))}
                                            >
                                                <DeleteOutlined />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="misa-table-action-footer-gap10">
                            <Button 
                                size="small" 
                                icon={<PlusOutlined />} 
                                onClick={() => setFixedPurchasePrices([...fixedPurchasePrices, { currency: 'VND', unit: form.getFieldValue('unit') || 'Cái', price: 0 }])}
                                className="misa-btn-tool"
                            >
                                Thêm dòng
                            </Button>
                            <Popconfirm title="Xóa hết các dòng?" onConfirm={() => setFixedPurchasePrices([])}>
                                <Button size="small" icon={<DeleteOutlined />} danger className="misa-btn-tool">
                                    Xóa hết dòng
                                </Button>
                            </Popconfirm>
                        </div>
                    </div>
                </div>
            </Modal>

            {/* MODAL 2: DANH SÁCH ĐƠN GIÁ MUA GẦN NHẤT (Mở qua nút ...) */}
            <Modal
                title="Danh sách đơn giá mua gần nhất"
                open={isLatestPurchasePriceModalOpen}
                onCancel={() => setIsLatestPurchasePriceModalOpen(false)}
                width={720}
                footer={
                    <div className="misa-modal-footer-end-gap10">
                        <Button onClick={() => setIsLatestPurchasePriceModalOpen(false)}>Hủy</Button>
                        <Button 
                            type="primary" 
                            className="misa-btn-modal-save-add"
                            onClick={() => {
                                if (latestPurchasePrices.length > 0) {
                                    form.setFieldValue('latest_purchase_price', latestPurchasePrices[0].price || 0);
                                }
                                setIsLatestPurchasePriceModalOpen(false);
                                message.info('Đã cập nhật đơn giá mua gần nhất trong biểu mẫu; chưa lưu máy chủ.');
                            }}
                        >
                            Đồng ý
                        </Button>
                    </div>
                }
            >
                <div className="misa-flex-col-gap10">
                    <div className="misa-table-border-wrapper">
                        <table className="misa-grid-table">
                            <thead>
                                <tr>
                                    <th className="misa-th-action">#</th>
                                    <th className="misa-th-w180">Loại tiền</th>
                                    <th className="misa-th-w220">Đơn vị tính</th>
                                    <th className="misa-th-w120-right">Đơn giá</th>
                                    <th className="misa-th-action"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {latestPurchasePrices.map((row, idx) => (
                                    <tr key={idx}>
                                        <td className="text-center">{idx + 1}</td>
                                        <td>
                                            <Select 
                                                value={row.currency}
                                                variant="borderless"
                                                className="misa-w-full"
                                                onChange={(val) => {
                                                    const updated = [...latestPurchasePrices];
                                                    updated[idx].currency = val;
                                                    setLatestPurchasePrices(updated);
                                                }}
                                                options={[
                                                    { value: 'VND', label: 'VND - Việt Nam Đồng' },
                                                    { value: 'USD', label: 'USD - Đô la Mỹ' },
                                                    { value: 'EUR', label: 'EUR - Đồng Euro' },
                                                ]}
                                            />
                                        </td>
                                        <td>
                                            <Select 
                                                value={row.unit}
                                                variant="borderless"
                                                className="misa-w-full"
                                                onChange={(val) => {
                                                    const updated = [...latestPurchasePrices];
                                                    updated[idx].unit = val;
                                                    setLatestPurchasePrices(updated);
                                                }}
                                                options={unitList.map((u: any) => ({ value: u.name, label: u.name }))}
                                            />
                                        </td>
                                        <td>
                                            <InputNumber 
                                                variant="borderless"
                                                value={row.price}
                                                className="misa-input-number-right misa-w-full"
                                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                onChange={(val) => {
                                                    const updated = [...latestPurchasePrices];
                                                    updated[idx].price = Number(val) || 0;
                                                    setLatestPurchasePrices(updated);
                                                }}
                                            />
                                        </td>
                                        <td className="text-center">
                                            <button 
                                                type="button" 
                                                className="misa-btn-icon-danger"
                                                onClick={() => setLatestPurchasePrices(latestPurchasePrices.filter((_, i) => i !== idx))}
                                            >
                                                <DeleteOutlined />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="misa-table-action-footer-gap10">
                            <Button 
                                size="small" 
                                icon={<PlusOutlined />} 
                                onClick={() => setLatestPurchasePrices([...latestPurchasePrices, { currency: 'VND', unit: form.getFieldValue('unit') || 'Cái', price: 0 }])}
                                className="misa-btn-tool"
                            >
                                Thêm dòng
                            </Button>
                            <Popconfirm title="Xóa hết các dòng?" onConfirm={() => setLatestPurchasePrices([])}>
                                <Button size="small" icon={<DeleteOutlined />} danger className="misa-btn-tool">
                                    Xóa hết dòng
                                </Button>
                            </Popconfirm>
                        </div>
                    </div>
                </div>
            </Modal>

            {/* MODAL 3: NHẬP ĐƠN GIÁ BÁN (Mở qua nút ...) */}
            <Modal
                title="Nhập đơn giá bán"
                open={isSalePriceModalOpen}
                onCancel={() => setIsSalePriceModalOpen(false)}
                width={480}
                centered={true}
                className="misa-clean-modal"
                zIndex={2500}
                footer={
                    <div className="misa-modal-footer-end-gap10">
                        <Button onClick={() => setIsSalePriceModalOpen(false)}>Hủy</Button>
                        <Button 
                            type="primary" 
                            className="misa-btn-modal-save-add"
                            onClick={() => {
                                const p1 = form.getFieldValue('sale_price_1') || 0;
                                form.setFieldValue('sale_price', p1);
                                setIsSalePriceModalOpen(false);
                                message.info('Đã cập nhật đơn giá bán trong biểu mẫu; chưa lưu máy chủ.');
                            }}
                        >
                            Đồng ý
                        </Button>
                    </div>
                }
            >
                <div className="misa-sale-prices-list">
                    <div className="misa-flex-between-center">
                        <span className="misa-price-label">Đơn giá bán 1</span>
                        <Form.Item name="sale_price_1" noStyle initialValue={0}>
                            <InputNumber 
                                className="misa-input misa-w-260" 
                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} 
                            />
                        </Form.Item>
                    </div>
                    <div className="misa-flex-between-center">
                        <span className="misa-price-label">Đơn giá bán 2</span>
                        <Form.Item name="sale_price_2" noStyle initialValue={0}>
                            <InputNumber 
                                className="misa-input misa-w-260" 
                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} 
                            />
                        </Form.Item>
                    </div>
                    <div className="misa-flex-between-center">
                        <span className="misa-price-label">Đơn giá bán 3</span>
                        <Form.Item name="sale_price_3" noStyle initialValue={0}>
                            <InputNumber 
                                className="misa-input misa-w-260" 
                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} 
                            />
                        </Form.Item>
                    </div>
                    <div className="misa-flex-between-center">
                        <span className="misa-price-label">Đơn giá cố định</span>
                        <Form.Item name="fixed_sale_price" noStyle initialValue={0}>
                            <InputNumber 
                                className="misa-input misa-w-260" 
                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} 
                            />
                        </Form.Item>
                    </div>
                </div>
            </Modal>

            {/* Modal Chọn tính chất */}
            <Modal
                title="Chọn tính chất vật tư, hàng hóa"
                open={isNatureModalOpen}
                onCancel={() => setIsNatureModalOpen(false)}
                width={550}
                centered={true}
                className="misa-clean-modal"
                zIndex={2500}
                footer={null}
            >
                <div className="misa-nature-list">
                    {NATURE_OPTIONS.map(n => (
                        <div 
                            key={n.value}
                            onClick={() => handleNatureChange(n.value)}
                            className={`misa-nature-card ${itemNature === n.value ? 'active' : ''}`}
                        >
                            <div>
                                <div className="misa-nature-card-title">
                                    {n.label} ({n.code})
                                </div>
                                <div className="apple-muted-text">
                                    {n.desc}
                                </div>
                            </div>
                            <Radio checked={itemNature === n.value} />
                        </div>
                    ))}
                </div>
            </Modal>

            {/* Quick Add Sub-Modals */}
            <QuickAddItemCategoryModal 
                open={isCategoryModalOpen}
                onCancel={() => setIsCategoryModalOpen(false)}
                onSuccess={(newCat) => {
                    queryClient.invalidateQueries({ queryKey: ['item-categories'] });
                    const cur = form.getFieldValue('category_codes') || [];
                    form.setFieldsValue({ category_codes: [...cur, newCat.code] });
                }}
            />

            <QuickAddUnitModal 
                open={isUnitModalOpen}
                onCancel={() => setIsUnitModalOpen(false)}
                onSuccess={(newUnit) => {
                    queryClient.invalidateQueries({ queryKey: ['units'] });
                    form.setFieldsValue({ unit: newUnit.name });
                }}
            />

            <QuickAddWarehouseModal 
                open={isWarehouseModalOpen}
                onCancel={() => setIsWarehouseModalOpen(false)}
                onSuccess={(newWh) => {
                    queryClient.invalidateQueries({ queryKey: ['warehouses'] });
                    form.setFieldsValue({ default_warehouse: newWh.code });
                }}
            />
        </>
    );
};

export default QuickAddItemModal;
