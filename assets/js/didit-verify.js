(function () {
  "use strict";

  var cfg = window.diditConfig;
  if (!cfg || !window.DiditSDK) return;

  var DiditSdk = window.DiditSDK.DiditSdk;
  var i18n = cfg.i18n || {};

  var activeButton = null;
  var pendingResult = null;

  function feedback(btn, message, isError) {
    var node = btn.parentNode.querySelector('.didit-feedback');
    if (!node) {
      node = document.createElement('p');
      node.className = 'didit-feedback';
      node.setAttribute('role', 'status');
      node.setAttribute('aria-live', 'polite');
      btn.insertAdjacentElement('afterend', node);
    }
    node.textContent = message;
    node.classList.toggle('didit-feedback-error', !!isError);
  }

  function setCheckoutSession(sessionId) {
    var hidden = document.getElementById('didit_session_id');
    if (hidden) hidden.value = sessionId;
    if (window.wp && window.wp.data && window.wp.data.dispatch) {
      try {
        wp.data.dispatch('wc/store/checkout').setExtensionData('didit-verify', { sessionId: sessionId });
      } catch (e) { /* Classic checkout has no Blocks data store. */ }
    }
  }

  function confirmResult(result, btn) {
    btn.textContent = i18n.confirming || 'Confirming verification…';
    btn.disabled = true;
    setCheckoutSession('');
    var body = { type: 'completed', sessionId: result.session ? result.session.sessionId : '' };
    if (btn.dataset.orderId) {
      body.order_id = btn.dataset.orderId;
      body.order_key = btn.dataset.orderKey || '';
    }
    return fetch(cfg.restUrl.replace('/session', '/verify'), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
      body: JSON.stringify(body)
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) {
          var error = new Error(data.message || i18n.confirmationError || 'Unable to confirm verification. Please try again.');
          error.code = data.code;
          throw error;
        }
        return data;
      });
    }).then(function (data) {
      btn.classList.remove('didit-verified', 'didit-in-review');
      if (data.status === 'Approved') {
        btn.textContent = btn.dataset.success || 'Verified';
        btn.classList.add('didit-verified');
        btn.disabled = true;
        pendingResult = null;
        setCheckoutSession(data.sessionId);
        feedback(btn, i18n.approved || 'Identity verified. You can continue.', false);
      } else if (data.status === 'Declined' || data.status === 'Expired' || data.status === 'KYC Expired') {
        pendingResult = null;
        resetBtn(btn);
        feedback(btn, data.status === 'Declined' ? (i18n.declined || 'Verification declined. Please try again.') : (i18n.expired || 'Verification expired. Please start again.'), true);
      } else {
        pendingResult = result;
        btn.textContent = i18n.checkStatus || 'Check status';
        btn.disabled = false;
        feedback(btn, i18n.inReview || 'Your verification is being reviewed. Check again shortly.', false);
      }
      document.dispatchEvent(new CustomEvent('didit:complete', { detail: { type: 'completed', session: { sessionId: data.sessionId, status: data.status } } }));
    }).catch(function (error) {
      pendingResult = result;
      btn.textContent = i18n.checkStatus || 'Check status';
      btn.disabled = false;
      if (error.code === 'didit_session_mismatch') {
        pendingResult = null;
        resetBtn(btn);
      }
      feedback(btn, error.message, true);
    });
  }

  DiditSdk.shared.onComplete = function (result) {
    var btn = activeButton;
    if (!btn) return;
    if (result.type !== 'completed') {
      pendingResult = null;
      setCheckoutSession('');
      resetBtn(btn);
      return;
    }
    if (cfg.mode === 'unilink' && !btn.dataset.wc) {
      resetBtn(btn);
      feedback(btn, i18n.submitted || 'Verification submitted. Your result is available from the service provider.', false);
      return;
    }
    confirmResult(result, btn);
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
    if (pendingResult && activeButton === btn) {
      confirmResult(pendingResult, btn);
      return;
    }
    activeButton = btn;
    pendingResult = null;
    btn.disabled = true;
    feedback(btn, '', false);

    if (cfg.mode === "unilink" && !btn.dataset.wc) {
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
          feedback(btn, err.message, true);
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
