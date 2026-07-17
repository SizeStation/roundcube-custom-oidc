/**
 * ident_switch - Account switcher UI and new mail notifications.
 *
 * Places the hidden <select> from the footer into the appropriate
 * skin location (Larry, Classic, Elastic), shows it, and registers
 * notification listeners for background mail checking.
 *
 * Copyright (C) 2016-2018 Boris Gulay
 * Copyright (C) 2026      Gecka
 *
 * Original code licensed under GPL-3.0+.
 * New contributions licensed under AGPL-3.0+.
 *
 * @url https://github.com/Gecka-apps/ident_switch
 */

$(function() {
	var $wrapper = $('#ident-switch-wrapper');
	if (!$wrapper.length) {
		return;
	}

	var $sw = $wrapper.find('#plugin-ident_switch-account');
	var placed = false;
	var enhanced = false;

	// Keep the select as the source of truth and as a fallback for older skins.
	$sw.find('option').each(function() {
		$(this).data('orig-text', $(this).text());
	});

	if (rcmail.env.skin === 'elastic') {
		enhanced = ident_switch_buildMenu($wrapper, $sw);
	}

	switch (rcmail.env.skin) {
		case 'larry':
			placed = plugin_switchIdent_addCbLarry($wrapper, $sw);
			break;
		case 'classic':
			placed = plugin_switchIdent_addCbClassic($wrapper, $sw);
			break;
		case 'elastic':
			placed = plugin_switchIdent_addCbElastic($wrapper, $sw);
			break;
	}

	if (!placed) {
		return;
	}

	if (enhanced) {
		$sw.hide().attr({'aria-hidden': 'true', 'tabindex': '-1'});
	} else {
		$sw.show();
	}

	// Register server-side notification listeners
	rcmail.addEventListener('plugin.ident_switch.update_counts', ident_switch_updateCounts);
	rcmail.addEventListener('plugin.ident_switch.notify', ident_switch_onNotify);

	// Apply initial counts from page load
	if (rcmail.env.ident_switch_initial_counts) {
		ident_switch_updateCounts(rcmail.env.ident_switch_initial_counts);
	}
});

/**
 * Build the Elastic skin account menu around the existing select.
 *
 * The native select remains in the DOM so existing switching and unread-count
 * behaviour continue to work if the enhanced UI cannot be used.
 */
function ident_switch_buildMenu($wrapper, $sw) {
	var $selected = $sw.find('option:selected');
	if (!$selected.length) {
		return false;
	}

	var $button = $('<button>', {
		type: 'button',
		'class': 'ident-switch-button',
		'aria-haspopup': 'menu',
		'aria-expanded': 'false'
	});
	$button.append($('<span>', {'class': 'ident-switch-avatar', 'aria-hidden': 'true'}));
	$button.append($('<span>', {'class': 'ident-switch-current-label'}));
	$button.append($('<span>', {'class': 'ident-switch-chevron', 'aria-hidden': 'true'}));

	var $menu = $('<div>', {
		'class': 'ident-switch-menu',
		role: 'menu',
		'aria-label': rcmail.env.ident_switch_switch_label || 'Switch account'
	}).hide();

	$sw.find('option').each(function() {
		var value = String($(this).val());
		var label = $(this).data('orig-text') || $(this).text();
		var $item = $('<button>', {
			type: 'button',
			'class': 'ident-switch-option',
			role: 'menuitemradio',
			'aria-checked': 'false',
			'data-value': value
		});
		$item.append($('<span>', {
			'class': 'ident-switch-option-avatar',
			'aria-hidden': 'true',
			text: ident_switch_initial(label)
		}));
		$item.append($('<span>', {'class': 'ident-switch-option-label', text: label}));
		$item.append($('<span>', {'class': 'ident-switch-check', 'aria-hidden': 'true'}));
		$menu.append($item);
	});

	$wrapper.addClass('ident-switch-enhanced').append($button, $menu);
	ident_switch_syncMenu($sw, $wrapper);

	function closeMenu(focusButton) {
		$menu.hide();
		$button.attr('aria-expanded', 'false');
		$wrapper.removeClass('ident-switch-open');
		if (focusButton) {
			$button.trigger('focus');
		}
	}

	function openMenu(focusSelected) {
		$menu.show();
		$button.attr('aria-expanded', 'true');
		$wrapper.addClass('ident-switch-open');
		if (focusSelected) {
			var $active = $menu.find('.ident-switch-option.is-active');
			($active.length ? $active : $menu.find('.ident-switch-option').first()).trigger('focus');
		}
	}

	$button.on('click', function() {
		if ($button.attr('aria-expanded') === 'true') {
			closeMenu(false);
		} else {
			openMenu(false);
		}
	}).on('keydown', function(event) {
		if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			event.preventDefault();
			openMenu(true);
		} else if (event.key === 'Escape') {
			closeMenu(false);
		}
	});

	$menu.on('click', '.ident-switch-option', function() {
		var value = String($(this).data('value'));
		if (value === String($sw.val())) {
			closeMenu(true);
			return;
		}
		$button.prop('disabled', true).addClass('is-switching');
		$sw.val(value);
		ident_switch_syncMenu($sw, $wrapper);
		closeMenu(false);
		plugin_switchIdent_switch(value);
	}).on('keydown', '.ident-switch-option', function(event) {
		var $items = $menu.find('.ident-switch-option');
		var index = $items.index(this);
		if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			event.preventDefault();
			index += event.key === 'ArrowDown' ? 1 : -1;
			$items.eq((index + $items.length) % $items.length).trigger('focus');
		} else if (event.key === 'Home' || event.key === 'End') {
			event.preventDefault();
			$items.eq(event.key === 'Home' ? 0 : -1).trigger('focus');
		} else if (event.key === 'Escape') {
			event.preventDefault();
			closeMenu(true);
		} else if (event.key === 'Tab') {
			closeMenu(false);
		}
	});

	$(document).on('mousedown.identSwitch', function(event) {
		if (!$wrapper.is(event.target) && !$wrapper.has(event.target).length) {
			closeMenu(false);
		}
	});
	$(window).on('resize.identSwitch', function() {
		closeMenu(false);
	});

	return true;
}

function ident_switch_initial(label) {
	var normalized = $.trim(String(label || ''));
	return normalized ? normalized.charAt(0).toUpperCase() : '@';
}

/** Sync visible menu labels, selection state, and avatar from the native select. */
function ident_switch_syncMenu($sw, $wrapper) {
	var selectedValue = String($sw.val());
	var $selected = $sw.find('option:selected');
	var selectedLabel = $selected.data('orig-text') || $selected.text();

	$wrapper.find('.ident-switch-current-label').text(selectedLabel);
	$wrapper.find('.ident-switch-avatar').text(ident_switch_initial(selectedLabel));
	$wrapper.find('.ident-switch-button').attr(
		'aria-label',
		(rcmail.env.ident_switch_switch_label || 'Switch account') + ': ' + selectedLabel
	);
	$wrapper.find('.ident-switch-option').each(function() {
		var $item = $(this);
		var value = String($item.data('value'));
		var $option = $sw.find('option').filter(function() {
			return String($(this).val()) === value;
		});
		var active = value === selectedValue;
		$item.toggleClass('is-active', active).attr('aria-checked', active ? 'true' : 'false');
		$item.find('.ident-switch-option-label').text($option.text());
	});
}

/**
 * Place switcher in Larry skin: replace username in top-right corner of #topline.
 */
function plugin_switchIdent_addCbLarry($wrapper, $sw) {
	var $topRight = $('#topline .topright');
	if (!$topRight.length) {
		return false;
	}

	$topRight.find('.username').hide();
	$topRight.prepend($wrapper);
	return true;
}

/**
 * Place switcher in Classic skin: prepend to task bar.
 */
function plugin_switchIdent_addCbClassic($wrapper) {
	var $taskBar = $('#taskbar');
	if (!$taskBar.length) {
		return false;
	}

	$taskBar.prepend($wrapper);
	return true;
}

/**
 * Place switcher in Elastic skin: replace username in header.
 */
function plugin_switchIdent_addCbElastic($wrapper, $sw) {
	var $target = $('.header-title.username');
	if (!$target.length) {
		return false;
	}

	$sw.css({
		'background-color': 'transparent',
		'border': 'none',
		'font-weight': 'bold',
		'color': 'inherit',
		'box-shadow': 'none',
		'text-overflow': 'ellipsis',
		'padding': '0 1.2em 0 0.25em'
	});

	// Hide original username text and elements
	$target.contents().filter(function() {
		return this.nodeType === 3;
	}).remove();
	$target.children().not('#ident-switch-wrapper').hide();

	$target.prepend($wrapper);
	return true;
}

/**
 * Perform account switch via AJAX (called from <select> onchange).
 */
var ident_switch_switching = false;

function plugin_switchIdent_switch(val) {
	if (ident_switch_switching) return;
	ident_switch_switching = true;
	rcmail.env.unread_counts = {};
	rcmail.http_post('plugin.ident_switch.switch', {
		'_ident-id': val,
		'_mbox': rcmail.env.mailbox
	});
}

/**
 * Update per-account counts in select options and total badge.
 *
 * Each entry in data is {unseen: N, baseline: B}.
 * - Option text: "account (baseline+delta)" when delta > 0, else "account (unseen)"
 * - Badge: sum of deltas across all accounts (new messages not yet visited).
 *
 * @param {Object} data - Map of iid to {unseen, baseline}.
 */
function ident_switch_updateCounts(data) {
	var map = rcmail.env.ident_switch_iid_map || {};
	var $select = $('#plugin-ident_switch-account');
	var totalDelta = 0;

	// Reset all options to original text
	$select.find('option').each(function() {
		var orig = $(this).data('orig-text');
		if (orig) {
			$(this).text(orig);
		}
	});

	// On mail task, skip count for the active account (already shown in folder list)
	var selectedVal = rcmail.env.task === 'mail' ? $select.val() : null;

	// Update each option with its count
	for (var iid in data) {
		if (!data.hasOwnProperty(iid)) continue;
		var info = data[iid];
		var unseen = parseInt(info.unseen) || 0;
		var baseline = parseInt(info.baseline) || 0;
		var delta = Math.max(0, unseen - baseline);

		totalDelta += delta;

		if (map[iid] === undefined) continue;
		var selectVal = '' + map[iid];
		var $opt = $select.find('option').filter(function() { return $(this).val() === selectVal; });
		if (!$opt.length) continue;

		// Skip active account on mail task
		if (selectVal === selectedVal) continue;

		var suffix;
		if (delta > 0) {
			suffix = ' (' + baseline + '+' + delta + ')';
		} else if (unseen > 0) {
			suffix = ' (' + unseen + ')';
		} else {
			suffix = '';
		}

		if (suffix) {
			$opt.text($opt.data('orig-text') + suffix);
		}
	}

	// Update total badge (only new messages not yet visited)
	var $badge = $('#ident-switch-badge');
	if (totalDelta > 0) {
		$badge.text(totalDelta).show();
	} else {
		$badge.hide().text('');
	}

	var $wrapper = $('#ident-switch-wrapper');
	if ($wrapper.hasClass('ident-switch-enhanced')) {
		ident_switch_syncMenu($select, $wrapper);
	}
}

/**
 * Handle new mail notification from server.
 * @param {Object} data - {iid, label, count, basic, sound, desktop}
 */
function ident_switch_onNotify(data) {
	if (data.basic) {
		ident_switch_notifyBasic();
	}
	if (data.sound) {
		ident_switch_notifySound();
	}
	if (data.desktop) {
		ident_switch_notifyDesktop(data.label, data.count);
	}
}

/**
 * Basic notification: change page title.
 */
function ident_switch_notifyBasic() {
	var marker = '(*) ';
	if (document.title.indexOf(marker) !== 0) {
		document.title = marker + document.title;
	}
}

/**
 * Sound notification: play newmail_notifier sound.
 */
function ident_switch_notifySound() {
	var src = rcmail.assets_path('plugins/newmail_notifier/sound');
	try {
		new Audio(src + '.mp3').play().catch(function() {
			new Audio(src + '.wav').play().catch(function() {});
		});
	} catch(e) {}
}

/**
 * Desktop notification via Notification API.
 */
function ident_switch_notifyDesktop(label, count) {
	if (!('Notification' in window)) {
		return;
	}

	if (Notification.permission === 'default') {
		Notification.requestPermission();
		return;
	}

	if (Notification.permission !== 'granted') {
		return;
	}

	var body = count + ' unread message' + (count > 1 ? 's' : '');
	var popup = new Notification('New mail — ' + label, {
		body: body,
		tag: 'ident_switch_' + label,
		icon: rcmail.assets_path('plugins/ident_switch/mail.png')
	});

	var timeout = (rcmail.env.newmail_notifier_timeout || 10) * 1000;
	setTimeout(function() { popup.close(); }, timeout);
}

/**
 * Fix identity selection in compose view when impersonating.
 */
function plugin_switchIdent_fixIdent(iid) {
	if (parseInt(iid) > 0) {
		$('#_from').val(iid);
	}
}

/**
 * Filter compose From dropdown to only show identities for the active account.
 * Removes options whose identity_id is not in ident_switch_allowed_identities.
 */
function plugin_switchIdent_filterFrom() {
	var allowed = rcmail.env.ident_switch_allowed_identities;
	if (!allowed || !allowed.length) {
		return;
	}

	var $from = $('#_from');
	if (!$from.length) {
		return;
	}

	var currentVal = parseInt($from.val());

	$from.find('option').each(function() {
		if (allowed.indexOf(parseInt($(this).val())) === -1) {
			$(this).remove();
		}
	});

	// If selected option was removed, select the first remaining
	var $remaining = $from.find('option:first');
	if (allowed.indexOf(currentVal) === -1 && $remaining.length) {
		$from.val($remaining.val()).trigger('change');
	}
}

// Filter compose From dropdown on page load
$(function() {
	plugin_switchIdent_filterFrom();
});
