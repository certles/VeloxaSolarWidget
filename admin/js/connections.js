(function () {
	'use strict';

	var chooser = document.querySelector('[data-source-chooser]');
	var section = document.querySelector('.veloxa-solar-add-source');
	var startButton = section ? section.querySelector('[data-source-start]') : null;
	var cancelLink = section ? section.querySelector('[data-source-cancel]') : null;
	if (!section) {
		return;
	}

	function showChooser() {
		var forms = section.querySelectorAll('.veloxa-solar-source-form');
		forms.forEach(function (form) {
			form.hidden = true;
		});
		if (chooser) {
			chooser.hidden = false;
		}
		if (startButton) {
			startButton.hidden = true;
		}
		var selectedSource = section.querySelector('.veloxa-solar-selected-source');
		if (selectedSource) {
			selectedSource.hidden = true;
		}
		var heading = section.querySelector('#veloxa-solar-add-source-title');
		if (heading) {
			heading.focus();
		}
		var url = new URL(window.location.href);
		url.searchParams.delete('veloxa_solar_add');
		url.searchParams.set('veloxa_solar_choose', '1');
		window.history.replaceState({}, '', url.toString());
		var firstCard = chooser ? chooser.querySelector('[data-source-select]') : null;
		if (firstCard) {
			firstCard.focus();
		} else if (cancelLink) {
			cancelLink.focus();
		}
	}

	function showForm(type) {
		var target = document.getElementById('veloxa-solar-form-' + type);
		if (!target) {
			return false;
		}
		section.querySelectorAll('.veloxa-solar-source-form').forEach(function (form) {
			form.hidden = form !== target;
		});
		if (chooser) {
			chooser.hidden = true;
		}
		if (startButton) {
			startButton.hidden = true;
		}
		var selectedSource = section.querySelector('.veloxa-solar-selected-source');
		if (selectedSource) {
			selectedSource.hidden = false;
		}
		var heading = section.querySelector('[data-source-heading]');
		if (heading) {
			heading.textContent = target.getAttribute('data-source-label') || heading.textContent;
			heading.focus();
		}
		var url = new URL(window.location.href);
		url.searchParams.set('veloxa_solar_add', type);
		url.searchParams.delete('veloxa_solar_choose');
		window.history.replaceState({}, '', url.toString());
		return true;
	}

	function cancelAdd() {
		section.querySelectorAll('.veloxa-solar-source-form').forEach(function (form) {
			form.hidden = true;
		});
		if (chooser) {
			chooser.hidden = true;
		}
		var selectedSource = section.querySelector('.veloxa-solar-selected-source');
		if (selectedSource) {
			selectedSource.hidden = true;
		}
		if (startButton) {
			startButton.hidden = false;
			startButton.focus();
		}
		var url = new URL(window.location.href);
		url.searchParams.delete('veloxa_solar_add');
		url.searchParams.delete('veloxa_solar_choose');
		window.history.replaceState({}, '', url.toString());
	}

	section.addEventListener('click', function (event) {
		var start = event.target.closest('[data-source-start]');
		if (start) {
			event.preventDefault();
			if (chooser) {
				chooser.hidden = false;
			}
			if (startButton) {
				startButton.hidden = true;
			}
			var heading = section.querySelector('#veloxa-solar-add-source-title');
			if (heading) {
				heading.focus();
			}
			var url = new URL(window.location.href);
			url.searchParams.set('veloxa_solar_choose', '1');
			window.history.replaceState({}, '', url.toString());
			var firstCard = chooser ? chooser.querySelector('[data-source-select]') : null;
			if (firstCard) {
				firstCard.focus();
			}
			return;
		}
		if (event.target.closest('[data-source-cancel]')) {
			event.preventDefault();
			cancelAdd();
			return;
		}
		var card = event.target.closest('[data-source-select]');
		if (card) {
			var type = card.getAttribute('data-source-select');
			if (showForm(type)) {
				event.preventDefault();
			}
			return;
		}
		if (event.target.closest('[data-source-back]')) {
			event.preventDefault();
			showChooser();
		}
	});

	var selected = new URL(window.location.href).searchParams.get('veloxa_solar_add');
	if (selected) {
		showForm(selected);
	}
}());
