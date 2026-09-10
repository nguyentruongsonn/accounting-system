import React from 'react';

/**
 * The budget-entry endpoint and approved budget catalogue are not published.
 * Keep the route visible for navigation, but do not render sample years,
 * departments, zero-valued monthly rows, or an unbound save action.
 */
const BudgetWorkspace: React.FC = () => (
    <div className="bg-white rounded-lg h-full p-4">
        <h2 className="text-xl font-bold mb-4">Ngân sách</h2>
        <div className="apple-muted-text">Lập dự toán chưa khả dụng. Không hiển thị số liệu mẫu.</div>
    </div>
);

export default BudgetWorkspace;
