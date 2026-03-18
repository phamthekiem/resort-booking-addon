# Changelog

## [1.4.1] - 2025-03-18

### Fixed
- **ACF Bridge**: Fix `TypeError` khi lưu ACF Options Page — `acf/save_post` hook truyền string `'options'` thay vì int, gây Fatal Error trên PHP 8. Thêm type-safe wrapper `maybe_sync_room()` và `maybe_sync_tour()`.
- **KiotViet Webhook**: Sửa công thức HMAC-SHA256 đúng theo KiotViet docs — signing string = `body + timestamp + retailerCode + secretKey`. Phiên bản cũ dùng sai format.
- **KiotViet Webhook**: Sửa tên header từ `X-Hub-Signature` thành `x-signature` (đúng chuẩn KiotViet).
- **KiotViet Webhook**: Thêm replay attack protection — reject webhook cũ hơn 5 phút.

### Added
- **GitHub Auto-Update**: Plugin tự check update từ GitHub Releases, hiển thị nút "Update" như plugin thương mại.
- **Update Settings**: Trang cấu hình GitHub username/repo/token trong admin.
- **KiotViet Channel Manager flow**: Khi KiotViet làm channel manager, webhook từ KiotViet tự động block dates và fire `rba_kv_booking_created` hook.
- **rba_kv_booking_created** action hook: cho developer mở rộng xử lý khi có booking từ OTA qua KiotViet.

## [1.4.0] - 2025-03-17

### Added
- Chương 14 trong tài liệu: KiotViet làm Channel Manager Trung Tâm.
- OTA API Manager (`class-rba-ota-api.php`): Adapter Pattern cho Agoda + Booking.com XML API.

## [1.3.0] - 2025-03-17

### Added
- Google Calendar Bridge (`class-rba-gcal.php`): cho OTA không có iCal trực tiếp (Traveloka, Trip.com).
- 2 phương thức: iCal Secret URL (không cần API) và Google Calendar API + Service Account.

## [1.2.0] - 2025-03-16

### Added
- KiotViet Hotel integration (`class-rba-kiotviet.php`): đồng bộ 2 chiều Website ↔ KiotViet.
- Webhook nhận từ KiotViet: tự động block/unblock dates khi lễ tân thao tác trong KiotViet.

## [1.1.0] - 2025-03-15

### Added
- OTA iCal Mesh Sync 2 chiều đầy đủ.
- Feed outbound bao gồm cả events từ OTA inbound (không chỉ WooCommerce orders).

## [1.0.3] - 2025-03-14

### Fixed
- Admin menu: tự động detect Tourfic menu slug qua `tf_dashboard` submenu pattern.
- CSS enqueue dùng `str_ends_with()` thay vì hardcode hook name.

## [1.0.1] - 2025-03-13

### Fixed
- Double confirm_booking: thêm idempotency guard qua order meta `_rba_booking_confirmed`.
- `session_start()` trong WordPress thay bằng WooCommerce session + custom cookie.
- `wc_orders_count()` không tồn tại → thay bằng `wc_get_orders()`.
