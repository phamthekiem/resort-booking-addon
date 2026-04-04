<?php
/**
 * Template: Trang chi tiết phòng — Resort Booking Addon v1.5.2
 *
 * CÁCH CÀI:
 * Copy file này vào: wp-content/themes/TÊN_THEME/single-tf_room.php
 *
 * @package ResortBookingAddon
 */
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'RBA_Room_Data' ) ) {
    get_header(); echo '<p style="padding:40px;text-align:center">Plugin chưa active.</p>'; get_footer(); return;
}
get_header();
if ( ! have_posts() ) { get_footer(); return; }
the_post();
$room_id = get_the_ID();
$d = RBA_Room_Data::get( $room_id );
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
<style>
:root{
    --acc:#1a3d2b;--acc2:#c9a84c;--bg:#f4f2ee;--surf:#fff;
    --bor:#e5e2dc;--mut:#6b6860;--txt:#1a1a18;
    --grn:#27ae60;--red:#c0392b;--org:#e65100;
    --r:12px;--sh:0 2px 20px rgba(0,0,0,.08);
    --fh:'Playfair Display',Georgia,serif;
    --fb:'DM Sans',system-ui,sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
.rba-pg{background:var(--bg);font-family:var(--fb);color:var(--txt);padding-bottom:60px}
.rba-w{max-width:1240px;margin:0 auto;padding:0 20px}
/* Breadcrumb */
.rba-bc{padding:14px 0 20px;font-size:13px;color:var(--mut);display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.rba-bc a{color:var(--acc);text-decoration:none}.rba-bc a:hover{text-decoration:underline}
/* Grid */
.rba-g{display:grid;grid-template-columns:1fr 360px;gap:28px;align-items:start}
@media(max-width:960px){.rba-g{grid-template-columns:1fr}}
/* Gallery */
.rba-gal{border-radius:var(--r);overflow:hidden;margin-bottom:20px;background:#000;position:relative}
.rba-gal img.main{width:100%;aspect-ratio:16/9;object-fit:cover;display:block;cursor:pointer;transition:transform .4s}
.rba-gal img.main:hover{transform:scale(1.015)}
.rba-gal .cnt{position:absolute;bottom:72px;right:12px;background:rgba(0,0,0,.55);color:#fff;border-radius:20px;padding:4px 12px;font-size:12px}
.rba-thumbs{display:flex;gap:3px;padding:3px;background:#111;overflow-x:auto;scrollbar-width:thin}
.rba-thumbs img{height:62px;width:92px;object-fit:cover;cursor:pointer;border-radius:4px;opacity:.6;transition:all .2s;flex-shrink:0;border:2px solid transparent}
.rba-thumbs img.on,.rba-thumbs img:hover{opacity:1;border-color:var(--acc2)}
/* Header */
.rba-hdr{margin-bottom:20px}
.rba-hl{font-size:13px;color:var(--acc);text-decoration:none;font-weight:500}
.rba-ttl{font-family:var(--fh);font-size:clamp(22px,4vw,34px);font-weight:700;line-height:1.2;margin:6px 0 10px;color:var(--acc)}
.rba-meta{display:flex;flex-wrap:wrap;gap:12px;font-size:13px;color:var(--mut)}
.rba-meta span{display:flex;align-items:center;gap:4px}
.rba-avb{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;font-size:13px;font-weight:600;margin-top:10px}
.rba-avb.ok{background:#e8f5e9;color:#2e7d32}.rba-avb.lw{background:#fff3e0;color:var(--org)}.rba-avb.no{background:#fce4ec;color:var(--red)}
.rba-vr{display:inline-flex;align-items:center;gap:7px;background:var(--acc);color:#fff;border-radius:8px;padding:9px 18px;font-size:13px;font-weight:600;text-decoration:none;margin-top:10px;transition:opacity .2s}
.rba-vr:hover{opacity:.85;color:#fff}
/* Card */
.rba-c{background:var(--surf);border:1px solid var(--bor);border-radius:var(--r);padding:22px;margin-bottom:18px;box-shadow:var(--sh)}
.rba-ct{font-family:var(--fh);font-size:17px;font-weight:600;color:var(--acc);margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--bor)}
/* Seasons */
.rba-ss{display:flex;flex-direction:column;gap:9px;margin-bottom:16px}
.rba-si{display:flex;justify-content:space-between;align-items:center;background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:11px 15px}
.rba-sn{font-weight:600;font-size:13px}.rba-sd{font-size:11px;color:var(--mut);margin-top:2px}
.rba-sp{font-size:15px;font-weight:700;color:var(--acc);text-align:right}
.rba-sp small{font-size:11px;color:var(--mut);font-weight:400;display:block}
/* Calendar */
.rba-cn{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.rba-cn button{background:none;border:1px solid var(--bor);border-radius:8px;padding:5px 13px;cursor:pointer;font-size:15px;color:var(--txt);transition:all .15s}
.rba-cn button:hover{background:var(--acc);color:#fff;border-color:var(--acc)}
.rba-cnl{font-weight:700;font-size:14px;color:var(--acc)}
.rba-pcal{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;margin-bottom:8px}
.rba-dow{text-align:center;font-size:10px;font-weight:700;color:var(--mut);padding:5px 0;text-transform:uppercase;letter-spacing:.5px}
.rba-day{border-radius:8px;padding:5px 3px;text-align:center;cursor:pointer;min-height:50px;display:flex;flex-direction:column;align-items:center;justify-content:center;border:2px solid transparent;transition:border-color .12s,background .12s;position:relative}
.rba-day:hover:not(.p){border-color:var(--acc)}
.rba-day .n{font-size:13px;font-weight:700;line-height:1}
.rba-day .p2{font-size:9px;color:var(--mut);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:46px}
.rba-day.base{background:#f0f0f0}.rba-day.low{background:#e8f5e9}.rba-day.mid{background:#fffde7}.rba-day.high{background:#fce4ec}
.rba-day.p{opacity:.3;cursor:default;pointer-events:none}
.rba-day.tod{border-color:var(--acc)!important}
.rba-day.sa,.rba-day.sb{background:var(--acc)!important;color:#fff!important}
.rba-day.sa .p2,.rba-day.sb .p2{color:rgba(255,255,255,.75)!important}
.rba-day.ir{background:#c8e6c9!important}
.rba-leg{display:flex;gap:12px;flex-wrap:wrap;font-size:11px;color:var(--mut)}
.rba-leg span{display:flex;align-items:center;gap:4px}
.rba-dot{width:10px;height:10px;border-radius:3px;display:inline-block}
/* Included / Features */
.rba-ig{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:8px}
.rba-ii{display:flex;align-items:center;gap:7px;font-size:13px;padding:8px 10px;background:#e8f5e9;border-radius:8px}
.rba-fl{display:flex;flex-wrap:wrap;gap:8px}
.rba-ft{background:#f5f5f5;border:1px solid var(--bor);border-radius:20px;padding:5px 13px;font-size:13px}
.rba-desc{font-size:15px;line-height:1.85;color:var(--mut)}.rba-desc p{margin-bottom:12px}
.rba-cp{font-size:13px;line-height:1.7;color:var(--mut)}
/* ── Sidebar ── */
.rba-sb{position:sticky;top:20px}
.rba-bk{background:var(--surf);border:1px solid var(--bor);border-radius:var(--r);overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12)}
.rba-bh{background:var(--acc);color:#fff;padding:20px 22px}
.rba-pl{font-size:11px;opacity:.75;text-transform:uppercase;letter-spacing:1px}
.rba-pr{font-family:var(--fh);font-size:30px;font-weight:700;line-height:1;margin:4px 0 2px}
.rba-pn{font-size:12px;opacity:.65}
.rba-bb{padding:18px 22px}
/* Dates */
.rba-dr{display:grid;grid-template-columns:1fr 1fr;border:2px solid var(--bor);border-radius:10px;overflow:hidden;margin-bottom:11px}
.rba-df{padding:11px 13px}.rba-df:first-child{border-right:1px solid var(--bor)}
.rba-dl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--mut);display:block}
.rba-df{position:relative}
.rba-date-txt{border:none;outline:none;font-size:14px;font-weight:600;color:var(--txt);width:100%;padding:2px 0;background:transparent;cursor:pointer;font-family:var(--fb);caret-color:transparent}
.rba-date-txt::placeholder{color:#bbb;font-weight:400}
/* Flatpickr calendar override — khớp với theme màu resort */
.flatpickr-calendar{font-family:var(--fb)!important;border-radius:var(--r)!important;box-shadow:0 8px 32px rgba(0,0,0,.15)!important;border:1px solid var(--bor)!important}
.flatpickr-day.selected,.flatpickr-day.selected:hover{background:var(--acc)!important;border-color:var(--acc)!important}
.flatpickr-day.inRange{background:rgba(26,61,43,.1)!important;border-color:rgba(26,61,43,.1)!important}
.flatpickr-day.startRange,.flatpickr-day.endRange{background:var(--acc)!important;border-color:var(--acc)!important}
.flatpickr-day.today{border-color:var(--acc2)!important;color:var(--acc)!important;font-weight:700!important}
.flatpickr-day:hover{background:#e8f5e9!important;border-color:#e8f5e9!important}
.flatpickr-months .flatpickr-prev-month svg,.flatpickr-months .flatpickr-next-month svg{fill:var(--acc)!important}
.flatpickr-current-month .numInputWrapper input{font-family:var(--fb)!important}
/* Guests */
.rba-gu{border:2px solid var(--bor);border-radius:10px;padding:11px 13px;margin-bottom:14px;cursor:pointer;position:relative}
.rba-gul{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--mut)}
.rba-guv{font-size:14px;font-weight:600;margin-top:2px}
.rba-gd{position:absolute;left:0;right:0;top:calc(100% + 4px);background:var(--surf);border:1px solid var(--bor);border-radius:10px;padding:14px;z-index:200;box-shadow:var(--sh);display:none}
.rba-gd.open{display:block}
.rba-gr{display:flex;align-items:center;justify-content:space-between;padding:7px 0}
.rba-gr+.rba-gr{border-top:1px solid var(--bor)}
.rba-gi strong{font-size:13px}.rba-gi small{font-size:11px;color:var(--mut);display:block}
.rba-gc{display:flex;align-items:center;gap:11px}
.rba-gb{width:27px;height:27px;border-radius:50%;border:1px solid var(--bor);background:var(--surf);cursor:pointer;font-size:17px;display:flex;align-items:center;justify-content:center;line-height:1;transition:all .15s}
.rba-gb:hover{background:var(--acc);color:#fff;border-color:var(--acc)}
.rba-gn{font-weight:700;min-width:18px;text-align:center;font-size:14px}
/* Summary */
.rba-ps{background:#f9f9f7;border-radius:10px;padding:13px;margin-bottom:14px;display:none}
.rba-pr2{display:flex;justify-content:space-between;font-size:13px;padding:3px 0}
.rba-pr2.ttl{font-weight:700;font-size:15px;border-top:1px solid var(--bor);padding-top:9px;margin-top:5px}
.rba-pr2.sp{color:var(--org)}
.rba-bdt{font-size:12px;color:var(--acc);cursor:pointer;text-align:right;margin-top:3px;display:none}
.rba-bd{display:none}
/* CTA */
.rba-btn{width:100%;padding:15px;border-radius:10px;border:none;background:var(--acc2);color:var(--acc);font-family:var(--fb);font-size:15px;font-weight:700;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:7px}
.rba-btn:hover{background:var(--acc);color:#fff;transform:translateY(-1px);box-shadow:0 4px 14px rgba(26,61,43,.3)}
.rba-btn:disabled{opacity:.45;transform:none;cursor:not-allowed;background:#ccc;color:#666}
.rba-btn.ld::after{content:'';display:inline-block;width:15px;height:15px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spin .7s linear infinite}
.rba-ai{display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:11px}
.rba-ad{width:8px;height:8px;border-radius:50%;background:var(--grn);animation:pulse 2s infinite}
.rba-ad.lw{background:var(--org)}.rba-ad.no{background:var(--red);animation:none}
.rba-er{background:#fce4ec;border:1px solid #ef9a9a;border-radius:8px;padding:9px 12px;font-size:13px;color:var(--red);margin-bottom:11px;display:none}
.rba-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-radius:10px;padding:14px;text-align:center;display:none;margin-top:11px}
.rba-ok-i{font-size:22px}.rba-ok-t{font-weight:700;font-size:14px;color:#2e7d32;margin:5px 0 9px}
.rba-oks{display:flex;gap:7px}
.rba-okb{flex:1;padding:9px;border-radius:8px;font-size:13px;font-weight:600;text-align:center;text-decoration:none;cursor:pointer;border:none;transition:opacity .2s}
.rba-okb:hover{opacity:.85}
.rba-okb.p{background:var(--acc);color:#fff}.rba-okb.s{background:var(--surf);color:var(--acc);border:1px solid var(--acc)}
.rba-nt{text-align:center;font-size:12px;color:var(--mut);margin-top:9px;line-height:1.5}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
@media(max-width:480px){.rba-day{min-height:38px}.rba-day .p2{display:none}}
</style>

<div class="rba-pg">
<div class="rba-w">

    <!-- Breadcrumb -->
    <nav class="rba-bc">
        <a href="<?php echo esc_url(home_url()); ?>">Trang chủ</a> ›
        <?php if( $d['hotel_id'] ): ?>
        <a href="<?php echo esc_url($d['hotel_url']); ?>"><?php echo esc_html($d['hotel_name']); ?></a> ›
        <?php endif; ?>
        <span><?php echo esc_html($d['title']); ?></span>
    </nav>

    <div class="rba-g">
    <!-- ╔═══ MAIN ═══╗ -->
    <div>

        <!-- Gallery -->
        <div class="rba-gal">
            <img class="main" id="rba-main" src="<?php echo esc_url($d['images'][0]); ?>" alt="<?php echo esc_attr($d['title']); ?>">
            <span class="cnt" id="rba-cnt">1 / <?php echo count($d['images']); ?></span>
            <?php if(count($d['images'])>1): ?>
            <div class="rba-thumbs" id="rba-thumbs">
                <?php foreach($d['images'] as $i=>$img): ?>
                <img src="<?php echo esc_url($img); ?>" data-i="<?php echo $i; ?>" class="<?php echo $i===0?'on':''; ?>" alt="<?php echo $i+1; ?>">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Header -->
        <div class="rba-hdr">
            <?php if($d['hotel_id']): ?>
            <a href="<?php echo esc_url($d['hotel_url']); ?>" class="rba-hl">← <?php echo esc_html($d['hotel_name']); ?></a>
            <?php endif; ?>
            <h1 class="rba-ttl"><?php echo esc_html($d['title']); ?></h1>
            <div class="rba-meta">
                <?php if($d['room_size']): ?><span>📐 <?php echo esc_html($d['room_size']); ?> m²</span><?php endif; ?>
                <?php foreach($d['room_beds'] as $b): ?><span><?php echo esc_html(RBA_Room_Data::bed_label($b)); ?></span><?php endforeach; ?>
                <?php foreach($d['room_view'] as $v): ?><span><?php echo esc_html(RBA_Room_Data::view_label($v)); ?></span><?php endforeach; ?>
                <?php if($d['room_floor']): ?><span>🏢 Tầng <?php echo esc_html($d['room_floor']); ?></span><?php endif; ?>
                <span>👥 Tối đa <?php echo esc_html($d['adults_cap']); ?> người lớn<?php if($d['children_cap']): ?>, <?php echo esc_html($d['children_cap']); ?> trẻ em<?php endif; ?></span>
            </div>
            <?php
            $ac = $d['available']>3?'ok':($d['available']>0?'lw':'no');
            $at = $d['available']>3?'✅ Còn phòng trống':($d['available']>0?"⚡ Chỉ còn {$d['available']} phòng!":'❌ Hết phòng');
            ?>
            <div class="rba-avb <?php echo esc_attr($ac); ?>"><?php echo esc_html($at); ?></div>
            <?php if($d['room_virtual']): ?>
            <a href="<?php echo esc_url($d['room_virtual']); ?>" target="_blank" class="rba-vr">🔭 Tour 360°</a>
            <?php endif; ?>
        </div>

        <!-- Price calendar -->
        <?php if( $d['today_price'] > 0 ): ?>
        <div class="rba-c">
            <div class="rba-ct">Giá theo ngày</div>

            <?php if($d['seasons']): ?>
            <div class="rba-ss">
                <?php foreach($d['seasons'] as $s):
                    $sp = $s->price_type==='fixed'
                        ? RBA_Room_Data::fmt((float)$s->price_value).' ₫/đêm'
                        : ((float)$s->price_value>0?'+'.esc_html($s->price_value).'%':esc_html($s->price_value).'%');
                    $sf = date_i18n('d/m/Y', strtotime($s->date_from));
                    $st = date_i18n('d/m/Y', strtotime($s->date_to));
                ?>
                <div class="rba-si">
                    <div>
                        <div class="rba-sn"><?php echo esc_html($s->season_name ?: 'Giá đặc biệt'); ?></div>
                        <div class="rba-sd"><?php echo esc_html("$sf → $st"); ?></div>
                    </div>
                    <div class="rba-sp"><?php echo esc_html($sp); ?>
                        <small><?php echo $s->price_type==='percent'?'so với giá thường':''; ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="rba-cn">
                <button id="rba-prev" type="button">‹</button>
                <span class="rba-cnl" id="rba-lbl">Đang tải...</span>
                <button id="rba-next" type="button">›</button>
            </div>
            <!-- DOWs rendered by PHP, days by JS -->
            <div class="rba-pcal" id="rba-pcal">
                <div style="grid-column:1/-1;text-align:center;padding:18px;color:#888;font-size:13px">Đang tải lịch giá...</div>
            </div>
            <div class="rba-leg">
                <span><span class="rba-dot" style="background:#e8f5e9"></span> Giá thấp</span>
                <span><span class="rba-dot" style="background:#fffde7"></span> Bình thường</span>
                <span><span class="rba-dot" style="background:#fce4ec"></span> Cao điểm</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Description -->
        <?php if($d['description']): ?>
        <div class="rba-c">
            <div class="rba-ct">Mô tả phòng</div>
            <div class="rba-desc"><?php echo wp_kses_post(wpautop($d['description'])); ?></div>
        </div>
        <?php endif; ?>

        <!-- Included -->
        <?php if($d['room_included']): ?>
        <div class="rba-c">
            <div class="rba-ct">Dịch vụ bao gồm</div>
            <div class="rba-ig">
                <?php foreach($d['room_included'] as $inc): ?>
                <div class="rba-ii"><?php echo esc_html(RBA_Room_Data::included_label($inc)); ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Features -->
        <?php if($d['features']): ?>
        <div class="rba-c">
            <div class="rba-ct">Tiện nghi & Đặc điểm</div>
            <div class="rba-fl">
                <?php foreach($d['features'] as $f): ?>
                <span class="rba-ft">✓ <?php echo esc_html($f); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cancellation -->
        <?php if($d['room_cancel']): ?>
        <div class="rba-c">
            <div class="rba-ct">Chính sách hủy</div>
            <div class="rba-cp"><?php echo wp_kses_post(wpautop($d['room_cancel'])); ?></div>
        </div>
        <?php endif; ?>

    </div><!-- /main -->

    <!-- ╔═══ SIDEBAR ═══╗ -->
    <div class="rba-sb">
    <div class="rba-bk">
        <div class="rba-bh">
            <div class="rba-pl">Giá từ</div>
            <div class="rba-pr" id="rba-price">
                <?php echo RBA_Room_Data::fmt($d['today_price']>0?$d['today_price']:$d['base_price']); ?>
                <span style="font-size:14px;opacity:.75">₫</span>
            </div>
            <div class="rba-pn">/đêm · Chưa bao gồm thuế</div>
        </div>
        <div class="rba-bb">
            <?php
            $dc=$d['available']>3?'':($d['available']>0?'lw':'no');
            $dm=$d['available']>3?'Phòng đang trống':($d['available']>0?"Chỉ còn {$d['available']} phòng":'Hết phòng hôm nay');
            ?>
            <div class="rba-ai">
                <span class="rba-ad <?php echo esc_attr($dc); ?>"></span>
                <span id="rba-avt"><?php echo esc_html($dm); ?></span>
            </div>
            <div class="rba-er" id="rba-er"></div>

            <!-- Dates — custom picker format dd/mm/yyyy -->
            <!--
                Dùng hidden input[type=hidden] lưu giá trị YYYY-MM-DD để gửi đi.
                Hiển thị: input[type=text] format dd/mm/yyyy cho user.
                Flatpickr (cdnjs, free, không cần npm) xử lý calendar popup.
            -->
            <div class="rba-dr">
                <div class="rba-df">
                    <span class="rba-dl">Nhận phòng</span>
                    <input type="text" id="rba-ci-display" class="rba-date-txt"
                           placeholder="dd/mm/yyyy" autocomplete="off" readonly>
                    <input type="hidden" id="rba-ci"
                           value="<?php echo esc_attr($d['today']); ?>"
                           data-min="<?php echo esc_attr($d['today']); ?>">
                </div>
                <div class="rba-df">
                    <span class="rba-dl">Trả phòng</span>
                    <input type="text" id="rba-co-display" class="rba-date-txt"
                           placeholder="dd/mm/yyyy" autocomplete="off" readonly>
                    <input type="hidden" id="rba-co"
                           value="<?php echo esc_attr($d['tomorrow']); ?>"
                           data-min="<?php echo esc_attr($d['tomorrow']); ?>">
                </div>
            </div>

            <!-- Guests -->
            <div class="rba-gu" id="rba-gu">
                <div class="rba-gul">Khách</div>
                <div class="rba-guv" id="rba-guv">2 người lớn</div>
                <div class="rba-gd" id="rba-gd">
                    <div class="rba-gr">
                        <div class="rba-gi"><strong>Người lớn</strong><small>Từ 13 tuổi</small></div>
                        <div class="rba-gc">
                            <button class="rba-gb" data-t="a" data-act="dec" type="button">−</button>
                            <span class="rba-gn" id="cnt-a">2</span>
                            <button class="rba-gb" data-t="a" data-act="inc" data-mx="<?php echo esc_attr($d['adults_cap']); ?>" type="button">+</button>
                        </div>
                    </div>
                    <div class="rba-gr">
                        <div class="rba-gi"><strong>Trẻ em</strong><small>0–12 tuổi</small></div>
                        <div class="rba-gc">
                            <button class="rba-gb" data-t="c" data-act="dec" type="button">−</button>
                            <span class="rba-gn" id="cnt-c">0</span>
                            <button class="rba-gb" data-t="c" data-act="inc" data-mx="<?php echo esc_attr($d['children_cap']); ?>" type="button">+</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary -->
            <div class="rba-ps" id="rba-ps">
                <div class="rba-pr2"><span id="rba-sl"></span><span id="rba-ss"></span></div>
                <div class="rba-bdt" id="rba-bdt">Xem chi tiết ▾</div>
                <div class="rba-bd" id="rba-bd"></div>
                <div class="rba-pr2 ttl"><span>Tổng</span><span id="rba-st" style="color:var(--acc)"></span></div>
            </div>

            <button class="rba-btn" id="rba-book" type="button" <?php if($d['available']<=0)echo 'disabled'; ?>>
                <?php echo $d['available']>0?'🏖 Đặt phòng ngay':'Hết phòng'; ?>
            </button>

            <div class="rba-ok" id="rba-ok">
                <div class="rba-ok-i">✅</div>
                <div class="rba-ok-t">Đã thêm vào giỏ hàng!</div>
                <div class="rba-oks">
                    <a href="<?php echo esc_url($d['cart_url']); ?>" class="rba-okb s">Xem giỏ</a>
                    <a href="<?php echo esc_url($d['checkout_url']); ?>" class="rba-okb p">Thanh toán →</a>
                </div>
            </div>
            <div class="rba-nt">🔒 Thanh toán bảo mật · Miễn phí hủy 24h</div>
        </div>
    </div>
    </div><!-- /sidebar -->
    </div><!-- /grid -->
</div><!-- /wrap -->
</div><!-- /page -->

<script>
/* ── Room Page JS — inline, no external dependency ── */
(function(){
'use strict';
var RID   = <?php echo (int)$room_id; ?>;
var NONCE = <?php echo wp_json_encode(wp_create_nonce('rba_public_nonce')); ?>;
var AJAX  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
var IMGS  = <?php echo wp_json_encode(array_values($d['images'])); ?>;
var TODAY = <?php echo wp_json_encode(current_time('Y-m-d')); ?>;
var CARTURL  = <?php echo wp_json_encode($d['cart_url']); ?>;
var CKOUT    = <?php echo wp_json_encode($d['checkout_url']); ?>;

/* ──────────────────────────────────────────────────────────────
   GALLERY
────────────────────────────────────────────────────────────── */
var curImg = 0;
function showImg(i) {
    curImg = i;
    var mainEl = document.getElementById('rba-main');
    var cntEl  = document.getElementById('rba-cnt');
    if(mainEl) mainEl.src = IMGS[i];
    if(cntEl)  cntEl.textContent = (i+1) + ' / ' + IMGS.length;
    var thumbs = document.querySelectorAll('.rba-thumbs img');
    thumbs.forEach(function(el, j){ el.classList.toggle('on', j===i); });
}
document.querySelectorAll('.rba-thumbs img').forEach(function(el){
    el.addEventListener('click', function(){ showImg(parseInt(this.dataset.i)||0); });
});
var mainImg = document.getElementById('rba-main');
if(mainImg) mainImg.addEventListener('click', function(){ showImg((curImg+1)%IMGS.length); });

/* ──────────────────────────────────────────────────────────────
   PRICE CALENDAR
────────────────────────────────────────────────────────────── */
var cY, cM, cData = null;
var selA = null, selB = null;

function fmtDate(d) {
    var m = d.getMonth()+1, day = d.getDate();
    return d.getFullYear() + '-' + (m<10?'0':'') + m + '-' + (day<10?'0':'') + day;
}
function fmtShort(n) {
    if(!n || n<=0) return '';
    if(n >= 1000000) return (n/1000000).toFixed(1).replace(/\.0$/,'') + 'tr';
    if(n >= 1000)    return Math.round(n/1000) + 'k';
    return String(Math.round(n));
}

function loadCal(y, m) {
    var lbl = document.getElementById('rba-lbl');
    var ph  = document.getElementById('rba-cal-placeholder');
    if(lbl) lbl.textContent = 'Đang tải...';
    if(ph)  ph.style.display = 'block';

    var fd = new FormData();
    fd.append('action',  'rba_get_price_calendar');
    fd.append('nonce',   NONCE);
    fd.append('room_id', RID);
    fd.append('year',    y);
    fd.append('month',   m);

    fetch(AJAX, {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(r){
            if(!r || !r.success || !r.data) {
                if(lbl) lbl.textContent = 'Không tải được lịch';
                return;
            }
            cData = r.data;
            renderCal(r.data);
        })
        .catch(function(e){
            console.error('Calendar load error:', e);
            if(lbl) lbl.textContent = 'Lỗi kết nối';
        });
}

function renderCal(data) {
    var cal = document.getElementById('rba-pcal');
    var lbl = document.getElementById('rba-lbl');
    if(!cal || !data) return;

    if(lbl) lbl.textContent = data.label || '';

    /* Xóa toàn bộ nội dung cũ */
    cal.innerHTML = '';

    /* Render 7 ô DOW headers (T2...CN) */
    var DOWS = ['T2','T3','T4','T5','T6','T7','CN'];
    DOWS.forEach(function(d){
        var el = document.createElement('div');
        el.className = 'rba-dow';
        el.textContent = d;
        cal.appendChild(el);
    });

    /* Kiểm tra prices tồn tại */
    if(!data.prices || !data.prices.length) {
        var msg = document.createElement('div');
        msg.style.cssText = 'grid-column:1/-1;text-align:center;padding:16px;color:#888;font-size:13px';
        msg.textContent = 'Không có dữ liệu giá';
        cal.appendChild(msg);
        return;
    }

    /* Offset: ISO weekday 1=Mon→0 empty cells, 7=Sun→6 empty cells */
    var offset = ((parseInt(data.weekday) - 1) + 7) % 7;
    for(var e = 0; e < offset; e++) {
        var blank = document.createElement('div');
        cal.appendChild(blank);
    }

    data.prices.forEach(function(p) {
        var isPast  = p.date < TODAY;
        var isToday = p.date === TODAY;
        var isSA    = p.date === selA;
        var isSB    = p.date === selB;
        var inRange = selA && selB && p.date > selA && p.date < selB;

        var el = document.createElement('div');
        var cls = 'rba-day ' + (p.level || 'base');
        if(isPast)  cls += ' p';
        if(isToday) cls += ' tod';
        if(isSA)    cls += ' sa';
        if(isSB)    cls += ' sb';
        if(inRange) cls += ' ir';
        el.className = cls;
        el.dataset.d = p.date;
        el.dataset.price = p.price;

        var dayNum = new Date(p.date + 'T00:00:00').getDate();
        var ps = fmtShort(p.price);
        el.innerHTML = '<span class="n">' + dayNum + '</span>' +
                       (ps ? '<span class="p2">' + ps + '</span>' : '');

        if(!isPast) {
            el.addEventListener('click', function(){
                var d  = this.dataset.d;
                var ci = document.getElementById('rba-ci');
                var co = document.getElementById('rba-co');
                /* Logic: click 1 = set check-in; click 2 = set check-out nếu sau */
                if(!selA || (selA && selB) || d <= selA) {
                    selA = d;
                    selB = null;
                    if(ci) ci.value = d;
                    var nxt = new Date(d + 'T00:00:00');
                    nxt.setDate(nxt.getDate() + 1);
                    selB = fmtDate(nxt);
                    if(co) co.value = selB;
                } else {
                    selB = d;
                    if(co) co.value = d;
                }
                renderCal(cData);
                scheduleCheck();
            });
        }
        cal.appendChild(el);
    });
}

/* Init calendar */
var calEl = document.getElementById('rba-pcal');
if(calEl) {
    var now = new Date();
    cY = now.getFullYear(); cM = now.getMonth()+1;
    loadCal(cY, cM);

    var prevBtn = document.getElementById('rba-prev');
    var nextBtn = document.getElementById('rba-next');
    if(prevBtn) prevBtn.addEventListener('click', function(){
        cM--; if(cM<1){cM=12;cY--;} loadCal(cY,cM);
    });
    if(nextBtn) nextBtn.addEventListener('click', function(){
        cM++; if(cM>12){cM=1;cY++;} loadCal(cY,cM);
    });
}

/* ──────────────────────────────────────────────────────────────
   GUESTS
────────────────────────────────────────────────────────────── */
var G = {a:2, c:0};
var guEl = document.getElementById('rba-gu');
var gdEl = document.getElementById('rba-gd');
if(guEl && gdEl) {
    guEl.addEventListener('click', function(e){
        if(e.target.closest('.rba-gb')) return;
        gdEl.classList.toggle('open');
    });
    document.addEventListener('click', function(e){
        if(!e.target.closest('#rba-gu')) gdEl.classList.remove('open');
    });
}
document.querySelectorAll('.rba-gb').forEach(function(btn){
    btn.addEventListener('click', function(){
        var t   = this.dataset.t;
        var act = this.dataset.act;
        var mx  = parseInt(this.dataset.mx || 99);
        if(act === 'inc') G[t] = Math.min(G[t]+1, mx);
        else              G[t] = Math.max(G[t]-1, t==='a' ? 1 : 0);
        var cntEl = document.getElementById('cnt-'+t);
        if(cntEl) cntEl.textContent = G[t];
        var guvEl = document.getElementById('rba-guv');
        if(guvEl) guvEl.textContent = G.a + ' người lớn' + (G.c > 0 ? ', ' + G.c + ' trẻ em' : '');
    });
});

/* ──────────────────────────────────────────────────────────────
   CHECK AVAILABILITY + PRICE
────────────────────────────────────────────────────────────── */
var chkTimer = null;
function scheduleCheck(){ clearTimeout(chkTimer); chkTimer = setTimeout(doCheck, 450); }

function doCheck() {
    var ciEl = document.getElementById('rba-ci');
    var coEl = document.getElementById('rba-co');
    if(!ciEl || !coEl) return;
    var ci = ciEl.value, co = coEl.value;
    if(!ci || !co || co <= ci) return;

    /* Sync selection với calendar */
    selA = ci; selB = co;
    if(cData) renderCal(cData);

    var fd = new FormData();
    fd.append('action',    'rba_room_check');
    fd.append('nonce',     NONCE);
    fd.append('room_id',   RID);
    fd.append('check_in',  ci);
    fd.append('check_out', co);

    fetch(AJAX, {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(r){
            if(!r || !r.success || !r.data) return;
            applyCheckResult(r.data);
        })
        .catch(function(e){ console.error('Check error:', e); });
}

function applyCheckResult(data) {
    /* Availability dot + text */
    var dot = document.querySelector('.rba-ad');
    var avt = document.getElementById('rba-avt');
    var btn = document.getElementById('rba-book');

    if(dot) {
        dot.className = 'rba-ad' + (!data.available ? ' no' : data.rooms_left <= 3 ? ' lw' : '');
    }
    if(avt) {
        avt.textContent = !data.available
            ? 'Hết phòng trong khoảng này'
            : data.rooms_left <= 3 ? 'Chỉ còn ' + data.rooms_left + ' phòng!' : 'Còn phòng trống';
    }
    if(btn) {
        btn.disabled = !data.available;
        if(!data.available) { btn.textContent = 'Không còn phòng'; }
        else { btn.innerHTML = '🏖 Đặt phòng ngay'; }
    }

    /* Sidebar price per night */
    if(data.nights > 0) {
        var ppn = Math.round(data.per_night);
        var prEl = document.getElementById('rba-price');
        if(prEl) prEl.innerHTML = ppn.toLocaleString('vi-VN') + '<span style="font-size:14px;opacity:.75">₫</span>';
    }

    /* Price summary */
    var psEl = document.getElementById('rba-ps');
    if(psEl) psEl.style.display = 'block';

    var slEl = document.getElementById('rba-sl');
    var stEl = document.getElementById('rba-st');
    if(slEl) slEl.textContent = Math.round(data.per_night).toLocaleString('vi-VN') + '₫ × ' + data.nights + ' đêm';
    if(stEl) stEl.textContent = Math.round(data.total).toLocaleString('vi-VN') + ' ₫';

    /* Breakdown */
    var bdEl  = document.getElementById('rba-bd');
    var bdtEl = document.getElementById('rba-bdt');
    var bdHTML = '';
    if(data.breakdown && data.has_vary) {
        data.breakdown.forEach(function(b){
            var dObj  = new Date(b.date + 'T00:00:00');
            var label = dObj.toLocaleDateString('vi-VN', {day:'numeric', month:'numeric'});
            bdHTML += '<div class="rba-pr2' + (b.is_special ? ' sp' : '') + '">' +
                      '<span>' + label + (b.is_special ? ' ✨' : '') + '</span>' +
                      '<span>' + Math.round(b.price).toLocaleString('vi-VN') + '₫</span></div>';
        });
    }
    if(bdEl)  bdEl.innerHTML = bdHTML;
    if(bdtEl) bdtEl.style.display = bdHTML ? 'block' : 'none';
}

/* Breakdown toggle */
var bdtEl = document.getElementById('rba-bdt');
if(bdtEl) {
    bdtEl.addEventListener('click', function(){
        var bdEl = document.getElementById('rba-bd');
        if(!bdEl) return;
        var open = bdEl.style.display === 'block';
        bdEl.style.display = open ? 'none' : 'block';
        this.textContent   = open ? 'Xem chi tiết ▾' : 'Thu gọn ▴';
    });
}

/* Date change — flatpickr onChange đã gọi scheduleCheck()
   Giữ lại listener cho hidden input phòng khi không dùng flatpickr */
var ciEl = document.getElementById('rba-ci');
var coEl = document.getElementById('rba-co');
if(ciEl) ciEl.addEventListener('change', function(){
    var co = coEl ? coEl.value : '';
    if(co && co <= this.value) {
        var d = new Date(this.value + 'T00:00:00');
        d.setDate(d.getDate() + 1);
        if(coEl) coEl.value = fmtDate(d);
    }
    scheduleCheck();
});
if(coEl) coEl.addEventListener('change', scheduleCheck);

/* ──────────────────────────────────────────────────────────────
   BOOKING
────────────────────────────────────────────────────────────── */
var bookBtn = document.getElementById('rba-book');
if(bookBtn) {
    bookBtn.addEventListener('click', function(){
        var ciEl = document.getElementById('rba-ci');
        var coEl = document.getElementById('rba-co');
        var erEl = document.getElementById('rba-er');
        var ci = ciEl ? ciEl.value : '';
        var co = coEl ? coEl.value : '';

        if(erEl) erEl.style.display = 'none';
        if(!ci || !co || co <= ci) {
            if(erEl){ erEl.textContent='Vui lòng chọn ngày hợp lệ.'; erEl.style.display='block'; }
            return;
        }

        this.disabled = true;
        this.classList.add('ld');
        this.textContent = '';

        var fd = new FormData();
        fd.append('action',    'rba_add_to_cart');
        fd.append('nonce',     NONCE);
        fd.append('room_id',   RID);
        fd.append('check_in',  ci);
        fd.append('check_out', co);
        fd.append('adults',    G.a);
        fd.append('children',  G.c);

        var self = this;
        fetch(AJAX, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(r){
                self.classList.remove('ld');
                if(r && r.success) {
                    self.style.display = 'none';
                    var okEl = document.getElementById('rba-ok');
                    if(okEl) okEl.style.display = 'block';
                } else {
                    self.disabled = false;
                    self.innerHTML = '🏖 Đặt phòng ngay';
                    var msg = (r && r.data) ? r.data : 'Có lỗi xảy ra, vui lòng thử lại.';
                    if(erEl){ erEl.textContent = msg; erEl.style.display = 'block'; }
                }
            })
            .catch(function(){
                self.disabled = false;
                self.classList.remove('ld');
                self.innerHTML = '🏖 Đặt phòng ngay';
            });
    });
}

/* ──────────────────────────────────────────────────────────────
   FLATPICKR DATE PICKER — format dd/mm/yyyy
────────────────────────────────────────────────────────────── */
function initDatePickers() {
    if (typeof flatpickr === 'undefined') {
        // Flatpickr chưa load — thử lại sau 200ms
        setTimeout(initDatePickers, 200);
        return;
    }

    var todayStr    = TODAY; // YYYY-MM-DD từ PHP
    var tomorrowStr = (function(){
        var d = new Date(TODAY + 'T00:00:00');
        d.setDate(d.getDate() + 1);
        return fmtDate(d);
    })();

    // Format hiển thị: dd/mm/yyyy
    var DISPLAY_FORMAT = 'd/m/Y';

    // Xác nhận giá trị hidden input ban đầu và hiển thị
    function syncDisplay(hiddenId, displayId) {
        var val = document.getElementById(hiddenId).value;
        if (val) {
            var parts = val.split('-'); // YYYY-MM-DD
            if (parts.length === 3) {
                document.getElementById(displayId).value = parts[2] + '/' + parts[1] + '/' + parts[0];
            }
        }
    }
    syncDisplay('rba-ci', 'rba-ci-display');
    syncDisplay('rba-co', 'rba-co-display');

    // Check-in picker
    var fpCI = flatpickr('#rba-ci-display', {
        dateFormat:    'd/m/Y',        // format hiển thị cho user
        altInput:      false,
        minDate:       todayStr,
        defaultDate:   todayStr,
        locale: {
            firstDayOfWeek: 1,         // Tuần bắt đầu từ Thứ 2
            weekdays: {
                shorthand: ['CN','T2','T3','T4','T5','T6','T7'],
                longhand:  ['Chủ Nhật','Thứ Hai','Thứ Ba','Thứ Tư','Thứ Năm','Thứ Sáu','Thứ Bảy']
            },
            months: {
                shorthand: ['Th1','Th2','Th3','Th4','Th5','Th6','Th7','Th8','Th9','Th10','Th11','Th12'],
                longhand:  ['Tháng 1','Tháng 2','Tháng 3','Tháng 4','Tháng 5','Tháng 6',
                            'Tháng 7','Tháng 8','Tháng 9','Tháng 10','Tháng 11','Tháng 12']
            }
        },
        onChange: function(selectedDates, dateStr) {
            if (!selectedDates.length) return;
            var isoDate = fmtDate(selectedDates[0]);  // YYYY-MM-DD cho hidden input
            document.getElementById('rba-ci').value = isoDate;

            // Tự động update minDate của check-out picker
            var nextDay = new Date(selectedDates[0]);
            nextDay.setDate(nextDay.getDate() + 1);
            fpCO.set('minDate', nextDay);

            // Nếu check-out ≤ check-in mới → đẩy check-out lên ngày hôm sau
            var coVal = document.getElementById('rba-co').value;
            if (coVal && coVal <= isoDate) {
                var newCO = fmtDate(nextDay);
                document.getElementById('rba-co').value = newCO;
                fpCO.setDate(nextDay, true);
            }

            scheduleCheck();
        }
    });

    // Check-out picker
    var fpCO = flatpickr('#rba-co-display', {
        dateFormat:    'd/m/Y',
        altInput:      false,
        minDate:       tomorrowStr,
        defaultDate:   tomorrowStr,
        locale: {
            firstDayOfWeek: 1,
            weekdays: {
                shorthand: ['CN','T2','T3','T4','T5','T6','T7'],
                longhand:  ['Chủ Nhật','Thứ Hai','Thứ Ba','Thứ Tư','Thứ Năm','Thứ Sáu','Thứ Bảy']
            },
            months: {
                shorthand: ['Th1','Th2','Th3','Th4','Th5','Th6','Th7','Th8','Th9','Th10','Th11','Th12'],
                longhand:  ['Tháng 1','Tháng 2','Tháng 3','Tháng 4','Tháng 5','Tháng 6',
                            'Tháng 7','Tháng 8','Tháng 9','Tháng 10','Tháng 11','Tháng 12']
            }
        },
        onChange: function(selectedDates, dateStr) {
            if (!selectedDates.length) return;
            var isoDate = fmtDate(selectedDates[0]);
            document.getElementById('rba-co').value = isoDate;
            scheduleCheck();
        }
    });

    // Khi click ngày trên calendar lớn (rba-pcal) → sync vào flatpickr
    // Override phần click calendar day để set flatpickr thay vì input trực tiếp
    var origClickDay = window._rba_calendar_day_click;
    document.getElementById('rba-pcal').addEventListener('click', function(e) {
        var day = e.target.closest('.rba-day:not(.p)');
        if (!day) return;
        var d = day.dataset.d;
        if (!d) return;

        var ciVal = document.getElementById('rba-ci').value;
        var coVal = document.getElementById('rba-co').value;

        if (!ciVal || (ciVal && coVal) || d <= ciVal) {
            // Set check-in
            fpCI.setDate(d, true);    // true = trigger onChange
        } else {
            // Set check-out
            fpCO.setDate(d, true);
        }
    }, true); // capture phase để chạy trước handler cũ
}

/* Load Flatpickr từ CDN rồi init */
(function loadFlatpickr() {
    if (typeof flatpickr !== 'undefined') {
        initDatePickers();
        return;
    }
    var s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js';
    s.onload = initDatePickers;
    s.onerror = function() {
        // Fallback: nếu CDN lỗi, dùng input[type=date] ẩn trực tiếp
        console.warn('RBA: Flatpickr CDN failed, falling back to native date picker');
        document.querySelectorAll('.rba-date-txt').forEach(function(el) {
            el.type = 'date';
            el.style.cssText = 'border:none;outline:none;font-size:14px;font-weight:600;width:100%;padding:2px 0;background:transparent;cursor:pointer';
        });
    };
    document.head.appendChild(s);
})();

/* Init: tính giá mặc định hôm nay → ngày mai */
scheduleCheck();

})();
</script>

<?php get_footer(); ?>
