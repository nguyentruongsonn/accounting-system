# Hệ Thống Kế Toán Doanh Nghiệp (MISA AMIS Web Clone)

Hệ thống phần mềm kế toán doanh nghiệp được xây dựng trên nền tảng **Laravel 12** và **React 19 (TypeScript)** với giao diện Ant Design 5 & TailwindCSS, tuân thủ chế độ kế toán doanh nghiệp Việt Nam theo **Thông tư 200/2014/TT-BTC**.

## 🌟 Tính Năng Chính

- **Hệ thống Danh mục & Tài khoản**: Chuẩn hóa hệ thống tài khoản kế toán Việt Nam (TT200), quản lý danh mục Khách hàng, Nhà cung cấp, Nhân viên, Kho và Vật tư hàng hóa.
- **Phân hệ Tiền mặt & Tiền gửi (Cash & Bank)**: Lập Phiếu thu, Phiếu chi tiền mặt, Thu/Chi tiền gửi ngân hàng, Ủy nhiệm chi, Chuyển tiền nội bộ, Đối chiếu ngân hàng.
- **Phân hệ Mua hàng & Bán hàng (Purchases & Sales)**: Hóa đơn mua vào/bán ra, đơn đặt hàng, chứng từ giảm giá, hàng bán/mua trả lại, bù trừ công nợ và theo dõi tuổi nợ AP/AR.
- **Phân hệ Kho & Giá thành (Inventory)**: Phiếu nhập kho, xuất kho, điều chuyển kho, kiểm kê và tính giá trị tồn kho.
- **Phân hệ Tài sản cố định & CCDC (Fixed Assets & Tools)**: Quản lý tài sản cố định, ghi tăng, tính khấu hao tự động hàng tháng; quản lý và phân bổ công cụ dụng cụ nhiều kỳ.
- **Phân hệ Tổng hợp & Sổ sách kế toán (General Ledger)**: Chứng từ nghiệp vụ khác, kết chuyển doanh thu chi phí cuối kỳ, tự động kiểm tra cân đối ghi sổ kép (Tổng Nợ = Tổng Có).
- **Hệ thống Báo cáo Tài chính & Sổ sách**:
  - Bảng cân đối kế toán (Mẫu B01-DN)
  - Báo cáo kết quả hoạt động kinh doanh (Mẫu B02-DN)
  - Báo cáo lưu chuyển tiền tệ (Mẫu B03-DN)
  - Bảng cân đối số phát sinh (Trial Balance)
  - Sổ nhật ký chung, Sổ cái các tài khoản (General Ledger explorer)
- **Tối ưu trải nghiệm người dùng (UX/UI)**: Giao diện chuẩn phong cách MISA AMIS, hỗ trợ phím tắt, cập nhật trạng thái ghi sổ và đồng bộ số liệu thời gian thực (Real-time Optimistic UI).

## 🛠️ Công Nghệ Sử Dụng

### Backend
- **Framework**: Laravel 12 (PHP 8.2+)
- **Database**: MySQL 8.0+ / MariaDB
- **Authentication**: Laravel Sanctum (Stateful SPA) & Spatie Permission (RBAC)
- **Excel & PDF Export**: Maatwebsite Excel, Barryvdh Laravel-DomPDF

### Frontend
- **Framework**: React 19 + TypeScript + Vite
- **UI Component Library**: Ant Design 5, TailwindCSS v4
- **State & Data Fetching**: TanStack React Query v5, Zustand, Axios
- **Icons & Tooling**: Ant Design Icons, Lucide React

## 🚀 Hướng Dẫn Cài Đặt & Chạy Thử

### Yêu cầu môi trường
- PHP >= 8.2 & Composer
- Node.js >= 20.x & npm
- MySQL >= 8.0 (hoặc MariaDB / XAMPP)

### 1. Cài đặt Backend
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Cấu hình thông tin kết nối DB trong backend/.env
php artisan migrate --seed
php artisan serve
```

### 2. Cài đặt Frontend
```bash
cd frontend
npm install
npm run dev
```
Truy cập ứng dụng tại: `http://localhost:8080` (hoặc cổng hiển thị trên terminal Vite).
