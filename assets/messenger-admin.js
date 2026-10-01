(function () {
	'use strict';
	function init() {
	document.querySelectorAll('[data-broadcast-form]').forEach(function (form) {
		var audience = form.querySelector('[data-broadcast-audience]');
		var roleField = form.querySelector('[data-broadcast-role]');
		var role = roleField ? roleField.querySelector('select') : null;
		var body = form.querySelector('[data-broadcast-body]');
		var count = form.querySelector('[data-broadcast-count]');
		var preview = form.querySelector('[data-broadcast-preview]');
		var stale = form.querySelector('[data-broadcast-stale]');
		var send = form.querySelector('[data-broadcast-send]');
		var confirmation = form.querySelector('[name="confirm_broadcast"]');

		function syncAudience() {
			if (!audience || !roleField || !role) return;
			var usesRole = audience.value === 'role';
			roleField.hidden = !usesRole;
			role.disabled = !usesRole;
			role.required = usesRole;
		}

		function syncCount() {
			if (!body || !count) return;
			count.textContent = body.value.length + ' of ' + body.maxLength + ' characters';
		}

		function invalidatePreview() {
			if (!preview) return;
			preview.dataset.stale = 'true';
			if (stale) stale.hidden = false;
			if (send) send.disabled = true;
			if (confirmation) {
				confirmation.checked = false;
				confirmation.disabled = true;
			}
		}

		syncAudience();
		syncCount();
		if (audience) audience.addEventListener('change', function () { syncAudience(); invalidatePreview(); });
		if (role) role.addEventListener('change', invalidatePreview);
		if (body) body.addEventListener('input', function () { syncCount(); invalidatePreview(); });
	});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
}());
