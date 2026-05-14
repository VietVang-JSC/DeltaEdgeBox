# 🎯 DeltaPOS Edge Box

**Hệ thống POS offline-first cho Windows** - Giải pháp cho cửa hàng không có internet ổn định.

---

## 🌐 Kiến Trúc Hybrid Deployment

DeltaPOS hỗ trợ **2 chế độ triển khai** tùy theo nhu cầu từng cửa hàng:

### 1️⃣ Online Mode (Mặc Định)
- **Dành cho**: Cửa hàng có internet ổn định
- **Cách dùng**: Truy cập DeltaPosWeb qua browser
- **Cài đặt**: Không cần (just open browser)
- **Database**: Cloud (MySQL/PostgreSQL)
- **Internet**: Required 24/7

### 2️⃣ Edge Box Mode (Offline-First)
- **Dành cho**: Cửa hàng internet không ổn định/vùng sâu vùng xa
- **Cách dùng**: Cài Edge Box trên PC local
- **Cài đặt**: Cần install Windows package (5 phút)
- **Database**: Local SQLite + Auto-sync lên cloud
- **Internet**: Optional (hoạt động offline, sync khi online)

> **💡 Một công ty có thể dùng CẢ HAI chế độ cùng lúc:**
> - Store A, B (thành phố, internet tốt) → Online Mode
> - Store C, D (vùng xa, internet kém) → Edge Box Mode
> - Tất cả đều quản lý từ cùng một admin dashboard

---

## ✨ Tính Năng Chính

- ✅ **Hoạt động offline** - Không cần internet vẫn bán được hàng
- ✅ **Tự động sync** - Khi có mạng, dữ liệu tự động upload lên cloud
- ✅ **In hóa đơn** - Hỗ trợ máy in nhiệt (hóa đơn, phiếu bếp)
- ✅ **Backup tự động** - Sao lưu database hàng ngày lên Backblaze B2
- ✅ **Chạy nền 24/7** - Windows services hoạt động liên tục
- ✅ **Cài đặt dễ dàng** - 5 phút là xong

---  

---

## 🚀 Cài Đặt Nhanh (5 Phút)

```powershell
# 1. Giải nén vào C:\DeltaPOS-EdgeBox

# 2. Chạy installer (với quyền Administrator)
cd C:\DeltaPOS-EdgeBox
.\install.ps1

# 3. Cấu hình thông tin cửa hàng
notepad .env

# 4. Cài đặt Windows services
.\setup-services.ps1

# 5. Mở trình duyệt
# http://localhost:8000
# Đăng nhập: admin@deltapos.local / password
```

📖 **Hướng dẫn chi tiết**: [QUICK_START_GUIDE.md](QUICK_START_GUIDE.md)

---

## 📦 Hệ Thống Bao Gồm

### Thành Phần Chính

| Component | Mô Tả | Trạng Thái |
|-----------|-------|------------|
| **Laravel Backend** | REST API backend | ✅ v10.x |
| **SQLite Database** | Database local với WAL mode | ✅ Sẵn sàng |
| **Sync Engine** | Tự động sync với cloud | ✅ Hoạt động |
| **Print Worker** | Xử lý hàng đợi in ấn | ✅ Active |
| **Backup Service** | Backup database hàng ngày | ✅ Đã lên lịch |
| **Windows Services** | 5 services chạy nền | ✅ Đã cấu hình |

### Background Services (Chạy Tự Động)

1. **Sync Worker** - Đồng bộ dữ liệu mỗi 10 giây
2. **Print Worker** - Xử lý lệnh in mỗi 2 giây
3. **Heartbeat** - Gửi tín hiệu lên cloud mỗi 30 giây
4. **Backup** - Backup database lúc 23:59 hàng ngày
5. **Cleanup** - Dọn dẹp logs cũ lúc 02:00 hàng ngày

---

## 🏗️ Kiến Trúc Hệ Thống

### Flow Hoạt Động

#### Online Mode (Store A, C):
```
User → Browser → DeltaPosWeb Frontend
                    ↓
            Cloud APIs (free-pos-backend)
                    ↓
            MySQL/PostgreSQL Database
```
- Không cần cài đặt
- Cần internet liên tục
- Data lưu trực tiếp trên cloud

#### Edge Box Mode (Store B, D):
```
User → Edge Box UI (local PC)
        ↓
  Local SQLite Database
        ↓
  Windows Services (background)
        ↓ (khi có internet)
  Sync Queue → Cloud APIs
                    ↓
            Cloud Database (merge data)
```
- Cài đặt trên PC local (5 phút)
- Hoạt động offline 24/7
- Tự động sync khi online
- Backup local + cloud

---

## 💻 Yêu Cầu Hệ Thống

### Tối Thiểu
- **OS**: Windows 10 (64-bit)
- **RAM**: 4GB
- **Ổ cứng**: 20GB HDD
- **Mạng**: Không bắt buộc (chỉ cần để sync)

### Khuyến Nghị
- **OS**: Windows 11 (64-bit)
- **RAM**: 8GB
- **Ổ cứng**: 50GB SSD (nhanh hơn)
- **Mạng**: Internet ổn định

---

## ⚙️ Cấu Hình

### Configuration Per-Store Trong Cloud Admin

Trước khi cài Edge Box, admin cần cấu hình store trong cloud dashboard:

**Bước 1**: Vào Admin Dashboard → Store Management  
**Bước 2**: Chọn store cần enable Edge Box mode  
**Bước 3**: Update config:
```json
{
  "mode": "edge_box",
  "edge_box_enabled": true,
  "sync_interval": 300,
  "backup_enabled": true
}
```
**Bước 4**: Generate API key cho store  
**Bước 5**: Cung cấp API key cho kỹ thuật viên cài Edge Box

---

### File `.env` - Các thông tin cần chỉnh:

```env
# Thông tin cửa hàng
STORE_ID=STORE_001
STORE_NAME=Tên Cửa Hàng
STORE_ADDRESS=Địa chỉ
STORE_PHONE=Số điện thoại

# Cloud API (để sync)
CLOUD_API_URL=https://api.deltapos.cloud
API_KEY=your_api_key_from_cloud_admin  # 👈 Lấy từ cloud dashboard
API_SECRET=your_api_secret

# Backblaze B2 (để backup)
B2_KEY_ID=your_key_id
B2_APPLICATION_KEY=your_app_key
B2_BUCKET=your_bucket_name
```

📝 Xem `.env.example` để biết tất cả options.

---

## 📚 Tài Liệu

### Hướng Dẫn Sử Dụng
- [Quick Start Guide](QUICK_START_GUIDE.md) - Cho chủ cửa hàng
- [Installation Guide](INSTALLATION.md) - Cài đặt chi tiết
- [Windows Services](WINDOWS_SERVICES_GUIDE.md) - Quản lý services

### Tài Liệu Kỹ Thuật
- [PDF Generation](PDF_GENERATION_DOCUMENTATION.md) - Template in ấn
- [Printer Integration](PRINT_WORKER_AND_INTEGRATION.md) - Cấu hình máy in
- [Backup API](BACKUP_API_AND_CONNECTION_RESOLVER.md) - Backup & kết nối

---

## 🛠️ Lệnh Quản Lý

### Quản Lý Services

```powershell
# Menu tương tác
.\manage-services.ps1

# Lệnh nhanh
.\manage-services.ps1 -Status      # Xem trạng thái
.\manage-services.ps1 -StartAll    # Start tất cả
.\manage-services.ps1 -StopAll     # Stop tất cả
.\manage-services.ps1 -RestartAll  # Restart tất cả
```

### Lệnh Application

```powershell
php artisan migrate:status          # Kiểm tra migrations
php artisan route:list              # Xem danh sách routes
php artisan schedule:list           # Xem scheduled tasks
php artisan backup:trigger          # Backup thủ công
php artisan sync:status             # Kiểm tra sync status
php artisan edgebox:reset-admin-password  # Reset admin password
```

### Xem Logs

```powershell
# Laravel logs
Get-Content storage\logs\laravel.log -Tail 50

# Tìm lỗi
Select-String -Path storage\logs\laravel.log -Pattern "ERROR" | Select-Object -Last 20
```

---

## 🧪 Kiểm Tra Hệ Thống

### Health Check

```bash
curl http://localhost:8000/api/health
```

Kết quả mong đợi:
```json
{
  "success": true,
  "data": {
    "status": "healthy",
    "database": "connected",
    "services": "running"
  }
}
```

### Test In Ấn

Mở trình duyệt:
- Hóa đơn: http://localhost:8000/test/receipt-pdf
- Phiếu bếp: http://localhost:8000/test/kitchen-pdf

---

## ❓ FAQ - Câu Hỏi Thường Gặp

### Q1: Edge Box khác gì với DeltaPosWeb online?

**Edge Box**:
- Cài đặt trên PC local của cửa hàng
- Dùng SQLite database local
- Hoạt động offline, sync khi online
- Cần cài đặt Windows services
- Dành cho cửa hàng internet không ổn định

**DeltaPosWeb Online**:
- Truy cập qua trình duyệt web
- Dùng cloud database (MySQL/PostgreSQL)
- Cần internet liên tục
- Không cần cài đặt gì
- Dành cho cửa hàng có internet ổn định

### Q2: Một công ty có thể dùng cả 2 loại cùng lúc không?

**Có!** Đây chính là mô hình hybrid:
- Store A, B, C (internet tốt) → Dùng DeltaPosWeb online
- Store D, E (internet kém) → Dùng Edge Box offline-first
- Tất cả đều sync về cùng một Cloud Central Database

### Q3: Dữ liệu từ Edge Box sync lên cloud như thế nào?

1. Edge Box lưu mọi thay đổi vào SQLite local
2. Sync Worker check mỗi 10 giây
3. Khi có internet, gửi queued operations lên Cloud API
4. Cloud merge data vào central database
5. Nếu có conflict, áp dụng last-write-wins hoặc manual review

### Q4: Nếu mất internet lâu thì sao?

- Edge Box vẫn hoạt động bình thường (offline)
- Dữ liệu được lưu trong SQLite local
- Sync queue sẽ giữ pending operations
- Khi internet trở lại, tự động sync toàn bộ
- Không mất dữ liệu, không gián đoạn bán hàng

### Q5: Có cần cài Edge Box cho tất cả cửa hàng không?

**Không!** Chỉ cài cho cửa hàng cần chế độ offline:
- Internet không ổn định/thường mất
- Cần backup local
- Yêu cầu uptime cao (24/7)

Các cửa hàng còn lại dùng DeltaPosWeb online là đủ.

---

## 🔐 Bảo Mật

### Lưu Ý Quan Trọng

1. **Đổi mật khẩu mặc định** ngay sau khi cài đặt
2. **Cập nhật Windows** thường xuyên
3. **Dùng mật khẩu mạnh** cho tài khoản admin
4. **Kiểm tra backup** hàng tuần
5. **Cấu hình firewall** - Chỉ mở ports cần thiết

### Thông Tin Đăng Nhập Mặc Định (ĐỔI NGAY!)

- Email: `admin@deltapos.local`
- Password: `password`

---

## 🔄 Cập Nhật

### Kiểm Tra Version

```powershell
php artisan --version
```

### Cách Update

1. **Backup trước**:
   ```powershell
   php artisan backup:trigger
   ```

2. **Tải update package** từ GitHub releases

3. **Làm theo hướng dẫn** trong release notes

4. **Test kỹ** trước khi dùng production

---

## 🆘 Xử Lý Sự Cố

### Không truy cập được web interface

```powershell
# Kiểm tra services
.\manage-services.ps1 -Status

# Restart services
.\manage-services.ps1 -RestartAll

# Xem logs
Get-Content storage\logs\laravel.log -Tail 50
```

### Máy in không hoạt động

1. Kiểm tra máy in đã bật nguồn
2. Kiểm tra kết nối mạng
3. Test connection trong Settings → Printers
4. Kiểm tra hàng đợi in:
   ```powershell
   php artisan print:queue-status
   ```

### Sync không hoạt động

1. Kiểm tra kết nối internet
2. Xác minh API credentials trong `.env`
3. Xem sync logs:
   ```powershell
   Get-Content storage\logs\laravel.log | Select-String "sync"
   ```

### Database issues

```powershell
# Kiểm tra database file tồn tại
Test-Path database\database.sqlite

# Repair database (nếu bị corrupt)
php artisan db:wipe
php artisan migrate --force
php artisan db:seed
```

---

## 📊 Hiệu Suất

### Metrics Điển Hình

- **Khởi động**: ~5 giây
- **Tạo đơn hàng**: <100ms
- **Tạo PDF**: 200-300ms
- **Sync batch (50 ops)**: 2-5 giây
- **Database size**: ~50MB (1 năm hoạt động)

### Mẹo Tối Ưu

- Dùng SSD để database nhanh hơn
- Giữ ít nhất 20GB ổ cứng trống
- Dọn dẹp logs cũ hàng tháng
- Monitor RAM usage (nên dưới 2GB)

---

## ❓ FAQ - Câu Hỏi Thường Gặp

### Q1: Edge Box khác gì với DeltaPosWeb online?

**Edge Box**:
- Cài đặt trên PC local của cửa hàng
- Dùng SQLite database local
- Hoạt động offline, sync khi online
- Cần cài đặt Windows services
- Dành cho cửa hàng internet không ổn định

**DeltaPosWeb Online**:
- Truy cập qua trình duyệt web
- Dùng cloud database (MySQL/PostgreSQL)
- Cần internet liên tục
- Không cần cài đặt gì
- Dành cho cửa hàng có internet ổn định

### Q2: Một công ty có thể dùng cả 2 loại cùng lúc không?

**Có!** Đây chính là mô hình hybrid:
- Store A, B, C (internet tốt) → Dùng DeltaPosWeb online
- Store D, E (internet kém) → Dùng Edge Box offline-first
- Tất cả đều sync về cùng một cloud backend
- Admin dashboard quản lý tất cả stores thống nhất

### Q3: Làm sao để biết store nào cần Edge Box?

Admin vào Cloud Dashboard → Store Management:
- Check cấu hình `config.mode` của store
- Nếu `mode = "online"` → Store dùng browser-based POS
- Nếu `mode = "edge_box"` → Store cần cài Edge Box package

### Q4: Edge Box có cần internet không?

**Không bắt buộc!**
- Edge Box hoạt động hoàn toàn offline
- Internet chỉ cần để:
  - Sync dữ liệu lên cloud
  - Nhận updates từ cloud (products, prices, v.v.)
  - Backup lên Backblaze B2
- Không có internet vẫn bán hàng bình thường

### Q5: Dữ liệu có an toàn không khi offline?

**Rất an toàn!**
- Database local được backup hàng ngày
- Backup lưu cả local + cloud (Backblaze B2)
- Khi có internet, data tự động sync lên cloud
- Conflict resolution đảm bảo data consistency

---

## 🤝 Hỗ Trợ

### Tài Nguyên

- 📖 Tài liệu: Thư mục `docs/`
- 🐛 Báo lỗi: GitHub Issues
- 💬 Cộng đồng: Discord/Forum (coming soon)

### Liên Hệ

- Email: support@deltapos.com
- Website: https://deltapos.com
- Phone: +84-xxx-xxx-xxxx

### Thời Gian Phản Hồi

- Vấn đề nghiêm trọng: Trong vòng 24 giờ
- Câu hỏi chung: Trong vòng 48 giờ
- Feature requests: Review hàng tuần

---

## 📄 License

MIT License - Xem file [LICENSE](LICENSE) để biết chi tiết

---

## 🙏 Credits

Xây dựng với:
- [Laravel](https://laravel.com/) - PHP Framework
- [SQLite](https://www.sqlite.org/) - Database
- [DomPDF](https://github.com/barryvdh/laravel-dompdf) - PDF Generation
- [NSSM](https://nssm.cc/) - Windows Service Manager
- [Backblaze B2](https://www.backblaze.com/b2) - Cloud Storage

---

**Made with ❤️ in Vietnam**

**Version**: 1.0.0 | **Build Date**: May 2026 | **Status**: Production Ready ✅
