(function ($) {
	'use strict';

	var busy = false;
	var $box = $('#ssa-progress');
	var $fill = $box.find('.ssa-fill');
	var $text = $box.find('.ssa-text');

	function post(action, data) {
		return $.post(SSA.ajax, $.extend({ action: action, nonce: SSA.nonce }, data || {}));
	}

	function fail(msg) {
		busy = false;
		$('#ssa-all, .ssa-one').prop('disabled', false);
		$text.text('Error: ' + msg);
	}

	function step() {
		post('ssa_step')
			.done(function (r) {
				if (!r || !r.success) {
					return fail(r && r.data ? r.data : 'Unknown');
				}
				var d = r.data;
				var pct = d.total ? Math.round((d.done / d.total) * 100) : 100;
				$fill.css('width', pct + '%');
				$text.text(d.done + ' / ' + d.total + (d.url ? ' \u2013 ' + d.url : '') + (d.ok ? '' : ' \u2013 FAILED'));
				if (d.finished) {
					setTimeout(function () { location.reload(); }, 600);
				} else {
					step();
				}
			})
			.fail(function (xhr) {
				fail('Request failed (' + xhr.status + '). Check timeout setting.');
			});
	}

	function start(url) {
		if (busy) { return; }
		busy = true;
		$('#ssa-all, .ssa-one').prop('disabled', true);
		$box.show();
		$fill.css('width', '0%');
		$text.text('Starting...');
		post('ssa_start', url ? { url: url } : {})
			.done(function (r) {
				if (!r || !r.success) {
					return fail(r && r.data ? r.data : 'Could not start');
				}
				step();
			})
			.fail(function (xhr) {
				fail('Request failed (' + xhr.status + ').');
			});
	}

	$('#ssa-gh-run').on('click', function () {
		var $b = $(this).prop('disabled', true);
		$box.show();
		$fill.css('width', '0%');
		$text.text('Starting workflow...');
		post('ssa_gh_run')
			.done(function (r) { $text.text(r && r.data ? r.data : 'Done'); })
			.fail(function (xhr) { $text.text('Request failed (' + xhr.status + ').'); })
			.always(function () { $b.prop('disabled', false); });
	});
	$('#ssa-all').on('click', function () { start(null); });
	$('.ssa-one').on('click', function () { start($(this).data('url')); });
})(jQuery);
