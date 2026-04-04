<?php
/**
 * PMS Dashboard Template
 * Trang lễ tân — hoàn toàn tách khỏi theme WordPress.
 * Load trực tiếp qua class-rba-pms.php khi truy cập /pms/
 */
defined('ABSPATH') || exit;
if (!RBA_PMS_Role::current_user_can_pms()) { wp_safe_redirect(wp_login_url(home_url('/pms/'))); exit; }

$current_page = defined('RBA_PMS_CURRENT_PAGE') ? RBA_PMS_CURRENT_PAGE : 'dashboard';
$current_user = wp_get_current_user();
$nonce        = wp_create_nonce('rba_pms_nonce');
$stats        = RBA_PMS::get_dashboard_stats();
$hotel_name   = get_bloginfo('name');
$today_str    = date_i18n('l, d/m/Y');
$ajax_url     = admin_url('admin-ajax.php');

$nav_items = [
    'dashboard' => ['icon'=>'⊞','label'=>'Tổng quan','url'=>'/pms/'],
    'checkin'   => ['icon'=>'✔','label'=>'Check-in/out','url'=>'/pms/checkin/'],
    'bookings'  => ['icon'=>'📋','label'=>'Đặt phòng','url'=>'/pms/bookings/'],
    'rooms'     => ['icon'=>'🏠','label'=>'Phòng','url'=>'/pms/rooms/'],
    'invoices'  => ['icon'=>'🧾','label'=>'Hóa đơn','url'=>'/pms/invoices/'],
    'reports'   => ['icon'=>'📊','label'=>'Báo cáo','url'=>'/pms/reports/'],
];

$status_colors = [
    'available'   => ['bg'=>'#e8f5e9','text'=>'#2e7d32','label'=>'Trống'],
    'occupied'    => ['bg'=>'#e3f2fd','text'=>'#1565c0','label'=>'Có khách'],
    'dirty'       => ['bg'=>'#fff3e0','text'=>'#e65100','label'=>'Cần dọn'],
    'maintenance' => ['bg'=>'#fce4ec','text'=>'#c62828','label'=>'Bảo trì'],
    'blocked'     => ['bg'=>'#f5f5f5','text'=>'#757575','label'=>'Blocked'],
];

$booking_status_labels = [
    'processing'   => ['Đang xử lý','#1565c0'],
    'completed'    => ['Hoàn tất','#2e7d32'],
    'cancelled'    => ['Đã hủy','#c62828'],
    'on-hold'      => ['Chờ','#e65100'],
    'pending'      => ['Chờ thanh toán','#854f0b'],
    'checkin_today'  => ['Check-in hôm nay','#1565c0'],
    'checkout_today' => ['Check-out hôm nay','#e65100'],
    'inhouse'      => ['Đang ở','#2e7d32'],
    'checked_out'  => ['Đã trả phòng','#757575'],
    'upcoming'     => ['Sắp tới','#6a1b9a'],
];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>PMS — <?php echo esc_html($hotel_name); ?></title>
<?php wp_head(); // Cần cho nonce + AJAX ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
/* ── RESET & BASE ─────────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --nav-w:220px; --nav-bg:#0f2419; --nav-hover:#1a3d2b; --nav-active:#27ae60;
    --bg:#f0f2f5; --surface:#fff; --border:#e2e8f0;
    --text:#1a202c; --muted:#718096;
    --accent:#1a6b3c; --accent2:#27ae60;
    --radius:10px; --shadow:0 1px 3px rgba(0,0,0,.1),0 1px 2px rgba(0,0,0,.06);
    --font:'Inter',system-ui,sans-serif;
    --green-bg:#e8f5e9; --green-txt:#2e7d32;
    --blue-bg:#e3f2fd;  --blue-txt:#1565c0;
    --amber-bg:#fff3e0; --amber-txt:#e65100;
    --red-bg:#fce4ec;   --red-txt:#c62828;
    --gray-bg:#f5f5f5;  --gray-txt:#616161;
}
html,body{height:100%;font-family:var(--font);background:var(--bg);color:var(--text);font-size:14px}
a{color:inherit;text-decoration:none}
button{cursor:pointer;font-family:var(--font)}
input,select,textarea{font-family:var(--font)}

/* ── LAYOUT ──────────────────────────────────────────────────────────────── */
.pms-layout{display:flex;min-height:100vh}
.pms-sidebar{
    width:var(--nav-w);background:var(--nav-bg);color:#fff;
    display:flex;flex-direction:column;
    position:fixed;top:0;left:0;height:100vh;z-index:100;
    transition:transform .25s;
}
.pms-main{margin-left:var(--nav-w);flex:1;display:flex;flex-direction:column;min-height:100vh}
@media(max-width:768px){
    .pms-sidebar{transform:translateX(-100%)}
    .pms-sidebar.open{transform:translateX(0)}
    .pms-main{margin-left:0}
}

/* ── SIDEBAR ─────────────────────────────────────────────────────────────── */
.sidebar-header{padding:20px 16px 16px;border-bottom:1px solid rgba(255,255,255,.1)}
.sidebar-hotel{font-size:13px;font-weight:600;color:rgba(255,255,255,.9);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sidebar-sub{font-size:11px;color:rgba(255,255,255,.45);margin-top:2px}
.sidebar-nav{flex:1;padding:12px 0;overflow-y:auto}
.nav-item{
    display:flex;align-items:center;gap:10px;
    padding:11px 16px;font-size:13px;font-weight:500;
    color:rgba(255,255,255,.7);border-radius:0;
    transition:all .15s;border-left:3px solid transparent;
    cursor:pointer;
}
.nav-item:hover{background:var(--nav-hover);color:#fff}
.nav-item.active{background:var(--nav-hover);color:#fff;border-left-color:var(--nav-active)}
.nav-icon{font-size:15px;width:20px;text-align:center;flex-shrink:0}
.sidebar-footer{padding:14px 16px;border-top:1px solid rgba(255,255,255,.1)}
.sidebar-user{display:flex;align-items:center;gap:9px}
.user-avatar{width:30px;height:30px;border-radius:50%;background:var(--nav-active);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0}
.user-name{font-size:12px;font-weight:500;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role{font-size:10px;color:rgba(255,255,255,.45)}
.logout-btn{margin-top:8px;width:100%;padding:7px;background:rgba(255,255,255,.08);border:none;color:rgba(255,255,255,.6);border-radius:6px;font-size:12px;transition:all .15s}
.logout-btn:hover{background:rgba(255,255,255,.15);color:#fff}

/* ── TOP BAR ─────────────────────────────────────────────────────────────── */
.pms-topbar{
    background:var(--surface);border-bottom:1px solid var(--border);
    padding:0 24px;height:56px;
    display:flex;align-items:center;justify-content:space-between;
    position:sticky;top:0;z-index:50;
}
.topbar-left{display:flex;align-items:center;gap:12px}
.topbar-title{font-size:16px;font-weight:600}
.topbar-date{font-size:12px;color:var(--muted)}
.topbar-right{display:flex;align-items:center;gap:10px}
.hamburger{display:none;background:none;border:none;font-size:20px;padding:4px}
@media(max-width:768px){.hamburger{display:block}}

/* Search bar */
.topbar-search{position:relative}
.topbar-search input{
    width:220px;height:34px;border:1px solid var(--border);border-radius:20px;
    padding:0 12px 0 34px;font-size:13px;outline:none;background:var(--bg);
    transition:all .2s;
}
.topbar-search input:focus{border-color:var(--accent2);background:#fff;width:280px}
.search-icon{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:14px;pointer-events:none}
.search-results{
    position:absolute;top:calc(100% + 6px);left:0;right:0;
    background:#fff;border:1px solid var(--border);border-radius:8px;
    box-shadow:var(--shadow);z-index:200;max-height:300px;overflow-y:auto;
    display:none;
}
.search-result-item{padding:10px 14px;border-bottom:1px solid var(--border);cursor:pointer;font-size:13px}
.search-result-item:hover{background:var(--bg)}
.search-result-item:last-child{border:none}

/* ── PAGE CONTENT ────────────────────────────────────────────────────────── */
.pms-content{flex:1;padding:24px}

/* ── CARDS & GRIDS ───────────────────────────────────────────────────────── */
.card{background:var(--surface);border-radius:var(--radius);border:1px solid var(--border);padding:20px;box-shadow:var(--shadow)}
.card-title{font-size:14px;font-weight:600;color:var(--text);margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.card-title span{font-size:12px;font-weight:400;color:var(--muted)}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
.grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:20px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
@media(max-width:1100px){.grid-4{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.grid-4,.grid-2,.grid-3{grid-template-columns:1fr}}

/* Stat card */
.stat-card{background:var(--surface);border-radius:var(--radius);border:1px solid var(--border);padding:18px 20px;box-shadow:var(--shadow)}
.stat-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:8px}
.stat-value{font-size:28px;font-weight:700;color:var(--text);line-height:1}
.stat-sub{font-size:12px;color:var(--muted);margin-top:4px}
.stat-card.accent{background:var(--accent);border-color:var(--accent)}
.stat-card.accent .stat-label,.stat-card.accent .stat-value,.stat-card.accent .stat-sub{color:#fff}

/* ── BADGE ───────────────────────────────────────────────────────────────── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap}
.badge-green{background:var(--green-bg);color:var(--green-txt)}
.badge-blue{background:var(--blue-bg);color:var(--blue-txt)}
.badge-amber{background:var(--amber-bg);color:var(--amber-txt)}
.badge-red{background:var(--red-bg);color:var(--red-txt)}
.badge-gray{background:var(--gray-bg);color:var(--gray-txt)}
.badge-purple{background:#f3e5f5;color:#6a1b9a}

/* ── TABLE ───────────────────────────────────────────────────────────────── */
.pms-table{width:100%;border-collapse:collapse;font-size:13px}
.pms-table th{text-align:left;padding:10px 12px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);border-bottom:2px solid var(--border);background:var(--bg);white-space:nowrap}
.pms-table td{padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:middle}
.pms-table tr:last-child td{border-bottom:none}
.pms-table tr:hover td{background:#fafafa}
.table-wrap{overflow-x:auto;border-radius:var(--radius);border:1px solid var(--border)}
.table-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border);flex-wrap:wrap}
.filter-row{display:flex;gap:8px;flex-wrap:wrap}

/* ── INPUTS / BUTTONS ────────────────────────────────────────────────────── */
.pms-input{height:34px;padding:0 12px;border:1px solid var(--border);border-radius:7px;font-size:13px;outline:none;font-family:var(--font);background:#fff}
.pms-input:focus{border-color:var(--accent2);box-shadow:0 0 0 3px rgba(39,174,96,.1)}
.pms-select{height:34px;padding:0 10px;border:1px solid var(--border);border-radius:7px;font-size:13px;outline:none;background:#fff;font-family:var(--font);cursor:pointer}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:7px;font-size:13px;font-weight:500;border:none;transition:all .15s;white-space:nowrap}
.btn-primary{background:var(--accent);color:#fff}.btn-primary:hover{background:var(--accent2)}
.btn-outline{background:#fff;color:var(--text);border:1px solid var(--border)}.btn-outline:hover{background:var(--bg);border-color:var(--accent)}
.btn-sm{padding:5px 10px;font-size:12px}
.btn-danger{background:var(--red-bg);color:var(--red-txt)}.btn-danger:hover{background:#ef9a9a}
.btn-success{background:var(--green-bg);color:var(--green-txt)}.btn-success:hover{background:#a5d6a7}
.btn-warning{background:var(--amber-bg);color:var(--amber-txt)}.btn-warning:hover{background:#ffcc80}
.btn:disabled{opacity:.5;cursor:not-allowed}

/* ── ROOM FLOOR MAP ──────────────────────────────────────────────────────── */
.room-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}
.room-card{border-radius:10px;padding:14px;border:1.5px solid var(--border);cursor:pointer;transition:all .2s;position:relative}
.room-card:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.12)}
.room-name{font-weight:600;font-size:14px;margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.room-guest{font-size:11px;color:var(--muted);margin-bottom:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.room-status-btn{
    position:absolute;top:10px;right:10px;
    border:none;border-radius:6px;padding:3px 8px;font-size:10px;font-weight:600;cursor:pointer;
    transition:opacity .15s;
}
.room-status-btn:hover{opacity:.8}

/* ── MODAL ───────────────────────────────────────────────────────────────── */
.modal-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;
    display:flex;align-items:center;justify-content:center;padding:20px;
}
.modal{background:#fff;border-radius:14px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-header{padding:18px 20px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-title{font-size:16px;font-weight:600}
.modal-close{background:none;border:none;font-size:20px;color:var(--muted);padding:4px;line-height:1;cursor:pointer;border-radius:6px}
.modal-close:hover{background:var(--bg);color:var(--text)}
.modal-body{padding:20px}
.modal-footer{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end}

/* Invoice */
.invoice-header{text-align:center;padding:16px 0 12px;border-bottom:2px solid var(--accent)}
.invoice-hotel{font-size:18px;font-weight:700;color:var(--accent)}
.invoice-body table{width:100%;font-size:13px;border-collapse:collapse;margin:12px 0}
.invoice-body th{text-align:left;padding:8px;background:var(--bg);font-weight:600}
.invoice-body td{padding:8px;border-bottom:1px solid var(--border)}
.invoice-total{font-size:16px;font-weight:700;text-align:right;padding:10px 0 0;color:var(--accent)}

/* ── NOTIFICATIONS ───────────────────────────────────────────────────────── */
.toast-container{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast{background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 16px;box-shadow:0 4px 16px rgba(0,0,0,.12);font-size:13px;max-width:300px;pointer-events:all;animation:slideIn .25s ease}
.toast.success{border-left:4px solid var(--accent2)}
.toast.error{border-left:4px solid var(--red-txt)}
.toast.info{border-left:4px solid #1565c0}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}

/* ── LOADING ─────────────────────────────────────────────────────────────── */
.loading-spinner{display:inline-block;width:16px;height:16px;border:2px solid var(--border);border-top-color:var(--accent2);border-radius:50%;animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.skeleton{background:linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);background-size:200%;animation:shimmer 1.5s infinite;border-radius:4px}
@keyframes shimmer{from{background-position:200%}to{background-position:-200%}}

/* ── PRINT ───────────────────────────────────────────────────────────────── */
@media print{
    .pms-sidebar,.pms-topbar,.no-print{display:none!important}
    .pms-main{margin:0}
    .modal-overlay{position:static;background:none}
    .modal{box-shadow:none;max-height:none}
}
</style>
</head>
<body class="pms-body">

<div class="pms-layout">

<!-- ═══ SIDEBAR ═══ -->
<nav class="pms-sidebar" id="pms-sidebar">
    <div class="sidebar-header">
        <div class="sidebar-hotel"><?php echo esc_html($hotel_name); ?></div>
        <div class="sidebar-sub">PMS Dashboard</div>
    </div>
    <div class="sidebar-nav">
        <?php foreach($nav_items as $key => $item): ?>
        <a href="<?php echo esc_url(home_url($item['url'])); ?>"
           class="nav-item<?php echo $current_page===$key?' active':''; ?>">
            <span class="nav-icon" style="font-size:15px"><?php echo $item['icon']; ?></span>
            <span><?php echo esc_html($item['label']); ?></span>
            <?php if($key==='checkin' && ($stats['checkins_today']+$stats['checkouts_today'])>0): ?>
            <span class="badge badge-amber" style="margin-left:auto;font-size:10px"><?php echo $stats['checkins_today']+$stats['checkouts_today']; ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><?php echo esc_html(strtoupper(substr($current_user->display_name,0,2))); ?></div>
            <div>
                <div class="user-name"><?php echo esc_html($current_user->display_name); ?></div>
                <div class="user-role">Nhân viên lễ tân</div>
            </div>
        </div>
        <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>" class="logout-btn">Đăng xuất</a>
    </div>
</nav>

<!-- ═══ MAIN ═══ -->
<div class="pms-main">

    <!-- TOP BAR -->
    <header class="pms-topbar">
        <div class="topbar-left">
            <button class="hamburger" onclick="document.getElementById('pms-sidebar').classList.toggle('open')">☰</button>
            <div>
                <div class="topbar-title"><?php echo esc_html($nav_items[$current_page]['label'] ?? 'PMS'); ?></div>
                <div class="topbar-date"><?php echo esc_html($today_str); ?></div>
            </div>
        </div>
        <div class="topbar-right">
            <div class="topbar-search">
                <span class="search-icon">🔍</span>
                <input type="text" id="pms-search" placeholder="Tìm khách, phòng, order..." autocomplete="off">
                <div class="search-results" id="search-results"></div>
            </div>
        </div>
    </header>

    <!-- PAGE CONTENT -->
    <main class="pms-content">

    <?php if($current_page === 'dashboard'): ?>
    <!-- ════════════════ DASHBOARD ════════════════ -->
    <div class="grid-4">
        <div class="stat-card accent">
            <div class="stat-label">Công suất hôm nay</div>
            <div class="stat-value"><?php echo esc_html($stats['occupancy_rate']); ?>%</div>
            <div class="stat-sub"><?php echo esc_html("{$stats['occupied_rooms']}/{$stats['total_rooms']}"); ?> phòng</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Check-in hôm nay</div>
            <div class="stat-value" style="color:#1565c0"><?php echo esc_html($stats['checkins_today']); ?></div>
            <div class="stat-sub">khách đến</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Check-out hôm nay</div>
            <div class="stat-value" style="color:#e65100"><?php echo esc_html($stats['checkouts_today']); ?></div>
            <div class="stat-sub">khách trả phòng</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Doanh thu tháng này</div>
            <div class="stat-value" style="font-size:20px;color:#2e7d32"><?php echo esc_html($stats['month_revenue_fmt']); ?>₫</div>
            <div class="stat-sub">hôm nay: <?php echo esc_html($stats['today_revenue_fmt']); ?>₫</div>
        </div>
    </div>

    <div class="grid-2">
        <!-- Check-in hôm nay -->
        <div class="card">
            <div class="card-title">Check-in hôm nay <span id="checkin-count"></span></div>
            <div id="checkin-list"><div class="skeleton" style="height:60px;margin-bottom:8px"></div><div class="skeleton" style="height:60px"></div></div>
        </div>
        <!-- Check-out hôm nay -->
        <div class="card">
            <div class="card-title">Check-out hôm nay <span id="checkout-count"></span></div>
            <div id="checkout-list"><div class="skeleton" style="height:60px;margin-bottom:8px"></div><div class="skeleton" style="height:60px"></div></div>
        </div>
    </div>

    <!-- Revenue chart -->
    <div class="card" style="margin-bottom:20px">
        <div class="card-title">Doanh thu 14 ngày gần nhất</div>
        <canvas id="revenue-chart" height="80"></canvas>
    </div>

    <!-- Phòng cần chú ý -->
    <?php if($stats['dirty_rooms']>0||$stats['maintenance_rooms']>0): ?>
    <div class="card" style="border-left:4px solid var(--amber-txt)">
        <div class="card-title" style="color:var(--amber-txt)">⚠ Phòng cần chú ý</div>
        <div style="display:flex;gap:16px;font-size:13px">
            <?php if($stats['dirty_rooms']>0): ?><span class="badge badge-amber"><?php echo esc_html($stats['dirty_rooms']); ?> phòng cần dọn</span><?php endif; ?>
            <?php if($stats['maintenance_rooms']>0): ?><span class="badge badge-red"><?php echo esc_html($stats['maintenance_rooms']); ?> phòng bảo trì</span><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php elseif($current_page === 'checkin'): ?>
    <!-- ════════════════ CHECK-IN / CHECK-OUT ════════════════ -->
    <div class="grid-2" style="margin-bottom:20px">
        <div class="card">
            <div class="card-title" style="color:#1565c0">Check-in hôm nay <span id="tab-ci-count"></span></div>
            <div id="tab-checkin-list"><div class="loading-spinner"></div></div>
        </div>
        <div class="card">
            <div class="card-title" style="color:#e65100">Check-out hôm nay <span id="tab-co-count"></span></div>
            <div id="tab-checkout-list"><div class="loading-spinner"></div></div>
        </div>
    </div>
    <div class="card">
        <div class="card-title">Đang ở <span id="inhouse-count"></span></div>
        <div id="inhouse-list"><div class="loading-spinner"></div></div>
    </div>

    <?php elseif($current_page === 'bookings'): ?>
    <!-- ════════════════ BOOKINGS ════════════════ -->
    <div class="card" style="padding:0">
        <div class="table-toolbar">
            <div class="filter-row">
                <input type="text" class="pms-input" id="bk-search" placeholder="Tìm khách / order #..." style="width:180px">
                <select class="pms-select" id="bk-status">
                    <option value="">Tất cả trạng thái</option>
                    <option value="processing">Đang xử lý</option>
                    <option value="completed">Hoàn tất</option>
                    <option value="cancelled">Đã hủy</option>
                    <option value="on-hold">Giữ phòng</option>
                    <option value="pending">Chờ thanh toán</option>
                </select>
                <input type="date" class="pms-input" id="bk-from" title="Từ ngày đặt" placeholder="Từ ngày">
                <input type="date" class="pms-input" id="bk-to"   title="Đến ngày đặt" placeholder="Đến ngày">
            </div>
            <button class="btn btn-primary" onclick="loadBookings()">Tìm kiếm</button>
        </div>
        <div class="table-wrap" style="border:none;border-radius:0">
            <table class="pms-table">
                <thead><tr>
                    <th>Order</th><th>Khách</th><th>Phòng</th>
                    <th>Check-in</th><th>Check-out</th><th>Đêm</th>
                    <th>Tổng tiền</th><th>Trạng thái</th><th>Thao tác</th>
                </tr></thead>
                <tbody id="bookings-tbody"><tr><td colspan="9" style="text-align:center;padding:30px"><div class="loading-spinner"></div></td></tr></tbody>
            </table>
        </div>
        <div style="padding:12px 16px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--border)" id="bk-pagination"></div>
    </div>

    <?php elseif($current_page === 'rooms'): ?>
    <!-- ════════════════ ROOMS ════════════════ -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px">
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php foreach($status_colors as $k=>$v): ?>
            <span class="badge" style="background:<?php echo esc_attr($v['bg']); ?>;color:<?php echo esc_attr($v['text']); ?>;cursor:pointer" onclick="filterRooms('<?php echo esc_js($k); ?>')"><?php echo esc_html($v['label']); ?></span>
            <?php endforeach; ?>
            <span class="badge badge-gray" style="cursor:pointer" onclick="filterRooms('')">Tất cả</span>
        </div>
        <button class="btn btn-outline btn-sm" onclick="loadRooms()">↻ Làm mới</button>
    </div>
    <div class="room-grid" id="room-grid"><div class="skeleton" style="height:120px"></div><div class="skeleton" style="height:120px"></div><div class="skeleton" style="height:120px"></div><div class="skeleton" style="height:120px"></div></div>

    <?php elseif($current_page === 'invoices'): ?>
    <!-- ════════════════ INVOICES ════════════════ -->
    <div class="card" style="padding:0">
        <div class="table-toolbar">
            <div class="filter-row">
                <input type="text" class="pms-input" id="inv-search" placeholder="Tìm theo tên / order #" style="width:200px">
                <select class="pms-select" id="inv-status">
                    <option value="">Tất cả</option>
                    <option value="processing">Đang xử lý</option>
                    <option value="completed">Hoàn tất</option>
                    <option value="on-hold">Giữ phòng</option>
                    <option value="cancelled">Đã hủy</option>
                </select>
                <input type="date" class="pms-input" id="inv-from" title="Từ ngày">
                <input type="date" class="pms-input" id="inv-to"   title="Đến ngày">
            </div>
            <button class="btn btn-primary" onclick="loadInvoiceList()">Tìm</button>
        </div>
        <div class="table-wrap" style="border:none;border-radius:0">
            <table class="pms-table">
                <thead><tr><th>Order</th><th>Khách</th><th>Phòng</th><th>Check-in</th><th>Check-out</th><th>Tổng</th><th>TT Thanh toán</th><th></th></tr></thead>
                <tbody id="invoice-tbody"><tr><td colspan="8" style="text-align:center;padding:30px"><div class="loading-spinner"></div></td></tr></tbody>
            </table>
        </div>
    </div>

    <?php elseif($current_page === 'reports'): ?>
    <!-- ════════════════ REPORTS ════════════════ -->
    <!-- Export toolbar -->
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
        <select class="pms-select" id="exp-status">
            <option value="">Tất cả trạng thái</option>
            <option value="processing">Đang xử lý</option>
            <option value="completed">Hoàn tất</option>
        </select>
        <button class="btn btn-primary" id="btn-export" onclick="exportExcel()">⬇ Xuất Excel (CSV)</button>
        <span style="font-size:12px;color:var(--muted)">Xuất dữ liệu theo số ngày đã chọn trong biểu đồ bên dưới</span>
    </div>
    <div class="grid-4" style="margin-bottom:20px" id="report-stats">
        <?php foreach([['Công suất','occupancy_rate','%',''],['Phòng đang có khách','occupied_rooms',' phòng',''],['Doanh thu tháng','month_revenue_fmt','₫',''],['Hôm nay','today_revenue_fmt','₫','']] as [$label,$key,$unit,$cls]): ?>
        <div class="stat-card <?php echo esc_attr($cls); ?>">
            <div class="stat-label"><?php echo esc_html($label); ?></div>
            <div class="stat-value" style="font-size:22px"><?php echo esc_html($stats[$key]); ?><?php echo esc_html($unit); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="grid-2">
        <div class="card">
            <div class="card-title">Doanh thu 30 ngày
                <select class="pms-select" id="chart-days" onchange="loadRevenueChart()">
                    <option value="14">14 ngày</option><option value="30" selected>30 ngày</option><option value="60">60 ngày</option>
                </select>
            </div>
            <canvas id="report-chart" height="120"></canvas>
        </div>
        <div class="card">
            <div class="card-title">Phân bổ trạng thái phòng</div>
            <canvas id="room-pie" height="120"></canvas>
        </div>
    </div>
    <?php endif; ?>

    </main><!-- /.pms-content -->
</div><!-- /.pms-main -->
</div><!-- /.pms-layout -->

<!-- Toast container -->
<div class="toast-container" id="toast-container"></div>

<!-- Modal container -->
<div id="modal-container"></div>

<script>
/* ── CONFIG ──────────────────────────────────────────────────────────────── */
const AJAX  = '<?php echo esc_js($ajax_url); ?>';
const NONCE = '<?php echo esc_js($nonce); ?>';
const PAGE  = '<?php echo esc_js($current_page); ?>';

/* ── TOAST ────────────────────────────────────────────────────────────────── */
function toast(msg, type='info') {
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.textContent = msg;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => { el.style.opacity='0'; el.style.transform='translateX(100%)'; }, 3000);
    setTimeout(() => el.remove(), 3400);
}

/* ── MODAL ────────────────────────────────────────────────────────────────── */
function showModal(title, bodyHTML, footerHTML='') {
    document.getElementById('modal-container').innerHTML = `
    <div class="modal-overlay" onclick="if(event.target===this)closeModal()">
      <div class="modal">
        <div class="modal-header">
          <div class="modal-title">${title}</div>
          <button class="modal-close" onclick="closeModal()">×</button>
        </div>
        <div class="modal-body">${bodyHTML}</div>
        ${footerHTML ? `<div class="modal-footer">${footerHTML}</div>` : ''}
      </div>
    </div>`;
}
function closeModal() { document.getElementById('modal-container').innerHTML = ''; }

/* ── AJAX ─────────────────────────────────────────────────────────────────── */
async function pmsPost(action, data={}) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', NONCE);
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    try {
        const r = await fetch(AJAX, {method:'POST', body:fd});
        return await r.json();
    } catch(e) {
        console.error('PMS AJAX error:', e);
        return {success:false, data:'Network error'};
    }
}

/* ── FORMAT ───────────────────────────────────────────────────────────────── */
function fmtDate(d) {
    if(!d) return '—';
    const p = d.split('-');
    return p.length===3 ? `${p[2]}/${p[1]}/${p[0]}` : d;
}
function fmtMoney(n) {
    return Number(n).toLocaleString('vi-VN') + '₫';
}
function statusBadge(wc_status, pms_status) {
    const s = pms_status || wc_status;
    const map = {
        processing:'badge-blue', completed:'badge-green',
        cancelled:'badge-red',  'on-hold':'badge-amber',
        pending:'badge-amber',  holding:'badge-amber',
        checkin_today:'badge-blue', checkout_today:'badge-amber',
        inhouse:'badge-green',  checked_out:'badge-gray',
        upcoming:'badge-purple', confirmed:'badge-blue',
    };
    const lbl = {
        processing:'Đang xử lý', completed:'Hoàn tất',
        cancelled:'Đã hủy',     'on-hold':'Chờ TT',
        pending:'Chờ TT',       holding:'Giữ phòng',
        checkin_today:'CI hôm nay', checkout_today:'CO hôm nay',
        inhouse:'Đang ở',       checked_out:'Đã trả',
        upcoming:'Sắp tới',     confirmed:'Đã xác nhận',
    };
    return `<span class="badge ${map[s]||'badge-gray'}">${lbl[s]||s}</span>`;
}

/* ── BOOKING CARD (check-in page) ─────────────────────────────────────────── */
function checkinCard(b, type) {
    const isCI = type==='checkin';
    const clr  = isCI ? '#1565c0' : '#e65100';
    return `<div style="border:1px solid var(--border);border-radius:8px;padding:12px 14px;margin-bottom:8px;border-left:4px solid ${clr}">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap">
            <div style="min-width:0">
                <div style="font-weight:600">${b.guest_name||'—'}</div>
                <div style="font-size:12px;color:var(--muted)">${b.guest_phone||''} · <strong>${b.room_name}</strong></div>
                <div style="font-size:11px;color:var(--muted);margin-top:2px">
                    ${b.adults} NL${b.children>0?', '+b.children+' TE':''} · ${b.nights} đêm
                    · ${fmtDate(b.check_in)} → ${fmtDate(b.check_out)}
                </div>
                ${b.checkin_time?`<div style="font-size:11px;color:var(--green-txt);margin-top:2px">✔ CI: ${b.checkin_time}</div>`:''}
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap">
                ${isCI && !b.checkin_time ? `<button class="btn btn-success btn-sm" onclick="doCheckin(${b.order_id})">✔ Check-in</button>` : ''}
                ${!isCI ? `<button class="btn btn-warning btn-sm" onclick="doCheckout(${b.order_id})">↩ Check-out</button>` : ''}
                <button class="btn btn-outline btn-sm" onclick="showInvoice(${b.order_id})">🧾</button>
            </div>
        </div>
    </div>`;
}

/* ── BOOKING ROW (bookings table) ─────────────────────────────────────────── */
function bookingRow(b) {
    const ciBtn  = (!b.checkin_time && ['checkin_today','holding','upcoming','confirmed'].includes(b.pms_status))
        ? `<button class="btn btn-success btn-sm" onclick="doCheckin(${b.order_id})">CI</button>` : '';
    const coBtn  = (['inhouse','checkout_today'].includes(b.pms_status) || (b.checkin_time && !b.checkout_time))
        ? `<button class="btn btn-warning btn-sm" onclick="doCheckout(${b.order_id})">CO</button>` : '';
    return `<tr>
        <td><a href="#" onclick="showInvoice(${b.order_id});return false" style="color:var(--accent);font-weight:600">#${b.order_id}</a></td>
        <td><div style="font-weight:500;white-space:nowrap">${b.guest_name||'—'}</div>
            <div style="font-size:11px;color:var(--muted)">${b.guest_phone||''}</div></td>
        <td style="font-size:13px;white-space:nowrap">${b.room_name}</td>
        <td style="white-space:nowrap">${fmtDate(b.check_in)}</td>
        <td style="white-space:nowrap">${fmtDate(b.check_out)}</td>
        <td style="text-align:center">${b.nights}</td>
        <td style="font-weight:500;color:var(--accent);white-space:nowrap">${fmtMoney(b.rba_total||b.total)}</td>
        <td>${statusBadge(b.order_status, b.pms_status)}</td>
        <td><div style="display:flex;gap:4px;flex-wrap:wrap">
            ${ciBtn}${coBtn}
            <button class="btn btn-outline btn-sm" onclick="showInvoice(${b.order_id})">🧾</button>
        </div></td>
    </tr>`;
}

/* ── CHECK-IN / CHECK-OUT ─────────────────────────────────────────────────── */
async function doCheckin(orderId) {
    const r = await pmsPost('rba_pms_do_checkin', {order_id:orderId});
    if(r.success) { toast('✓ ' + r.data.message, 'success'); setTimeout(()=>location.reload(), 1500); }
    else toast('Lỗi: ' + r.data, 'error');
}
async function doCheckout(orderId) {
    if(!confirm('Xác nhận check-out cho order #' + orderId + '?')) return;
    const r = await pmsPost('rba_pms_do_checkout', {order_id:orderId});
    if(r.success) { toast('✓ ' + r.data.message, 'success'); setTimeout(()=>location.reload(), 1500); }
    else toast('Lỗi: ' + r.data, 'error');
}

/* ── INVOICE ──────────────────────────────────────────────────────────────── */
async function showInvoice(orderId) {
    showModal('Đang tải hóa đơn...', '<div style="text-align:center;padding:30px"><div class="loading-spinner"></div></div>');
    const r = await pmsPost('rba_pms_get_invoice', {order_id:orderId});
    if(!r.success) { closeModal(); toast('Không tải được hóa đơn: ' + r.data, 'error'); return; }
    const d = r.data;

    const itemRows = d.items.map(it => `
        <tr>
            <td>${it.room_name}</td>
            <td>${it.check_in} → ${it.check_out}</td>
            <td style="text-align:center">${it.nights}</td>
            <td style="text-align:right">${it.per_night}₫/đêm</td>
            <td style="text-align:right;font-weight:600">${it.price_fmt}₫</td>
        </tr>`).join('');

    const discountRow = d.discount !== '0'
        ? `<tr><td colspan="4" style="text-align:right;color:var(--amber-txt)">Giảm giá:</td><td style="text-align:right;color:var(--amber-txt)">-${d.discount}₫</td></tr>` : '';
    const taxRow = d.tax !== '0'
        ? `<tr><td colspan="4" style="text-align:right">Thuế:</td><td style="text-align:right">${d.tax}₫</td></tr>` : '';

    const body = `
    <div id="invoice-print-area">
        <div class="invoice-header">
            <div class="invoice-hotel">${d.hotel_name}</div>
            ${d.hotel_address ? `<div style="font-size:11px;color:var(--muted)">${d.hotel_address}</div>` : ''}
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:14px 0;font-size:13px">
            <div>
                <div style="font-weight:700;font-size:14px;margin-bottom:4px">${d.guest_name}</div>
                ${d.guest_phone ? `<div>${d.guest_phone}</div>` : ''}
                ${d.guest_email ? `<div style="color:var(--muted);font-size:12px">${d.guest_email}</div>` : ''}
            </div>
            <div style="text-align:right">
                <div><strong>Order:</strong> #${d.order_number}</div>
                <div style="color:var(--muted);font-size:12px">${d.order_date}</div>
                <div style="margin-top:4px">${d.paid
                    ? '<span class="badge badge-green">Đã thanh toán</span>'
                    : '<span class="badge badge-amber">Chưa thanh toán</span>'}</div>
            </div>
        </div>
        <div class="invoice-body">
            <table>
                <thead>
                    <tr><th>Phòng</th><th>Thời gian</th><th style="text-align:center">Đêm</th><th style="text-align:right">Giá/đêm</th><th style="text-align:right">Thành tiền</th></tr>
                </thead>
                <tbody>${itemRows}</tbody>
                <tfoot>
                    ${discountRow}${taxRow}
                    <tr style="border-top:2px solid var(--accent)">
                        <td colspan="4" style="text-align:right;font-weight:700;font-size:15px;padding-top:8px">TỔNG CỘNG:</td>
                        <td style="text-align:right;font-weight:700;font-size:15px;color:var(--accent);padding-top:8px">${d.total}₫</td>
                    </tr>
                </tfoot>
            </table>
            <div style="margin-top:10px;font-size:12px;color:var(--muted)">
                Phương thức thanh toán: <strong>${d.payment_method}</strong>
                ${d.checkin_time ? ` · Check-in: <strong>${d.checkin_time}</strong>` : ''}
                ${d.checkout_time ? ` · Check-out: <strong>${d.checkout_time}</strong>` : ''}
            </div>
        </div>
        <div style="text-align:center;margin-top:16px;font-size:11px;color:var(--muted);border-top:1px dashed var(--border);padding-top:10px">
            Cảm ơn quý khách đã lựa chọn ${d.hotel_name}!
        </div>
    </div>`;

    // Thêm section đặt cọc nếu có
    let depositHtml = '';
    if(d.deposit_total > 0) {
        const remaining = Math.max(0, d.deposit_total - d.deposit_paid);
        const dep_pct = Math.round(d.deposit_paid / d.deposit_total * 100);
        depositHtml = `<div style="margin-top:12px;padding:10px 12px;background:${remaining>0?'#fff3e0':'#e8f5e9'};border-radius:8px;font-size:13px">
            <strong>Đặt cọc:</strong> ${Number(d.deposit_paid).toLocaleString('vi-VN')}₫ / ${Number(d.deposit_total).toLocaleString('vi-VN')}₫ (${dep_pct}%)
            ${remaining>0 ? `<br><strong style="color:#e65100">Còn lại: ${Number(remaining).toLocaleString('vi-VN')}₫</strong>
            <button class="btn btn-warning btn-sm no-print" style="margin-left:8px" onclick="showCollectRemaining(${d.order_id},${remaining})">Thu tiền</button>`
            : '<br><span style="color:#2e7d32">✓ Đã thanh toán đủ</span>'}
        </div>`;
    }

    const finalBody = body + depositHtml;

    showModal('🧾 Hóa đơn #' + d.order_number, finalBody,
        `<button class="btn btn-outline no-print" onclick="showDepositModal(${d.order_id},${d.total_raw||0})">💰 Đặt cọc</button>
         <button class="btn btn-outline no-print" onclick="window.print()">🖨 In</button>
         <button class="btn btn-primary no-print" onclick="closeModal()">Đóng</button>`);
}

/* ── PRINT INVOICE ───────────────────────────────────────────────────────── */
async function printInvoice(orderId) {
    await showInvoice(orderId);
    setTimeout(() => window.print(), 800);
}

/* ── DEPOSIT UI ──────────────────────────────────────────────────────────── */
function showDepositModal(orderId, totalRaw) {
    const body = `
        <div style="display:flex;flex-direction:column;gap:12px">
            <div>
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Tổng tiền phòng (₫)</label>
                <input type="number" id="dep-total" class="pms-input" style="width:100%" value="${totalRaw}" step="1000">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Số tiền đặt cọc (₫)</label>
                <input type="number" id="dep-amount" class="pms-input" style="width:100%" placeholder="VD: 500000" step="1000">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Ghi chú</label>
                <input type="text" id="dep-note" class="pms-input" style="width:100%" placeholder="Tiền mặt / chuyển khoản...">
            </div>
        </div>`;
    showModal('💰 Đặt cọc giữ phòng', body,
        `<button class="btn btn-outline" onclick="closeModal()">Hủy</button>
         <button class="btn btn-primary" onclick="saveDeposit(${orderId})">Lưu đặt cọc</button>`);
}

async function saveDeposit(orderId) {
    const total  = document.getElementById('dep-total')?.value || 0;
    const amount = document.getElementById('dep-amount')?.value || 0;
    const note   = document.getElementById('dep-note')?.value  || '';
    if(!amount || Number(amount) <= 0) { toast('Nhập số tiền đặt cọc','error'); return; }
    if(Number(amount) > Number(total)) { toast('Tiền cọc không thể lớn hơn tổng tiền','error'); return; }
    const r = await pmsPost('rba_pms_set_deposit', {order_id:orderId, deposit:amount, total, note});
    if(r.success) { toast('✓ '+r.data.message,'success'); closeModal(); setTimeout(()=>location.reload(),1500); }
    else toast('Lỗi: '+r.data,'error');
}

function showCollectRemaining(orderId, remaining) {
    const body = `
        <div style="margin-bottom:16px;padding:10px;background:#fff3e0;border-radius:8px;font-size:13px">
            <strong>Số tiền cần thu:</strong> ${Number(remaining).toLocaleString('vi-VN')}₫
        </div>
        <div style="display:flex;flex-direction:column;gap:10px">
            <div>
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Số tiền thu (₫)</label>
                <input type="number" id="rem-amount" class="pms-input" style="width:100%" value="${remaining}" step="1000">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Hình thức</label>
                <select id="rem-method" class="pms-select" style="width:100%">
                    <option value="cash">Tiền mặt</option>
                    <option value="transfer">Chuyển khoản</option>
                    <option value="card">Thẻ ngân hàng</option>
                    <option value="momo">MoMo</option>
                    <option value="zalopay">ZaloPay</option>
                </select>
            </div>
        </div>`;
    showModal('💳 Thu tiền còn lại', body,
        `<button class="btn btn-outline" onclick="closeModal()">Hủy</button>
         <button class="btn btn-primary" onclick="collectRemaining(${orderId})">Xác nhận thu tiền</button>`);
}

async function collectRemaining(orderId) {
    const amount = document.getElementById('rem-amount')?.value || 0;
    const method = document.getElementById('rem-method')?.value || 'cash';
    if(!amount || Number(amount) <= 0) { toast('Nhập số tiền','error'); return; }
    const r = await pmsPost('rba_pms_collect_remaining', {order_id:orderId, amount, method});
    if(r.success) { toast('✓ '+r.data.message,'success'); closeModal(); setTimeout(()=>location.reload(),1500); }
    else toast('Lỗi: '+r.data,'error');
}

/* ── ROOMS ────────────────────────────────────────────────────────────────── */
let roomsData = [];
let roomFilter = '';

async function loadRooms() {
    const grid = document.getElementById('room-grid');
    if(!grid) return;
    grid.innerHTML = '<div class="skeleton" style="height:120px"></div>'.repeat(4);
    const r = await pmsPost('rba_pms_get_room_status');
    if(!r.success) { toast('Lỗi tải phòng: ' + r.data, 'error'); return; }
    roomsData = r.data;
    renderRooms(roomsData);
}

function filterRooms(status) {
    roomFilter = status;
    if(!status) { renderRooms(roomsData); return; }
    renderRooms(roomsData.filter(r => {
        if(status === 'occupied') return r.is_occupied;
        return r.pms_status === status;
    }));
}

function renderRooms(rooms) {
    const grid = document.getElementById('room-grid');
    if(!grid) return;
    if(!rooms.length) {
        grid.innerHTML = '<p style="grid-column:1/-1;color:var(--muted);padding:20px">Không có phòng nào.</p>';
        return;
    }
    const sMap = {
        available: {bg:'#e8f5e9', txt:'#2e7d32', bdr:'#a5d6a7', lbl:'Trống'},
        occupied:  {bg:'#e3f2fd', txt:'#1565c0', bdr:'#90caf9', lbl:'Có khách'},
        dirty:     {bg:'#fff3e0', txt:'#e65100', bdr:'#ffcc02', lbl:'Cần dọn'},
        maintenance:{bg:'#fce4ec',txt:'#c62828', bdr:'#ef9a9a', lbl:'Bảo trì'},
        blocked:   {bg:'#f5f5f5', txt:'#616161', bdr:'#bdbdbd', lbl:'Blocked'},
    };
    grid.innerHTML = rooms.map(room => {
        const s  = room.is_occupied ? 'occupied' : (room.pms_status||'available');
        const sc = sMap[s] || sMap['available'];
        const guest = room.booking ? room.booking.guest_name : '';
        const dates = room.booking
            ? `${fmtDate(room.booking.check_in)} → ${fmtDate(room.booking.check_out)}` : '';
        const qty = room.quantity > 1 ? `<span style="font-size:10px;opacity:.7"> ×${room.quantity}</span>` : '';
        return `<div class="room-card" style="background:${sc.bg};border-color:${sc.bdr}"
                     onclick="showRoomDetail(${room.room_id})">
            <div style="font-size:10px;font-weight:700;color:${sc.txt};text-transform:uppercase;letter-spacing:.5px">
                ${room.floor ? 'Tầng '+room.floor+' · ' : ''}${sc.lbl}
            </div>
            <div class="room-name" style="color:${sc.txt}">${room.room_name}${qty}</div>
            <div class="room-guest">${guest||'Chưa có khách'}</div>
            ${dates ? `<div style="font-size:10px;color:${sc.txt};opacity:.8">${dates}</div>` : ''}
            <div style="display:flex;gap:4px;margin-top:8px;flex-wrap:wrap">
                ${room.booking ? `<button class="btn btn-outline btn-sm" style="font-size:11px" onclick="event.stopPropagation();showInvoice(${room.booking.order_id})">🧾</button>` : ''}
                <button class="btn btn-outline btn-sm" style="font-size:11px" onclick="event.stopPropagation();showRoomStatusMenu(${room.room_id},'${s}')">⚙ Trạng thái</button>
            </div>
        </div>`;
    }).join('');
}

function showRoomDetail(roomId) {
    const room = roomsData.find(r => r.room_id === roomId);
    if(!room) return;
    const s = room.is_occupied ? 'occupied' : (room.pms_status||'available');
    const sLabels = {available:'Trống',occupied:'Có khách',dirty:'Cần dọn',maintenance:'Bảo trì',blocked:'Blocked'};
    const bk = room.booking;
    const body = `
        <div style="display:flex;gap:14px;align-items:flex-start">
            ${room.thumbnail ? `<img src="${room.thumbnail}" style="width:80px;height:60px;object-fit:cover;border-radius:6px;flex-shrink:0">` : ''}
            <div>
                <div style="font-size:16px;font-weight:700">${room.room_name}</div>
                <div style="font-size:12px;color:var(--muted)">${room.floor?'Tầng '+room.floor:''}${room.hotel_name?' · '+room.hotel_name:''}</div>
                <div style="margin-top:6px">Số phòng vật lý: <strong>${room.quantity}</strong></div>
            </div>
        </div>
        <div style="margin:14px 0;padding:10px;background:var(--bg);border-radius:8px">
            <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Trạng thái hiện tại</div>
            <div style="font-size:15px;font-weight:600">${sLabels[s]||s}</div>
        </div>
        ${bk ? `<div style="border:1px solid var(--border);border-radius:8px;padding:12px">
            <div style="font-weight:600;margin-bottom:8px">Booking hiện tại</div>
            <div style="font-size:13px;line-height:2">
                Khách: <strong>${bk.guest_name}</strong><br>
                SĐT: ${bk.guest_phone||'—'}<br>
                Check-in: <strong>${fmtDate(bk.check_in)}</strong> &nbsp;→&nbsp; Check-out: <strong>${fmtDate(bk.check_out)}</strong><br>
                ${bk.checkin_time ? `Giờ CI thực tế: <strong>${bk.checkin_time}</strong><br>` : ''}
                Tổng: <strong style="color:var(--accent)">${fmtMoney(bk.rba_total||bk.total)}</strong>
            </div>
        </div>` : '<div style="color:var(--muted);font-size:13px">Phòng hiện đang trống.</div>'}`;

    const footer = `
        <button class="btn btn-outline" onclick="showRoomStatusMenu(${roomId},'${s}');closeModal()">⚙ Đổi trạng thái</button>
        ${bk ? `<button class="btn btn-primary" onclick="closeModal();showInvoice(${bk.order_id})">🧾 Xem hóa đơn</button>` : ''}
        <button class="btn btn-outline" onclick="closeModal()">Đóng</button>`;

    showModal('Phòng: ' + room.room_name, body, footer);
}

function showRoomStatusMenu(roomId, currentStatus) {
    const options = [
        ['available','Trống','btn-success'],
        ['dirty','Cần dọn phòng','btn-warning'],
        ['maintenance','Bảo trì','btn-danger'],
        ['blocked','Blocked','btn-outline'],
    ].filter(o => o[0] !== currentStatus);

    const body = `<p style="color:var(--muted);font-size:13px;margin-bottom:12px">Chọn trạng thái mới cho phòng:</p>` +
        options.map(o =>
            `<button class="btn ${o[2]}" style="width:100%;margin-bottom:8px;justify-content:center"
                     onclick="updateRoomStatus(${roomId},'${o[0]}')">
                ${o[1]}
             </button>`
        ).join('');
    showModal('Cập nhật trạng thái phòng', body);
}

async function updateRoomStatus(roomId, status) {
    closeModal();
    const r = await pmsPost('rba_pms_update_room_status', {room_id:roomId, status});
    if(r.success) { toast('Cập nhật thành công', 'success'); loadRooms(); }
    else toast(r.data, 'error');
}

/* ── BOOKINGS LIST ────────────────────────────────────────────────────────── */
async function loadBookings(offset=0) {
    const tbody = document.getElementById('bookings-tbody');
    if(!tbody) return;
    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:30px"><div class="loading-spinner"></div></td></tr>`;
    const r = await pmsPost('rba_pms_get_bookings', {
        limit:  25,
        offset: offset,
        status: document.getElementById('bk-status')?.value || '',
        search: document.getElementById('bk-search')?.value || '',
        check_in: '',
        date_from: document.getElementById('bk-from')?.value || '',
        date_to:   document.getElementById('bk-to')?.value   || '',
    });
    if(!r.success) {
        tbody.innerHTML = `<tr><td colspan="9" style="color:red;text-align:center;padding:20px">Lỗi: ${r.data}</td></tr>`;
        return;
    }
    tbody.innerHTML = r.data.length
        ? r.data.map(bookingRow).join('')
        : '<tr><td colspan="9" style="text-align:center;padding:30px;color:var(--muted)">Không có dữ liệu</td></tr>';
}

/* ── INVOICE LIST ─────────────────────────────────────────────────────────── */
async function loadInvoiceList() {
    const tbody = document.getElementById('invoice-tbody');
    if(!tbody) return;
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px"><div class="loading-spinner"></div></td></tr>`;
    const r = await pmsPost('rba_pms_get_bookings', {
        limit:  50,
        search: document.getElementById('inv-search')?.value || '',
        date_from: document.getElementById('inv-from')?.value || '',
        date_to:   document.getElementById('inv-to')?.value   || '',
        status: document.getElementById('inv-status')?.value  || '',
    });
    if(!r.success) {
        tbody.innerHTML = `<tr><td colspan="8" style="color:red;text-align:center">Lỗi: ${r.data}</td></tr>`;
        return;
    }
    tbody.innerHTML = r.data.map(b => `<tr>
        <td><a href="#" onclick="showInvoice(${b.order_id});return false" style="color:var(--accent);font-weight:600">#${b.order_id}</a></td>
        <td>${b.guest_name} <div style="font-size:11px;color:var(--muted)">${b.guest_phone}</div></td>
        <td>${b.room_name}</td>
        <td>${fmtDate(b.check_in)}</td>
        <td>${fmtDate(b.check_out)}</td>
        <td style="font-weight:600;color:var(--accent)">${fmtMoney(b.rba_total||b.total)}</td>
        <td>${statusBadge(b.order_status, b.pms_status)}</td>
        <td><div style="display:flex;gap:4px">
            <button class="btn btn-outline btn-sm" onclick="showInvoice(${b.order_id})">🧾 Xem</button>
            <button class="btn btn-outline btn-sm no-print" onclick="printInvoice(${b.order_id})">🖨</button>
        </div></td>
    </tr>`).join('') || '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--muted)">Không có dữ liệu</td></tr>';
}

/* ── CHARTS ───────────────────────────────────────────────────────────────── */
let revenueChart = null, roomPieChart = null;

async function loadRevenueChart() {
    const days = document.getElementById('chart-days')?.value || 30;
    const canvasId = PAGE === 'dashboard' ? 'revenue-chart' : 'report-chart';
    const canvas = document.getElementById(canvasId);
    if(!canvas) return;
    const r = await pmsPost('rba_pms_get_reports', {type:'revenue', days});
    if(!r.success) return;
    if(revenueChart) revenueChart.destroy();
    revenueChart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: r.data.map(d => d.label),
            datasets: [{
                label: 'Doanh thu (₫)',
                data: r.data.map(d => d.value),
                backgroundColor: 'rgba(26,107,60,.2)',
                borderColor: 'rgba(26,107,60,.8)',
                borderWidth: 1.5,
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {display:false},
                tooltip: {callbacks: {label: function(ctx){ return '  ' + fmtMoney(ctx.parsed.y); }}}
            },
            scales: {y: {ticks: {callback: function(v){
                if(v>=1000000) return (v/1000000).toFixed(1)+'tr';
                if(v>=1000)    return (v/1000).toFixed(0)+'k';
                return v;
            }}}}
        }
    });
}

async function loadRoomPie() {
    const canvas = document.getElementById('room-pie');
    if(!canvas) return;
    const r = await pmsPost('rba_pms_get_room_status');
    if(!r.success) return;
    var counts = {empty:0, occupied:0, dirty:0, maintenance:0, blocked:0};
    var countLabels = {empty:'Trống',occupied:'Có khách',dirty:'Cần dọn',maintenance:'Bảo trì',blocked:'Blocked'};
    r.data.forEach(rm => {
        var sk = rm.is_occupied ? 'occupied'
            : (rm.pms_status==='dirty' ? 'dirty'
            : (rm.pms_status==='maintenance' ? 'maintenance'
            : (rm.pms_status==='blocked' ? 'blocked' : 'empty')));
        counts[sk] = (counts[sk]||0) + (rm.quantity||1);
    });
    if(roomPieChart) roomPieChart.destroy();
    var pieLabels = Object.keys(counts).map(function(k){ return countLabels[k]; });
    var pieData   = Object.values(counts);
    roomPieChart = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: pieLabels,
            datasets: [{
                data: pieData,
                backgroundColor: ['#e8f5e9','#e3f2fd','#fff3e0','#fce4ec','#f5f5f5'],
                borderColor: ['#2e7d32','#1565c0','#e65100','#c62828','#616161'],
                borderWidth: 2,
            }]
        },
        options: {responsive:true, plugins:{legend:{position:'bottom'}}}
    });
}

/* ── EXPORT EXCEL (CSV) ───────────────────────────────────────────────────── */
async function exportExcel() {
    const btn = document.getElementById('btn-export');
    if(btn) { btn.disabled=true; btn.textContent='Đang xuất...'; }

    const days   = document.getElementById('chart-days')?.value || 30;
    const status = document.getElementById('exp-status')?.value || '';
    const r = await pmsPost('rba_pms_export_excel', {days, status});

    if(btn) { btn.disabled=false; btn.textContent='⬇ Xuất Excel (CSV)'; }
    if(!r.success) { toast('Lỗi xuất: ' + r.data, 'error'); return; }

    const { rows, filename } = r.data;

    // Tạo CSV với BOM UTF-8
    var BOM  = String.fromCharCode(0xFEFF);
    var CRLF = String.fromCharCode(13) + String.fromCharCode(10);
    var LF   = String.fromCharCode(10);
    var csvRows = rows.map(function(row) {
        return row.map(function(cell) {
            var s = String(cell == null ? '' : cell).replace(/"/g, '""');
            if(s.indexOf(',') >= 0 || s.indexOf('"') >= 0 || s.indexOf(LF) >= 0) {
                s = '"' + s + '"';
            }
            return s;
        }).join(',');
    });
    var csv = BOM + csvRows.join(CRLF);

    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = filename; a.click();
    URL.revokeObjectURL(url);
    toast(`Xuất xong ${r.data.total} booking`, 'success');
}

/* ── QUICK SEARCH ─────────────────────────────────────────────────────────── */
let searchTimer;
document.getElementById('pms-search')?.addEventListener('input', function(){
    clearTimeout(searchTimer);
    const q = this.value.trim();
    const box = document.getElementById('search-results');
    if(q.length < 2) { box.style.display='none'; return; }
    searchTimer = setTimeout(async () => {
        const r = await pmsPost('rba_pms_quick_search', {q});
        if(!r.success || !r.data.length) { box.style.display='none'; return; }
        box.innerHTML = r.data.map(b =>
            `<div class="search-result-item" onclick="showInvoice(${b.order_id});document.getElementById('search-results').style.display='none'">
                <strong>#${b.order_id}</strong> — ${b.guest_name||'—'} — ${b.room_name}
                <span style="float:right;font-size:11px;color:var(--muted)">${fmtDate(b.check_in)} → ${fmtDate(b.check_out)}</span>
            </div>`
        ).join('');
        box.style.display = 'block';
    }, 350);
});
document.addEventListener('click', e => {
    if(!e.target.closest('#pms-search'))
        document.getElementById('search-results').style.display = 'none';
});

/* ── INIT ─────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    if(PAGE === 'dashboard') {
        pmsPost('rba_pms_get_checkins_today').then(r => {
            if(!r.success) return;
            const a = r.data;
            const ciEl = document.getElementById('checkin-list');
            const coEl = document.getElementById('checkout-list');
            if(ciEl) {
                document.getElementById('checkin-count').textContent = `(${a.checkins.length})`;
                ciEl.innerHTML = a.checkins.length
                    ? a.checkins.map(b => checkinCard(b,'checkin')).join('')
                    : '<p style="color:var(--muted);font-size:13px">Không có check-in hôm nay</p>';
            }
            if(coEl) {
                document.getElementById('checkout-count').textContent = `(${a.checkouts.length})`;
                coEl.innerHTML = a.checkouts.length
                    ? a.checkouts.map(b => checkinCard(b,'checkout')).join('')
                    : '<p style="color:var(--muted);font-size:13px">Không có check-out hôm nay</p>';
            }
        });
        loadRevenueChart();
    }
    else if(PAGE === 'checkin') {
        pmsPost('rba_pms_get_checkins_today').then(r => {
            if(!r.success) return;
            const a = r.data;
            document.getElementById('tab-ci-count').textContent  = `(${a.checkins.length})`;
            document.getElementById('tab-co-count').textContent  = `(${a.checkouts.length})`;
            document.getElementById('inhouse-count').textContent = `(${a.inhouse.length})`;
            document.getElementById('tab-checkin-list').innerHTML  = a.checkins.length  ? a.checkins.map(b=>checkinCard(b,'checkin')).join('')  : '<p style="color:var(--muted);font-size:13px;padding:12px 0">Không có check-in hôm nay</p>';
            document.getElementById('tab-checkout-list').innerHTML = a.checkouts.length ? a.checkouts.map(b=>checkinCard(b,'checkout')).join('') : '<p style="color:var(--muted);font-size:13px;padding:12px 0">Không có check-out hôm nay</p>';
            document.getElementById('inhouse-list').innerHTML = a.inhouse.length
                ? a.inhouse.map(b => `<div style="padding:9px 0;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
                    <div><strong>${b.guest_name}</strong> — ${b.room_name}<br>
                    <small style="color:var(--muted)">${fmtDate(b.check_in)} → ${fmtDate(b.check_out)} · ${fmtMoney(b.rba_total||b.total)}</small></div>
                    <div style="display:flex;gap:6px">
                        <button class="btn btn-warning btn-sm" onclick="doCheckout(${b.order_id})">↩ CO</button>
                        <button class="btn btn-outline btn-sm" onclick="showInvoice(${b.order_id})">🧾</button>
                    </div>
                  </div>`).join('')
                : '<p style="color:var(--muted);font-size:13px;padding:12px 0">Không có khách đang ở</p>';
        });
    }
    else if(PAGE === 'bookings')  { loadBookings(); }
    else if(PAGE === 'rooms')     { loadRooms(); }
    else if(PAGE === 'invoices')  { loadInvoiceList(); }
    else if(PAGE === 'reports')   { loadRevenueChart(); loadRoomPie(); }
});
</script>
<?php wp_footer(); ?>
</body>
</html>
