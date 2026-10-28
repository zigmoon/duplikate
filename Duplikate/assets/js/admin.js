/**
 * Duplikate — settings screen.
 *
 * Tabs only. Every control of this screen is a native form element, so with
 * JavaScript off the panels stay open and the form still saves.
 */
(function () {
	'use strict';

	var root = document.querySelector('[data-dup-admin]');

	if (!root) {
		return;
	}

	root.classList.add('dup-admin--js');

	var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-dup-tab]'));
	var panels = Array.prototype.slice.call(root.querySelectorAll('[data-dup-panel]'));

	if (!tabs.length || !panels.length) {
		return;
	}

	var activate = function (id, focus) {
		var found = false;

		tabs.forEach(function (tab) {
			var on = tab.getAttribute('data-dup-tab') === id;

			tab.setAttribute('aria-selected', on ? 'true' : 'false');
			tab.tabIndex = on ? 0 : -1;

			if (on && focus) {
				tab.focus();
			}
		});

		panels.forEach(function (panel) {
			var on = panel.getAttribute('data-dup-panel') === id;

			panel.hidden = !on;

			if (on) {
				found = true;
			}
		});

		if (!found) {
			activate(tabs[0].getAttribute('data-dup-tab'), focus);
			return;
		}

		if (window.history && window.history.replaceState) {
			try {
				window.history.replaceState(null, '', '#dup-panel-' + id);
			} catch (error) {
				// Ignore URL restrictions in sandboxed previews.
			}
		}
	};

	tabs.forEach(function (tab, index) {
		tab.addEventListener('click', function () {
			activate(tab.getAttribute('data-dup-tab'), false);
		});

		tab.addEventListener('keydown', function (event) {
			var next = null;

			if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
				next = tabs[(index + 1) % tabs.length];
			} else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
				next = tabs[(index - 1 + tabs.length) % tabs.length];
			} else if (event.key === 'Home') {
				next = tabs[0];
			} else if (event.key === 'End') {
				next = tabs[tabs.length - 1];
			}

			if (next) {
				event.preventDefault();
				activate(next.getAttribute('data-dup-tab'), true);
			}
		});
	});

	var initial = (window.location.hash || '').replace('#dup-panel-', '');

	activate(initial || tabs[0].getAttribute('data-dup-tab'), false);
}());
