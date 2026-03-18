<?php
/**
 * Template: Search Form nâng cao
 * Được include bởi RBA_Search::shortcode_search_form()
 */
defined('ABSPATH') || exit;

$hotel_id   = (int) ($atts['hotel_id'] ?? 0);
$show_tours = (bool) ($atts['show_tours'] ?? false);
$compact    = (bool) ($atts['compact'] ?? false);
?>
<div class="rba-search-form <?php echo $compact ? 'compact' : ''; ?>" id="rba-search-<?php echo uniqid(); ?>">

    <?php if ( ! $compact ) : ?>
    <div class="rba-search-tabs">
        <button class="tab active" data-tab="rooms">🏨 Đặt phòng</button>
        <?php if ($show_tours) : ?>
        <button class="tab" data-tab="tours">🗺️ Đặt tour</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="rba-tab-content active" data-content="rooms">
        <form class="rba-room-search-form" method="get">
            <?php if ($hotel_id) : ?>
                <input type="hidden" name="hotel_id" value="<?php echo $hotel_id; ?>">
            <?php endif; ?>

            <div class="rba-form-row">
                <div class="rba-field">
                    <label>📅 Ngày check-in</label>
                    <input type="date" name="check_in" id="rba-check-in"
                           value="<?php echo esc_attr($_GET['check_in'] ?? ''); ?>"
                           min="<?php echo date('Y-m-d'); ?>"
                           required>
                </div>
                <div class="rba-field">
                    <label>📅 Ngày check-out</label>
                    <input type="date" name="check_out" id="rba-check-out"
                           value="<?php echo esc_attr($_GET['check_out'] ?? ''); ?>"
                           min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                           required>
                </div>
                <div class="rba-field small">
                    <label>👤 Người lớn</label>
                    <select name="adults" id="rba-adults">
                        <?php for ($i=1; $i<=10; $i++) : ?>
                        <option value="<?php echo $i; ?>" <?php selected((int)($_GET['adults']??1), $i); ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="rba-field small">
                    <label>🧒 Trẻ em</label>
                    <select name="children">
                        <?php for ($i=0; $i<=6; $i++) : ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <?php if ( ! $compact ) : ?>
            <div class="rba-form-row rba-advanced">
                <div class="rba-field">
                    <label>🪟 Hướng nhìn</label>
                    <select name="view">
                        <option value="">Tất cả</option>
                        <option value="sea">🌊 Hướng biển</option>
                        <option value="garden">🌿 Hướng vườn</option>
                        <option value="pool">🏊 Hướng hồ bơi</option>
                        <option value="mountain">⛰️ Hướng núi</option>
                    </select>
                </div>
                <div class="rba-field">
                    <label>💰 Giá tối đa / đêm</label>
                    <input type="number" name="max_price" placeholder="VD: 3000000" step="100000">
                </div>
            </div>
            <?php endif; ?>

            <div class="rba-form-actions">
                <button type="submit" class="rba-btn-search">
                    🔍 Tìm phòng trống
                </button>
            </div>
        </form>

        <!-- Results container -->
        <div class="rba-search-results" id="rba-room-results" style="display:none;">
            <div class="rba-results-header">
                <span class="rba-results-count"></span>
                <span class="rba-results-dates"></span>
            </div>
            <div class="rba-results-grid"></div>
            <div class="rba-no-results" style="display:none;">
                <p>😔 Không có phòng trống trong khoảng thời gian đã chọn.</p>
                <p>Vui lòng chọn ngày khác hoặc <a href="tel:+84xxx">liên hệ trực tiếp</a> để được hỗ trợ.</p>
            </div>
        </div>
    </div>

    <?php if ($show_tours) : ?>
    <div class="rba-tab-content" data-content="tours">
        <form class="rba-tour-search-form">
            <div class="rba-form-row">
                <div class="rba-field">
                    <label>📅 Ngày tham quan</label>
                    <input type="date" name="tour_date" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="rba-field small">
                    <label>👤 Người lớn</label>
                    <input type="number" name="tour_adults" value="1" min="1" max="20">
                </div>
                <div class="rba-field small">
                    <label>🧒 Trẻ em</label>
                    <input type="number" name="tour_children" value="0" min="0" max="10">
                </div>
            </div>
            <div class="rba-form-actions">
                <button type="submit" class="rba-btn-search">🗺️ Xem tour có sẵn</button>
            </div>
        </form>
        <div id="rba-tour-results"></div>
    </div>
    <?php endif; ?>

</div>

<script>
(function($){
    const form  = $('.rba-room-search-form');
    const cfg   = window.rba_search_config || {};

    // Validate: check-out phải sau check-in
    $('#rba-check-in').on('change', function(){
        const cin = $(this).val();
        if (cin) {
            const next = new Date(cin);
            next.setDate(next.getDate() + 1);
            $('#rba-check-out').attr('min', next.toISOString().split('T')[0]);
        }
    });

    // AJAX search
    form.on('submit', function(e){
        e.preventDefault();
        const $btn = $(this).find('.rba-btn-search');
        $btn.prop('disabled', true).text('⏳ Đang tìm...');
        const data = {
            action:    'rba_search_rooms',
            nonce:     cfg.nonce,
            check_in:  $('#rba-check-in').val(),
            check_out: $('#rba-check-out').val(),
            adults:    $('#rba-adults').val(),
            view:      $('[name="view"]').val(),
            max_price: $('[name="max_price"]').val(),
        };
        <?php if ($hotel_id) : ?>
        data.hotel_id = <?php echo $hotel_id; ?>;
        <?php endif; ?>

        $.post(cfg.ajax_url, data, function(res){
            $btn.prop('disabled', false).text('🔍 Tìm phòng trống');
            const $results = $('#rba-room-results');
            $results.show();

            if (!res.success || res.data.count === 0) {
                $results.find('.rba-results-grid').empty();
                $results.find('.rba-no-results').show();
                $results.find('.rba-results-count').text('');
                return;
            }
            $results.find('.rba-no-results').hide();
            $results.find('.rba-results-count').text(`${res.data.count} phòng trống`);
            $results.find('.rba-results-dates').text(`(${formatDate(res.data.dates.check_in)} → ${formatDate(res.data.dates.check_out)}, ${res.data.dates.nights} đêm)`);

            const grid = $results.find('.rba-results-grid').empty();
            res.data.rooms.forEach(function(room){
                const priceNight = parseInt(room.price_night).toLocaleString('vi-VN');
                const priceTotal = parseInt(room.price_total).toLocaleString('vi-VN');
                const thumb = room.thumb ? `<img src="${room.thumb}" alt="${room.title}">` : '';
                grid.append(`
                    <div class="rba-result-card">
                        ${thumb}
                        <div class="rba-result-info">
                            <h3>${room.title}</h3>
                            <div class="rba-room-meta">
                                ${room.size ? `<span>📐 ${room.size}m²</span>` : ''}
                                ${room.view.length ? `<span>🪟 ${room.view.join(', ')}</span>` : ''}
                            </div>
                            <div class="rba-price">
                                <strong>${priceNight} VNĐ</strong>/đêm
                                <small>Tổng: ${priceTotal} VNĐ</small>
                            </div>
                            <a href="${room.url}" class="rba-btn-book">Đặt ngay →</a>
                        </div>
                    </div>`);
            });
        });
    });

    function formatDate(d){
        const p = d.split('-');
        return `${p[2]}/${p[1]}/${p[0]}`;
    }

    // Tabs
    $('.rba-search-tabs .tab').on('click', function(){
        const tab = $(this).data('tab');
        $('.rba-search-tabs .tab').removeClass('active');
        $(this).addClass('active');
        $('.rba-tab-content').removeClass('active');
        $(`.rba-tab-content[data-content="${tab}"]`).addClass('active');
    });
})(jQuery);
</script>
