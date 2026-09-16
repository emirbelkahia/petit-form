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
})();
