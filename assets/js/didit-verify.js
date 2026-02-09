(function () {
  "use strict";

  var cfg = window.diditConfig;
  if (!cfg || !window.DiditSDK) return;

  var DiditSdk = window.DiditSDK.DiditSdk;

  DiditSdk.shared.onComplete = function (result) {
    document.querySelectorAll(".didit-verify-btn").forEach(function (btn) {
      if (result.type === "completed") {
        btn.textContent = btn.dataset.success || "Verified";
        btn.classList.add("didit-verified");
        btn.disabled = true;
      } else {
        btn.textContent = btn.dataset.text || "Verify Identity";
        btn.disabled = false;
      }
    });

    var hidden = document.getElementById("didit_session_id");
    if (hidden && result.session && result.session.sessionId) {
      hidden.value = result.type === "completed" ? result.session.sessionId : "";
    }

    if (cfg.restUrl && cfg.nonce) {
      fetch(cfg.restUrl.replace("/session", "/verify"), {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": cfg.nonce
        },
        body: JSON.stringify({
          type: result.type,
          sessionId: result.session ? result.session.sessionId : "",
          status: result.session ? result.session.status : ""
        })
      }).catch(function () {});
    }

    document.dispatchEvent(new CustomEvent("didit:complete", { detail: result }));
  };

  function getWcBillingData() {
    var val = function (id) {
      var el = document.getElementById(id);
      return el ? el.value.trim() : "";
    };

    var contact_details = {};
    var expected_details = {};

    var email = val("billing_email");
    var phone = val("billing_phone");
    if (email) contact_details.email = email;
    if (phone) contact_details.phone = phone;

    var firstName = val("billing_first_name");
    var lastName = val("billing_last_name");
    var address1 = val("billing_address_1");
    var address2 = val("billing_address_2");
    var city = val("billing_city");
    var state = val("billing_state");
    var postcode = val("billing_postcode");
    var country = val("billing_country");

    if (firstName) expected_details.first_name = firstName;
    if (lastName) expected_details.last_name = lastName;
    if (country) expected_details.country = country;

    var parts = [address1, address2, city, state, postcode].filter(Boolean);
    if (parts.length > 0) expected_details.address = parts.join(", ");

    var data = {};
    if (Object.keys(contact_details).length) data.contact_details = contact_details;
    if (Object.keys(expected_details).length) data.expected_details = expected_details;

    return data;
  }

  document.addEventListener("click", function (e) {
    var btn = e.target.closest(".didit-verify-btn");
    if (!btn || btn.disabled || btn.classList.contains("didit-verified")) return;

    e.preventDefault();
    btn.disabled = true;

    if (cfg.mode === "unilink") {
      startSdk(cfg.unilinkUrl, btn);
    } else {
      btn.textContent = "Creating session\u2026";

      var requestBody = {};
      if (btn.dataset.wc && cfg.sendBilling) {
        requestBody = getWcBillingData();
      }

      fetch(cfg.restUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": cfg.nonce
        },
        body: JSON.stringify(requestBody)
      })
        .then(function (r) {
          if (!r.ok)
            return r.json().then(function (d) {
              throw new Error(d.message || "Error " + r.status);
            });
          return r.json();
        })
        .then(function (data) {
          if (!data.url) throw new Error("No verification URL returned");
          startSdk(data.url, btn);
        })
        .catch(function (err) {
          alert("Verification error: " + err.message);
          resetBtn(btn);
        });
    }
  });

  function resetBtn(btn) {
    btn.textContent = btn.dataset.text || "Verify Identity";
    btn.disabled = false;
  }

  function startSdk(url, btn) {
    var config = {
      showCloseButton: cfg.showCloseButton,
      showExitConfirmation: cfg.showExitConfirmation,
      closeModalOnComplete: cfg.closeModalOnComplete,
      loggingEnabled: cfg.loggingEnabled
    };

    var containerId = btn.dataset.container;
    if (containerId) {
      var container = document.getElementById(containerId);
      if (container) container.innerHTML = "";
      config.embedded = true;
      config.embeddedContainerId = containerId;
      config.showExitConfirmation = false;
    }

    DiditSdk.shared.startVerification({ url: url, configuration: config });
    btn.textContent = btn.dataset.text || "Verify Identity";
  }
})();
