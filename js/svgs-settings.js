/**
 * SVG Support — settings screen behavior
 * Live Advanced Mode reveal + debounced auto-save (vanilla JS, no dependencies).
 * Without JS the screen falls back to the classic form submit + simple-mode CSS.
 */
(function () {
	'use strict';

	var form = document.getElementById('svgs-settings-form');
	if (!form || typeof window.SvgsSettings === 'undefined') {
		return;
	}

	document.body.classList.add('svgs-js');

	/* ----- Live Advanced Mode reveal ----- */

	var advancedToggle = form.querySelector('input[name="bodhi_svgs_settings[advanced_mode]"]');
	var advancedSections = document.querySelectorAll('.svgs-advanced');
	var lockedNotices = document.querySelectorAll('.svgs-locked');

	function syncAdvanced() {
		var on = advancedToggle && advancedToggle.checked;
		advancedSections.forEach(function (el) {
			// Explicit value: an empty string would let the simple-mode
			// stylesheet (loaded while Advanced Mode is saved off) re-hide it.
			el.style.display = on ? 'block' : 'none';
		});
		lockedNotices.forEach(function (el) {
			el.style.display = on ? 'none' : 'flex';
		});
	}

	if (advancedToggle) {
		advancedToggle.addEventListener('change', syncAdvanced);
		syncAdvanced();
	}

	/* ----- Save-state chip ----- */

	var chip = document.getElementById('svgs-savestate');
	var chipIcon = chip ? chip.querySelector('use') : null;
	var chipText = chip ? chip.querySelector('span') : null;

	function setState(state) {
		if (!chip) {
			return;
		}
		chip.setAttribute('data-state', state);
		var icons = { idle: 'cloud', saving: 'cloud', saved: 'circle-check', error: 'triangle-alert' };
		if (chipIcon) {
			chipIcon.setAttribute('href', window.SvgsSettings.iconsUrl + '#' + icons[state]);
		}
		if (chipText) {
			chipText.textContent = window.SvgsSettings.i18n[state];
		}
	}

	/* ----- Debounced auto-save ----- */

	var timer = null;
	var inFlight = null;

	function save() {
		setState('saving');

		var data = new FormData(form);
		data.append('action', 'bodhi_svgs_autosave');
		data.append('nonce', window.SvgsSettings.nonce);

		var thisRequest = fetch(window.SvgsSettings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (inFlight !== thisRequest) {
					return; // a newer save superseded this one
				}
				setState(json && json.success ? 'saved' : 'error');
			})
			.catch(function () {
				if (inFlight === thisRequest) {
					setState('error');
				}
			});

		inFlight = thisRequest;
	}

	function queueSave(delay) {
		clearTimeout(timer);
		timer = setTimeout(save, delay);
	}

	form.addEventListener('change', function () {
		queueSave(400);
	});

	// Text input: save while typing, slightly longer debounce
	var cssTarget = form.querySelector('input[name="bodhi_svgs_settings[css_target]"]');
	if (cssTarget) {
		cssTarget.addEventListener('input', function () {
			queueSave(800);
		});
	}
})();
