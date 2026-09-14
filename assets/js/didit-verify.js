(function () {
  "use strict";

  var cfg = window.diditConfig;
  if (!cfg || !window.DiditSDK) return;

  var DiditSdk = window.DiditSDK.DiditSdk;
  var i18n = cfg.i18n || {};

  function setButtonState(type, status) {
    document.querySelectorAll(".didit-verify-btn").forEach(function (btn) {
      btn.classList.remove("didit-verified", "didit-declined", "didit-in-review");

      if (type === "completed" && status === "Approved") {
        btn.textContent = btn.dataset.success || "Verified";
        btn.classList.add("didit-verified");
        btn.disabled = true;
      } else if (type === "completed" && status === "Declined") {
        btn.textContent = btn.dataset.text || "Verify Identity";
        btn.disabled = false;
      } else if (type === "completed") {
        btn.textContent = i18n.inReview || "Verification In Review";
        btn.classList.add("didit-in-review");
        btn.disabled = true;
      } else {
        btn.textContent = btn.dataset.text || "Verify Identity";
        btn.disabled = false;
      }
    });
  }

  DiditSdk.shared.onComplete = function (result) {
    var status = result.session ? result.session.status : "";

    var hidden = document.getElementById("didit_session_id");
    if (hidden && result.session && result.session.sessionId) {
      hidden.value = result.type === "completed" ? result.session.sessionId : "";
    }

    if (window.wp && window.wp.data && window.wp.data.dispatch) {
      try {
        wp.data.dispatch("wc/store/checkout").setExtensionData("didit-verify", {
          sessionId: result.type === "completed" && result.session ? result.session.sessionId : ""
        });
      } catch (e) {}
    }

    // In API mode the site confirms a completion with Didit before saving it, so
    // the button shows the status the site stored rather than the one the SDK
    // reported. UniLink results cannot be saved, so they are not sent.
    var confirmWithSite = cfg.mode === "api" && cfg.restUrl && cfg.nonce;

    if (!confirmWithSite || result.type !== "completed") {
      setButtonState(result.type, status);
    }

    if (confirmWithSite) {
      var verifyBody = {
        type: result.type,
        sessionId: result.session ? result.session.sessionId : ""
      };
      var orderBtn = document.querySelector(".didit-verify-btn[data-order-id]");
      if (orderBtn) {
        verifyBody.order_id = orderBtn.dataset.orderId;
        verifyBody.order_key = orderBtn.dataset.orderKey || "";
      }
      fetch(cfg.restUrl.replace("/session", "/verify"), {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": cfg.nonce
        },
        body: JSON.stringify(verifyBody)
      })
        .then(function (r) {
          return r.json().then(function (d) {
            return { response: r, data: d };
          });
        })
        .then(function (res) {
          if (result.type !== "completed") return;
          if (res.response.ok) {
            setButtonState("completed", res.data.status);
          } else if (res.response.status === 401) {
            // Anonymous visitor outside an order: nothing is stored on the site.
            setButtonState(result.type, status);
          } else {
            setButtonState("error", "");
            alert((i18n.verificationError || "Verification error:") + " " + (res.data.message || "Error " + res.response.status));
          }
        })
        .catch(function () {
          if (result.type === "completed") setButtonState("error", "");
        });
    }

    document.dispatchEvent(new CustomEvent("didit:complete", { detail: result }));
  };

  function getWcBillingData() {
    if (window.wp && window.wp.data && window.wp.data.select) {
      try {
        var storeCart = wp.data.select("wc/store/cart");
        if (storeCart && storeCart.getCustomerData) {
          var billing = (storeCart.getCustomerData().billingAddress) || {};
          var cd = {};
          var ed = {};
          if (billing.email) cd.email = billing.email;
          if (billing.phone) cd.phone = billing.phone;
          if (billing.first_name) ed.first_name = billing.first_name;
          if (billing.last_name) ed.last_name = billing.last_name;
          if (billing.country) ed.country = billing.country;
          var addrParts = [billing.address_1, billing.address_2, billing.city, billing.state, billing.postcode].filter(Boolean);
          if (addrParts.length) ed.address = addrParts.join(", ");
          var storeData = {};
          if (Object.keys(cd).length) storeData.contact_details = cd;
          if (Object.keys(ed).length) storeData.expected_details = ed;
          if (Object.keys(storeData).length) return storeData;
        }
      } catch (e) {}
    }

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
      btn.textContent = i18n.creatingSession || "Creating session\u2026";

      var requestBody = {};
      if (btn.dataset.wc && cfg.sendBilling) {
        requestBody = getWcBillingData();
      }
      if (btn.dataset.orderId) {
        requestBody.order_id = btn.dataset.orderId;
        requestBody.order_key = btn.dataset.orderKey || "";
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
          if (!data.url) throw new Error(i18n.noUrl || "No verification URL returned");
          startSdk(data.url, btn);
        })
        .catch(function (err) {
          alert((i18n.verificationError || "Verification error:") + " " + err.message);
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
