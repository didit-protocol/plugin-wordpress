(function () {
  "use strict";

  var cfg = window.diditConfig;
  if (!cfg || !window.DiditSDK) return;

  var DiditSdk = window.DiditSDK.DiditSdk;
  var i18n = cfg.i18n || {};

  var activeButton = null;
  var RESUME_KEY = "diditPendingSessionId";
  var UUID = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;

  // Each button keeps its own unfinished result, so checking one placement's status
  // never replays or drops another placement's session.
  function pendingOf(btn) { return btn ? btn.diditPending || null : null; }
  function setPending(btn, result) { if (btn) btn.diditPending = result || null; }

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

  // Every placement has its own proof: registration writes its own field and checkout
  // the order session, so finishing or cancelling one never clears the other.
  function setPlacementSession(btn, sessionId) {
    if (btn.dataset.placement === 'registration') {
      var wrap = btn.closest('.didit-registration');
      var field = wrap && wrap.querySelector('input[name="didit_registration_session_id"]');
      if (field) field.value = sessionId;
      return;
    }
    // Gated pages and account tabs keep their proof on the server only.
    if (["product_gate", "my_account", "business"].indexOf(btn.dataset.placement || "") === -1) {
      setCheckoutSession(sessionId);
    }
  }

  // The redirect return remembers which placement started it, not just the session.
  function rememberSession(sessionId, btn) {
    var record = JSON.stringify({ id: sessionId, placement: (btn && btn.dataset.placement) || '' });
    try { window.sessionStorage.setItem(RESUME_KEY, record); } catch (e) { /* storage may be blocked */ }
  }

  function storedSession() {
    var raw = '';
    try { raw = window.sessionStorage.getItem(RESUME_KEY) || ''; } catch (e) { raw = ''; }
    if (!raw) return { id: '', placement: '' };
    try {
      var parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') {
        return { id: String(parsed.id || ''), placement: String(parsed.placement || '') };
      }
    } catch (e) { /* value written by an earlier version: a bare session ID */ }
    return { id: raw, placement: '' };
  }

  function forgetSession() {
    try { window.sessionStorage.removeItem(RESUME_KEY); } catch (e) { /* storage may be blocked */ }
    if (window.history && window.history.replaceState) {
      try {
        var current = new URL(window.location.href);
        if (current.searchParams.has("verificationSessionId")) {
          window.history.replaceState({}, "", pageUrl());
        }
      } catch (e) { /* older browser, the parameter is harmless */ }
    }
  }

  // This page without the parameters Didit appends on the way back.
  function pageUrl() {
    try {
      var current = new URL(window.location.href);
      if (current.searchParams.has("verificationSessionId")) {
        current.searchParams.delete("verificationSessionId");
        current.searchParams.delete("status");
      }
      return current.toString();
    } catch (e) {
      return window.location.href;
    }
  }

  function returnedSession() {
    var id = "";
    try {
      id = new URL(window.location.href).searchParams.get("verificationSessionId") || "";
    } catch (e) { id = ""; }
    var stored = storedSession();
    if (!id) id = stored.id;
    // The stored placement belongs to this return only when it names the same session.
    var placement = stored.id && stored.id.toLowerCase() === id.toLowerCase() ? stored.placement : "";
    return UUID.test(id) ? { id: id, placement: placement } : { id: "", placement: "" };
  }

  function buttonFor(placement) {
    var buttons = document.querySelectorAll(".didit-verify-btn");
    for (var i = 0; i < buttons.length; i++) {
      if ((buttons[i].dataset.placement || "") === placement) return buttons[i];
    }
    // A page with a single button (or a return stored by an earlier version).
    return buttons.length === 1 || !placement ? buttons[0] || null : null;
  }

  // The shopper comes back from the hosted page: confirm what Didit decided on the
  // button that started it, so each placement keeps its own result.
  function resumeReturnedSession() {
    var returned = returnedSession();
    var sessionId = returned.id;
    if (!sessionId) return;
    var btn = buttonFor(returned.placement);
    if (!btn || btn.classList.contains("didit-verified")) {
      forgetSession();
      return;
    }
    activeButton = btn;
    confirmResult({ type: "completed", session: { sessionId: sessionId } }, btn).then(forgetSession, forgetSession);
  }

  function confirmResult(result, btn) {
    btn.textContent = i18n.confirming || 'Confirming verification…';
    btn.disabled = true;
    setPlacementSession(btn, '');
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
        setPending(btn, null);
        setPlacementSession(btn, data.sessionId);
        feedback(btn, i18n.approved || 'Identity verified. You can continue.', false);
        // Gated pages show what the approval unlocked.
        if (["product_gate", "my_account", "business"].indexOf(btn.dataset.placement || "") !== -1) {
          window.setTimeout(function () { window.location.reload(); }, 1200);
        }
      } else if (data.status === 'Declined' || data.status === 'Abandoned' || data.status === 'Expired' || data.status === 'KYC Expired') {
        setPending(btn, null);
        resetBtn(btn);
        feedback(btn, data.status === 'Declined' ? (i18n.declined || 'Verification declined. Please try again.') : (i18n.expired || 'Verification expired. Please start again.'), true);
      } else {
        setPending(btn, result);
        btn.textContent = i18n.checkStatus || 'Check status';
        btn.disabled = false;
        feedback(btn, i18n.inReview || 'Your verification is being reviewed. Check again shortly.', false);
      }
      document.dispatchEvent(new CustomEvent('didit:complete', { detail: { type: 'completed', session: { sessionId: data.sessionId, status: data.status } } }));
    }).catch(function (error) {
      setPending(btn, result);
      btn.textContent = i18n.checkStatus || 'Check status';
      btn.disabled = false;
      if (error.code === 'didit_session_mismatch') {
        setPending(btn, null);
        resetBtn(btn);
      }
      feedback(btn, error.message, true);
    });
  }

  DiditSdk.shared.onComplete = function (result) {
    var btn = activeButton;
    if (!btn) return;
    if (result.type !== 'completed') {
      // Cancelling clears only the placement that was being verified.
      setPending(btn, null);
      setPlacementSession(btn, '');
      btn.classList.remove('didit-verified', 'didit-in-review');
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
    if (pendingOf(btn)) {
      activeButton = btn;
      confirmResult(pendingOf(btn), btn);
      return;
    }
    activeButton = btn;
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
      if (btn.dataset.display === "redirect") {
        requestBody.return_url = pageUrl();
      }
      // The server maps the placement to the workflow the admin chose for it.
      if (btn.dataset.placement) {
        requestBody.placement = btn.dataset.placement;
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
          startSdk(data.url, btn, data.sessionId);
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

  function startSdk(url, btn, sessionId) {
    // The server marks every redirect button, so a shortcode's own mode always wins.
    if (btn.dataset.display === "redirect") {
      // The hosted page is the only place a blocked camera can recover the flow.
      if (sessionId) rememberSession(sessionId, btn);
      window.location.assign(url);
      return;
    }

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

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", resumeReturnedSession);
  } else {
    resumeReturnedSession();
  }
})();
