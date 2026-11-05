/*
 * 2026-10-24 — Receipt reprint with supervisor approval.
 * Buttons: <button data-reprint data-invoice="123" data-doc="RECEIPT" data-url="receipt_print.php?invoice_number=123">.
 * When the page sets data-needs-approval="1" on <body>, a dialog asks for a supervisor's username + password;
 * the server (receipt_reprint_authorise.php) verifies and returns a single-use grant for the print page.
 */
(function () {
  "use strict";
  var dlg = document.getElementById("reprintDialog");
  if (!dlg) return;
  var form = dlg.querySelector("form");
  var err = dlg.querySelector("[data-reprint-error]");
  var label = dlg.querySelector("[data-reprint-label]");
  var submit = dlg.querySelector("[data-reprint-submit]");
  var pending = null;
  var needsApproval = document.body.getAttribute("data-needs-approval") === "1";

  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute("content") : "";
  }
  function openUrl(url, grant) {
    var u = url + (grant ? (url.indexOf("?") >= 0 ? "&" : "?") + "grant=" + encodeURIComponent(grant) : "");
    // "noopener" would make window.open return null, so detach the opener by hand.
    var w = window.open(u, "_blank");
    if (w) { try { w.opener = null; } catch (ignore) {} } else { window.location.href = u; }
  }
  function showError(msg) {
    err.textContent = msg;
    err.hidden = !msg;
  }

  document.addEventListener("click", function (e) {
    var btn = e.target.closest ? e.target.closest("[data-reprint]") : null;
    if (!btn) return;
    e.preventDefault();
    pending = { invoice: btn.getAttribute("data-invoice"), doc: btn.getAttribute("data-doc") || "RECEIPT", url: btn.getAttribute("data-url") };
    if (!needsApproval) {
      openUrl(pending.url, "");
      return;
    }
    form.reset();
    showError("");
    label.textContent = (btn.getAttribute("data-label") || "Receipt") + " " + pending.invoice;
    submit.disabled = false;
    dlg.showModal();
    var first = form.querySelector("input[name=approver_login]");
    if (first) first.focus();
  });

  dlg.querySelectorAll("[data-dialog-close]").forEach(function (b) {
    b.addEventListener("click", function () { dlg.close(); });
  });

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    if (!pending || submit.disabled) return;
    submit.disabled = true;
    showError("");
    var body = new URLSearchParams();
    body.set("invoice_number", pending.invoice);
    body.set("document", pending.doc);
    body.set("approver_login", form.approver_login.value);
    body.set("approver_password", form.approver_password.value);
    body.set("csrf_token", csrf());
    fetch("receipt_reprint_authorise.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-Token": csrf(), "Accept": "application/json" },
      body: body
    }).then(function (r) { return r.json(); }).then(function (res) {
      form.approver_password.value = "";
      if (!res || !res.success) {
        showError((res && res.error) || "The reprint was not approved.");
        submit.disabled = false;
        return;
      }
      dlg.close();
      openUrl(pending.url, res.grant);
    }).catch(function () {
      showError("The reprint could not be approved. Check the connection and try again.");
      submit.disabled = false;
    });
  });
})();
