/**
 * RBA Price Display — Calendar & Calculator
 * @since 1.4.5
 */
/* global rba_price_cfg, jQuery */
(function ($) {
    'use strict';

    var cfg = rba_price_cfg || {};
    var DOW = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];

    // State mỗi widget
    var state = {};

    function getState(roomId) {
        if (!state[roomId]) {
            var now = new Date();
            state[roomId] = {
                year: now.getFullYear(),
                month: now.getMonth() + 1,
                calData: null,
                selectStart: null,
                selectEnd: null,
            };
        }
        return state[roomId];
    }

    // ── Calendar ──────────────────────────────────────────────────────────────

    function loadCalendar(roomId, year, month) {
        var s   = getState(roomId);
        var $cal = $('.rba-pw-calendar[data-room="' + roomId + '"]');
        $cal.html('<div class="rba-cal-loading">Đang tải...</div>');
        $('.rba-cal-month-label').closest('.rba-price-widget[data-room="' + roomId + '"]')
            .find('.rba-cal-month-label').text('...');

        $.post(cfg.ajax_url, {
            action:   'rba_get_price_calendar',
            nonce:    cfg.nonce,
            room_id:  roomId,
            year:     year,
            month:    month,
        }, function (r) {
            if (!r.success) return;
            s.calData = r.data;
            renderCalendar(roomId, r.data);
        });
    }

    function renderCalendar(roomId, data) {
        var $widget = $('.rba-price-widget[data-room="' + roomId + '"]');
        $widget.find('.rba-cal-month-label').text(data.label);

        var today    = new Date();
        var todayStr = formatDate(today);
        var html     = '';

        // Day of week headers
        var dows = ['T2','T3','T4','T5','T6','T7','CN'];
        for (var i = 0; i < 7; i++) {
            html += '<div class="rba-cal-dow">' + dows[i] + '</div>';
        }

        // weekday: 1=Mon...7=Sun → offset empties
        // data.weekday theo ISO (1=Mon) → offset = weekday - 1
        var offset = (data.weekday - 1 + 7) % 7; // ISO Mon=1 → offset 0
        for (var e = 0; e < offset; e++) {
            html += '<div class="rba-cal-empty"></div>';
        }

        for (var idx = 0; idx < data.prices.length; idx++) {
            var p        = data.prices[idx];
            var d        = new Date(p.date + 'T00:00:00');
            var dateStr  = p.date;
            var dayNum   = d.getDate();
            var isPast   = dateStr < todayStr;
            var isToday  = dateStr === todayStr;

            var cls  = 'rba-cal-day rba-cal-day--' + (p.level || 'none');
            if (isPast) cls += ' rba-cal-day--past';
            if (isToday) cls += ' rba-cal-day--today';

            var priceStr = p.price > 0
                ? formatPrice(p.price, data.currency)
                : '';

            html += '<div class="' + cls + '" data-date="' + dateStr + '" data-price="' + p.price + '">' +
                '<span class="rba-day-num">' + dayNum + '</span>' +
                (priceStr ? '<span class="rba-day-price">' + priceStr + '</span>' : '') +
                '</div>';
        }

        $('.rba-pw-calendar[data-room="' + roomId + '"]').html(html);
    }

    function formatPrice(n, cur) {
        if (!n || n <= 0) return '';
        var s = Math.round(n).toString();
        // Rút gọn: 1.700.000 → 1.7tr
        if (n >= 1000000) {
            return (n / 1000000).toFixed(1).replace('.0','') + 'tr';
        }
        if (n >= 1000) {
            return Math.round(n / 1000) + 'k';
        }
        return s;
    }

    function formatDate(d) {
        var m = d.getMonth() + 1;
        var day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
    }

    // ── Price calculator ──────────────────────────────────────────────────────

    function calculatePrice(roomId) {
        var $widget  = $('.rba-price-widget[data-room="' + roomId + '"]');
        var checkIn  = $widget.find('.rba-checkin').val();
        var checkOut = $widget.find('.rba-checkout').val();
        var $result  = $('#rba-result-' + roomId);

        if (!checkIn || !checkOut || checkIn >= checkOut) {
            $result.find('.rba-pw-result-content').html(
                '<span style="color:#888;font-size:13px">Chọn ngày nhận và trả phòng hợp lệ</span>'
            );
            return;
        }

        $result.find('.rba-pw-result-loading').show();
        $result.find('.rba-pw-result-content').html('');

        $.post(cfg.ajax_url, {
            action:    'rba_calculate_price',
            nonce:     cfg.nonce,
            room_id:   roomId,
            check_in:  checkIn,
            check_out: checkOut,
        }, function (r) {
            $result.find('.rba-pw-result-loading').hide();
            if (!r.success) {
                $result.find('.rba-pw-result-content').html('<span style="color:#c62828">' + r.data + '</span>');
                return;
            }

            var d = r.data;
            var html = '<div class="rba-pw-total">' + d.formatted + '</div>';
            html += '<div style="font-size:12px;color:#888;margin-bottom:8px">' +
                d.nights + ' đêm &nbsp;·&nbsp; ' + d.per_night + '</div>';

            // Breakdown nếu giá thay đổi theo ngày
            if (d.has_vary && d.breakdown && d.breakdown.length <= 14) {
                html += '<div class="rba-pw-breakdown">';
                for (var i = 0; i < d.breakdown.length; i++) {
                    var b     = d.breakdown[i];
                    var dObj  = new Date(b.date + 'T00:00:00');
                    var label = dObj.toLocaleDateString('vi-VN', { weekday: 'short', day: 'numeric', month: 'numeric' });
                    html += '<div class="rba-pw-breakdown-row">' +
                        '<span>' + label + '</span>' +
                        '<span>' + Math.round(b.price).toLocaleString('vi-VN') + ' ' + d.currency + '</span>' +
                        '</div>';
                }
                html += '</div>';
            }

            $result.find('.rba-pw-result-content').html(html);

            // Highlight ngày được chọn trên calendar
            highlightRange(roomId, checkIn, checkOut);
        });
    }

    function highlightRange(roomId, checkIn, checkOut) {
        $('.rba-pw-calendar[data-room="' + roomId + '"] .rba-cal-day').each(function () {
            var d = $(this).data('date');
            $(this).removeClass('rba-cal-day--selected rba-cal-day--in-range');
            if (d === checkIn || d === checkOut) {
                $(this).addClass('rba-cal-day--selected');
            } else if (d > checkIn && d < checkOut) {
                $(this).addClass('rba-cal-day--in-range');
            }
        });
    }

    // ── Event bindings ────────────────────────────────────────────────────────

    $(document).ready(function () {

        // Init mỗi widget
        $('.rba-price-widget').each(function () {
            var roomId = $(this).data('room');
            if (!roomId) return;
            var s = getState(roomId);
            loadCalendar(roomId, s.year, s.month);
            // Tính giá mặc định (hôm nay → ngày mai)
            setTimeout(function () { calculatePrice(roomId); }, 300);
        });

        // Calendar nav
        $(document).on('click', '.rba-cal-prev', function () {
            var roomId = $(this).data('room');
            var s = getState(roomId);
            s.month--;
            if (s.month < 1) { s.month = 12; s.year--; }
            loadCalendar(roomId, s.year, s.month);
        });

        $(document).on('click', '.rba-cal-next', function () {
            var roomId = $(this).data('room');
            var s = getState(roomId);
            s.month++;
            if (s.month > 12) { s.month = 1; s.year++; }
            loadCalendar(roomId, s.year, s.month);
        });

        // Click ngày → điền vào date input
        $(document).on('click', '.rba-cal-day:not(.rba-cal-day--past)', function () {
            var $day   = $(this);
            var date   = $day.data('date');
            var roomId = $day.closest('.rba-pw-calendar').data('room');
            var $widget = $('.rba-price-widget[data-room="' + roomId + '"]');
            var $in    = $widget.find('.rba-checkin');
            var $out   = $widget.find('.rba-checkout');

            var curIn  = $in.val();
            var curOut = $out.val();

            // Click 1: set check-in; click 2: set check-out nếu sau check-in
            if (!curIn || (curIn && curOut) || date <= curIn) {
                $in.val(date);
                // Set check-out ngày hôm sau
                var next = new Date(date + 'T00:00:00');
                next.setDate(next.getDate() + 1);
                $out.val(formatDate(next));
            } else {
                $out.val(date);
            }

            calculatePrice(roomId);
        });

        // Date input change → recalculate
        $(document).on('change', '.rba-checkin, .rba-checkout', function () {
            var roomId = $(this).data('room');
            // Ensure check-out > check-in
            var $widget = $('.rba-price-widget[data-room="' + roomId + '"]');
            var inVal  = $widget.find('.rba-checkin').val();
            var outVal = $widget.find('.rba-checkout').val();
            if (inVal && outVal && outVal <= inVal) {
                var d = new Date(inVal + 'T00:00:00');
                d.setDate(d.getDate() + 1);
                $widget.find('.rba-checkout').val(formatDate(d));
            }
            calculatePrice(roomId);
        });

        // Toggle calendar collapse
        $(document).on('click', '.rba-pw-toggle', function () {
            var target = $(this).data('target');
            var $wrap  = $('#' + target);
            var isOpen = !$wrap.hasClass('collapsed');
            $wrap.toggleClass('collapsed', isOpen);
            $(this).toggleClass('open', !isOpen);
        });
    });

})(jQuery);
