"use strict";

const fs = require("node:fs");
const vm = require("node:vm");

const listeners = {};
global.document = {
	addEventListener(type, callback) {
		(listeners[type] ||= []).push(callback);
	},
};
global.window = global;

vm.runInThisContext(fs.readFileSync(require.resolve("../assets/petit-form.js"), "utf8"), {
	filename: "assets/petit-form.js",
});

let checks = 0;
function check(condition, message) {
	if (!condition) throw new Error(message);
	checks += 1;
	process.stdout.write(`PASS ${message}\n`);
}

function makeForm(ageSeconds) {
	const now = Math.floor(Date.now() / 1000);
	const inputs = {
		pf_form_id: { value: "contact" },
		pf_fields: { value: "name:required,email:required" },
		pf_definition_sig: { value: "a".repeat(64) },
		pf_nonce: { value: "old-nonce" },
		pf_ts: { value: String(now - ageSeconds) },
		pf_sig: { value: "old-signature" },
	};
	const error = { hidden: true, textContent: "" };
	const button = { disabled: false };
	return {
		action: "https://example.test/wp-admin/admin-post.php",
		dataset: { pfExpiredMessage: "The form has expired. Please reload the page and try again." },
		requestCount: 0,
		matches(selector) { return selector === "form.petit-form"; },
		querySelector(selector) {
			if (selector === ".pf-token-error") return error;
			const match = selector.match(/^\[name="([^"]+)"\]$/);
			return match ? inputs[match[1]] || null : null;
		},
		querySelectorAll(selector) { return selector === '[type="submit"]' ? [button] : []; },
		requestSubmit() { this.requestCount += 1; },
		inputs,
		error,
		button,
	};
}

function submitEvent(form) {
	return {
		target: form,
		submitter: form.button,
		prevented: false,
		preventDefault() { this.prevented = true; },
	};
}

async function waitFor(predicate) {
	for (let attempt = 0; attempt < 30; attempt += 1) {
		if (predicate()) return;
		await new Promise((resolve) => setImmediate(resolve));
	}
	throw new Error("Timed out waiting for the submit handler");
}

async function main() {
	const submit = listeners.submit[0];
	let fetchCalls = 0;
	window.fetch = async () => { fetchCalls += 1; throw new Error("unexpected fetch"); };
	const freshForm = makeForm(60);
	const freshEvent = submitEvent(freshForm);
	submit(freshEvent);
	check(!freshEvent.prevented && fetchCalls === 0, "A fresh form keeps the native submission path");

	window.fetch = async () => {
		fetchCalls += 1;
		return {
			ok: true,
			async json() {
				return { success: true, data: { nonce: "fresh-nonce", timestamp: String(Math.floor(Date.now() / 1000)), signature: "fresh-signature" } };
			},
		};
	};
	const expiredForm = makeForm(25 * 60 * 60);
	const expiredEvent = submitEvent(expiredForm);
	submit(expiredEvent);
	await waitFor(() => expiredForm.requestCount === 1);
	check(expiredEvent.prevented, "An old form pauses its native submission while tokens refresh");
	check(expiredForm.inputs.pf_nonce.value === "fresh-nonce" && expiredForm.inputs.pf_sig.value === "fresh-signature", "The three submission tokens update together");
	check(expiredForm.requestCount === 1 && !expiredForm.button.disabled, "A successful refresh resumes exactly one submission");

	let resolveFetch;
	let repeatedCalls = 0;
	window.fetch = () => {
		repeatedCalls += 1;
		return new Promise((resolve) => { resolveFetch = resolve; });
	};
	const repeatedForm = makeForm(25 * 60 * 60);
	const firstEvent = submitEvent(repeatedForm);
	const secondEvent = submitEvent(repeatedForm);
	submit(firstEvent);
	submit(secondEvent);
	check(repeatedCalls === 1 && firstEvent.prevented && secondEvent.prevented, "Repeated clicks share one in-flight refresh");
	resolveFetch({ ok: true, json: async () => ({ success: true, data: { nonce: "n", timestamp: String(Math.floor(Date.now() / 1000)), signature: "s" } }) });
	await waitFor(() => repeatedForm.requestCount === 1);
	check(repeatedForm.requestCount === 1, "Repeated clicks still resume one submission");

	window.fetch = async () => ({ ok: false, json: async () => ({}) });
	const failedForm = makeForm(25 * 60 * 60);
	const failedEvent = submitEvent(failedForm);
	submit(failedEvent);
	await waitFor(() => failedForm.dataset.pfRefreshing === undefined);
	check(failedEvent.prevented && failedForm.requestCount === 0, "A failed refresh never submits stale tokens automatically");
	check(!failedForm.error.hidden && failedForm.error.textContent.includes("reload"), "A failed refresh shows the actionable expiry message");
	check(!failedForm.button.disabled, "A failed refresh restores the submit control for retry");

	process.stdout.write(`${checks} browser checks passed.\n`);
}

main().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
