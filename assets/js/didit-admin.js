(function () {
  var vendorSelect = document.getElementById("didit_vendor_data_mode");
  if (vendorSelect) {
    vendorSelect.addEventListener("change", function () {
      document.getElementById("didit-vendor-prefix-row").style.display =
        this.value === "custom" ? "" : "none";
    });
  }

  var preview = document.getElementById("didit-btn-preview");
  if (!preview) return;

  function bind(name, prop, unit) {
    var el = document.querySelector('[name="' + name + '"]');
    if (!el) return;
    el.addEventListener("input", function () {
      if (prop === "textContent") { preview.textContent = this.value; return; }
      if (prop === "padding") {
        var pv = document.querySelector('[name="didit_btn_padding_v"]');
        var ph = document.querySelector('[name="didit_btn_padding_h"]');
        preview.style.padding = (pv ? pv.value : 12) + "px " + (ph ? ph.value : 24) + "px";
        return;
      }
      preview.style[prop] = this.value + (unit || "");
    });
  }

  bind("didit_btn_text", "textContent");
  bind("didit_btn_bg_color", "background");
  bind("didit_btn_text_color", "color");
  bind("didit_btn_border_radius", "borderRadius", "px");
  bind("didit_btn_padding_v", "padding");
  bind("didit_btn_padding_h", "padding");
  bind("didit_btn_font_size", "fontSize", "px");
})();
