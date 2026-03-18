# Resort Booking Addon for Tourfic
## Hướng dẫn cài đặt & sử dụng

---

## 📁 Cấu trúc plugin

```
resort-booking-addon/
├── resort-booking-addon.php          ← Entry point chính
├── includes/
│   ├── class-rba-database.php        ← Custom tables + DB helpers
│   ├── class-rba-room-unlock.php     ← Bypass giới hạn 5 phòng
│   ├── class-rba-seasonal-price.php  ← Giá theo mùa / ngày cụ thể
│   ├── class-rba-booking-guard.php   ← Chống double booking
│   ├── class-rba-ical-sync.php       ← OTA sync (Booking.com/Airbnb)
│   ├── class-rba-tour-addon.php      ← Tour nội khu + slots
│   ├── class-rba-acf-bridge.php      ← ACF ↔ Tourfic integration
│   ├── class-rba-search.php          ← Search nâng cao
│   └── class-rba-notifications.php   ← (thêm sau)
├── admin/
│   └── class-rba-admin.php           ← Dashboard admin
├── templates/
│   └── search-form.php               ← Template search form
└── assets/
    ├── css/search.css
    └── js/search.js
```

---

## 🗄️ Database Tables được tạo

| Table | Mô tả |
|-------|-------|
| `wp_rba_seasonal_prices` | Giá theo mùa (khoảng ngày) |
| `wp_rba_date_prices` | Giá override từng ngày cụ thể |
| `wp_rba_availability` | Inventory phòng theo ngày (realtime) |
| `wp_rba_ical_sources` | Danh sách iCal feeds từ OTA |
| `wp_rba_ical_events` | Events đã sync từ OTA |
| `wp_rba_booking_locks` | Pessimistic locks chống race condition |
| `wp_rba_tour_bookings` | Booking tour theo slot giờ |

---

## ⚙️ Cài đặt

### Yêu cầu
- WordPress 6.0+
- PHP 8.0+
- Plugin **Tourfic** (free version)
- Plugin **Advanced Custom Fields** (free version)
- Plugin **WooCommerce**

### Bước 1: Upload plugin
```
/wp-content/plugins/resort-booking-addon/
```
Hoặc zip toàn bộ folder và upload qua WordPress Admin → Plugins → Add New → Upload.

### Bước 2: Activate
WordPress Admin → Plugins → Activate "Resort Booking Addon for Tourfic"

Khi activate, plugin sẽ tự động:
- Tạo 7 custom database tables
- Đăng ký cron job sync iCal mỗi 15 phút
- Flush rewrite rules

### Bước 3: Flush Rewrite Rules
WordPress Admin → Settings → Permalinks → Save (để đăng ký endpoint iCal).

---

## 🏨 Thêm phòng (không giới hạn)

1. Tourfic → Hotels → Thêm khách sạn/khu nghỉ dưỡng
2. Tourfic → Rooms → Add New Room (thêm bao nhiêu tùy ý, giới hạn 5 đã được bypass)
3. Trong phòng, điền:
   - **ACF fields**: Số lượng phòng vật lý, diện tích, hướng nhìn, loại giường, giá cơ bản
   - **Giá theo Mùa**: Meta box "Giá theo Mùa & Ngày Đặc Biệt" → thêm seasons
   - **OTA Sync**: Meta box "OTA Sync" → dán iCal URL từ Booking.com/Airbnb

---

## 🔄 Kết nối Booking.com / Airbnb

### Inbound (OTA → Site):
1. Booking.com: Extranet → Calendar → Sync → Export → Copy URL
2. Airbnb: Calendar → Import/Export → Export → Copy URL
3. Trong trang phòng WordPress → "OTA Sync" meta box → Thêm → Paste URL → Save

Sync tự động mỗi 15 phút. Nhấn "Sync ngay" để sync thủ công.

### Outbound (Site → OTA):
1. Vào trang phòng → "OTA Sync" meta box → Copy URL feed
2. Booking.com: Extranet → Calendar → Sync → Import → Paste URL
3. Airbnb: Calendar → Import/Export → Import → Paste URL

---

## 🗓️ Giá theo mùa

Trong trang phòng → Meta box "Giá theo Mùa & Ngày Đặc Biệt":

**Ví dụ:**
- Mùa hè (01/06 - 31/08): `fixed` = 2,500,000 VNĐ/đêm
- Tết Nguyên Đán (20/01 - 05/02): `fixed` = 4,000,000 VNĐ/đêm
- Cuối tuần thường: `percent` = +20%
- Ngày cụ thể (24/12): Date Override = 3,800,000 VNĐ

**Ưu tiên:**
1. Date Override (cao nhất)
2. Seasonal Price (theo priority, số nhỏ = cao hơn)
3. Base price từ ACF/Tourfic (mặc định)

---

## 🗺️ Tour nội khu

1. Tourfic → Tours → Add New Tour
2. Điền ACF fields: Loại tour, số người tối đa, khung giờ (time slots), điểm hẹn, itinerary chi tiết
3. Giá trong meta box "Cài Đặt Tour Nội Khu": người lớn / trẻ em / trẻ nhỏ
4. Combo discount: Tick "Áp dụng giảm giá combo" → khách đặt cả phòng lẫn tour được -10% giá tour

---

## 🔍 Shortcodes

```php
// Form search phòng (với AJAX realtime)
[rba_search hotel_id="123" show_tours="1"]

// Hiển thị phòng trống trực tiếp
[rba_available_rooms check_in="2025-07-01" check_out="2025-07-05" adults="2"]

// Slots tour trong ngày
[rba_tour_slots tour_id="456" date="2025-07-15"]

// Hiển thị ACF field
[rba_field post_id="123" field="room_view"]
```

---

## 🔌 REST API

```
GET /wp-json/rba/v1/availability?room_id=123&check_in=2025-07-01&check_out=2025-07-05
```

Response:
```json
{
  "available": true,
  "rooms_left": 3,
  "total_price": 7500000,
  "nights": 4
}
```

---

## 🛡️ Cơ chế chống double booking

1. **Pessimistic Lock**: Khi user add to cart, slot bị giữ 15 phút
2. **Final check**: Validate lại trước khi checkout
3. **Atomic decrement**: Trừ availability chỉ khi payment complete
4. **Lock cleanup**: Cron mỗi 15 phút dọn lock hết hạn
5. **Race condition safe**: Sử dụng MySQL transactions + INSERT IGNORE

---

## 📊 Admin Dashboard

**Tourfic → Resort Dashboard**:
- Tỷ lệ lấp đầy hôm nay
- Đơn hàng pending/processing
- Availability overview 30 ngày (color-coded)
- Quick actions

**Tourfic → OTA Sync**: Trạng thái sync tất cả feeds

---

## 🔧 Hooks & Filters có thể dùng trong theme

```php
// Thay đổi giá season
add_filter('rba_seasonal_price', function($price, $room_id, $date) {
    // custom logic
    return $price;
}, 10, 3);

// Sau khi booking confirmed
add_action('rba_booking_confirmed', function($order_id, $room_id, $check_in, $check_out) {
    // gửi SMS, CRM, v.v.
}, 10, 4);

// Sau khi iCal sync
add_action('rba_ical_synced', function($source_id, $events_count) {
    // log, notify, v.v.
}, 10, 2);

// Thay đổi combo discount %
add_filter('rba_combo_discount_percent', function($percent) {
    return 15; // 15% thay vì 10%
});
```
