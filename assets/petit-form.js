(function () {
	"use strict";

	function formFor(field) {
		return field && field.closest ? field.closest("form.petit-form") : null;
	}

	function messageFor(field, form) {
		if (field.validity.valueMissing) {
			return field.type === "checkbox"
				? form.dataset.pfCheckboxMessage
				: form.dataset.pfRequiredMessage;
		}
		if (field.validity.typeMismatch && field.type === "email") {
			return form.dataset.pfEmailMessage;
		}
		return "";
	}

	// Native browser validation messages follow the browser UI language, not
	// the WordPress locale. Supply the same small set of messages through the
	// translated form attributes while keeping native constraint validation.
	document.addEventListener("invalid", function (event) {
		var field = event.target;
		var form = formFor(field);
		if (!form || typeof field.setCustomValidity !== "function") return;
		// Read native validity before clearing a previous custom message. Some
		// browsers recompute an empty email field during setCustomValidity(),
		// which can otherwise lose the required-field message.
		var message = messageFor(field, form);
		field.setCustomValidity("");
		field.setCustomValidity(message);
	}, true);

	function clearMessage(event) {
		var field = event.target;
		if (!formFor(field) || typeof field.setCustomValidity !== "function") return;
		field.setCustomValidity("");
	}

	document.addEventListener("input", clearMessage);
	document.addEventListener("change", clearMessage);

	var refreshAfterSeconds = 10 * 60 * 60;

	function formInput(form, name) {
		return form.querySelector('[name="' + name + '"]');
	}

	function tokenNeedsRefresh(form) {
		var timestamp = parseInt((formInput(form, "pf_ts") || {}).value || "0", 10);
		return !timestamp || Math.floor(Date.now() / 1000) - timestamp >= refreshAfterSeconds;
	}

	function setRefreshError(form, visible) {
		var error = form.querySelector(".pf-token-error");
		if (!error) return;
		error.textContent = visible ? form.dataset.pfExpiredMessage : "";
		error.hidden = !visible;
	}

	function setSubmitDisabled(form, disabled) {
		Array.prototype.forEach.call(form.querySelectorAll('[type="submit"]'), function (button) {
			button.disabled = disabled;
		});
	}

	function refreshTokens(form) {
		var body = new URLSearchParams();
		var controller = typeof window.AbortController === "function" ? new window.AbortController() : null;
		var timeout = window.setTimeout(function () {
			if (controller) controller.abort();
		}, 8000);
		body.set("action", "petit_form_refresh_tokens");
		["pf_form_id", "pf_fields", "pf_definition_sig"].forEach(function (name) {
			var input = formInput(form, name);
			body.set(name, input ? input.value : "");
		});
		return window.fetch(form.action, {
			method: "POST",
			credentials: "same-origin",
			cache: "no-store",
			headers: { "Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
			body: body.toString(),
			signal: controller ? controller.signal : undefined
		}).then(function (response) {
			if (!response.ok) throw new Error("token-refresh-http");
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.data) throw new Error("token-refresh-payload");
			var mapping = { pf_nonce: "nonce", pf_ts: "timestamp", pf_sig: "signature" };
			Object.keys(mapping).forEach(function (name) {
				var input = formInput(form, name);
				var value = payload.data[mapping[name]];
				if (!input || typeof value !== "string" || !value) throw new Error("token-refresh-fields");
				input.value = value;
			});
		}).then(function (result) {
			window.clearTimeout(timeout);
			return result;
		}, function (error) {
			window.clearTimeout(timeout);
			throw error;
		});
	}

	document.addEventListener("submit", function (event) {
		var form = event.target;
		if (!form || !form.matches || !form.matches("form.petit-form")) return;
		if (form.dataset.pfFreshSubmit === "true") {
			delete form.dataset.pfFreshSubmit;
			return;
		}
		if (!tokenNeedsRefresh(form)) return;
		event.preventDefault();
		if (form.dataset.pfRefreshing === "true") return;

		form.dataset.pfRefreshing = "true";
		setRefreshError(form, false);
		setSubmitDisabled(form, true);
		var submitter = event.submitter || null;
		refreshTokens(form).then(function () {
			setSubmitDisabled(form, false);
			delete form.dataset.pfRefreshing;
			form.dataset.pfFreshSubmit = "true";
			if (typeof form.requestSubmit === "function") {
				form.requestSubmit(submitter);
			} else {
				form.submit();
			}
		}).catch(function () {
			setSubmitDisabled(form, false);
			delete form.dataset.pfRefreshing;
			setRefreshError(form, true);
		});
	}, true);
})();
