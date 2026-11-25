(function () {
  "use strict";

  var cfg = window.diditContact;
  if (!cfg) return;
  var i18n = cfg.i18n || {};

  function call(step, body) {
    body.order_id = cfg.orderId;
    body.order_key = cfg.orderKey;
    return fetch(cfg.restUrl + step, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-WP-Nonce": cfg.nonce },
      body: JSON.stringify(body)
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) throw new Error(data.message || i18n.error);
        return data;
      });
    });
  }

  document.addEventListener("click", function (e) {
    var send = e.target.closest(".didit-contact-send");
    var check = e.target.closest(".didit-contact-check");
    var box = (send || check) && (send || check).closest(".didit-contact");
    if (!box) return;
    e.preventDefault();
    var feedback = box.querySelector(".didit-feedback");
    var button = send || check;
    var channel = box.getAttribute("data-channel");
    var body = { channel: channel };
    if (check) {
      body.code = (box.querySelector("input").value || "").trim();
    }
    button.disabled = true;
    feedback.classList.remove("didit-feedback-error");
    call(send ? "send" : "check", body).then(function (data) {
      if (data.status === "Approved") {
        feedback.textContent = i18n.verified;
        box.querySelector(".didit-contact-code").hidden = true;
        box.querySelector(".didit-contact-send").hidden = true;
        return;
      }
      feedback.textContent = i18n.sent;
      box.querySelector(".didit-contact-code").hidden = false;
      box.querySelector("input").focus();
      button.disabled = false;
    }).catch(function (error) {
      feedback.textContent = error.message || i18n.error;
      feedback.classList.add("didit-feedback-error");
      button.disabled = false;
    });
  });
})();
