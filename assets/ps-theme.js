(function () {
  function current() {
    return document.documentElement.getAttribute("data-theme") === "light" ? "light" : "dark";
  }

  function apply(theme) {
    document.documentElement.setAttribute("data-theme", theme);
    try {
      localStorage.setItem("ps-theme", theme);
    } catch (e) {}
    document.querySelectorAll("[data-theme-value]").forEach(function (btn) {
      btn.classList.toggle("is-on", btn.getAttribute("data-theme-value") === theme);
    });
  }

  document.addEventListener("click", function (event) {
    var themeBtn = event.target.closest("[data-theme-value]");
    if (themeBtn) {
      event.preventDefault();
      apply(themeBtn.getAttribute("data-theme-value"));
      return;
    }

    var branch = event.target.closest("[data-ps-branch]");
    if (branch && document.getElementById("branchModalOverlay")) {
      event.preventDefault();
      var branchOverlay = document.getElementById("branchModalOverlay");
      // Match admin_dashboard showModal: escape .ps-content container-type so fixed covers viewport.
      if (branchOverlay.parentElement !== document.body) {
        document.body.appendChild(branchOverlay);
      }
      var branchErr = document.getElementById("branchModalError");
      if (branchErr) {
        branchErr.textContent = "";
        branchErr.hidden = true;
      }
      branchOverlay.style.display = "flex";
      return;
    }

    if (event.target.closest("[data-ps-burger]")) {
      document.body.classList.toggle("ps-nav-open");
      return;
    }

    if (event.target.closest("[data-ps-backdrop]")) {
      document.body.classList.remove("ps-nav-open");
    }
  });

  function pageOf(filter) {
    return (filter.getAttribute("data-ps-page") || "").toLowerCase();
  }

  function filterNav(term) {
    var needle = term.toLowerCase();
    document.querySelectorAll(".ps-nav a").forEach(function (link) {
      var label = link.textContent.toLowerCase();
      link.style.display = needle && label.indexOf(needle) === -1 ? "none" : "";
    });
    // Sections: while searching, show only sections with a match, opened; afterwards, back to how they were.
    document.querySelectorAll(".ps-nav .ps-nav-sec").forEach(function (sec) {
      var any = false;
      sec.querySelectorAll("a").forEach(function (a) { if (a.style.display !== "none") any = true; });
      sec.style.display = needle && !any ? "none" : "";
      if (needle) setSecOpen(sec, any, false);
      else setSecOpen(sec, sec.getAttribute("data-ps-current") === "1" || savedOpen(sec), false);
    });
  }

  // 2026-11-03: sidebar sections open and close like dropdowns; the choice is remembered on this browser.
  var NAV_KEY = "ps-nav-open";
  function navState() {
    try { return JSON.parse(localStorage.getItem(NAV_KEY) || "{}") || {}; } catch (e) { return {}; }
  }
  function savedOpen(sec) {
    return navState()[sec.getAttribute("data-ps-sec")] === 1;
  }
  function setSecOpen(sec, open, remember) {
    sec.classList.toggle("is-open", open);
    var btn = sec.querySelector("[data-ps-sec-toggle]");
    if (btn) btn.setAttribute("aria-expanded", open ? "true" : "false");
    if (remember) {
      var st = navState();
      if (open) st[sec.getAttribute("data-ps-sec")] = 1; else delete st[sec.getAttribute("data-ps-sec")];
      try { localStorage.setItem(NAV_KEY, JSON.stringify(st)); } catch (e) {}
    }
  }
  document.querySelectorAll(".ps-nav .ps-nav-sec").forEach(function (sec) {
    if (sec.getAttribute("data-ps-current") !== "1" && savedOpen(sec)) setSecOpen(sec, true, false);
  });
  document.addEventListener("click", function (event) {
    var btn = event.target.closest("[data-ps-sec-toggle]");
    if (!btn) return;
    var sec = btn.closest(".ps-nav-sec");
    if (sec) setSecOpen(sec, !sec.classList.contains("is-open"), true);
  });

  function productText(row) {
    var marked = row.getAttribute("data-ps-product");
    if (marked) return marked.toLowerCase();
    var cells = row.querySelectorAll("td");
    var idText = cells[0] ? cells[0].textContent : "";
    var nameInput = row.querySelector('input[name="product"]');
    var nameText = nameInput ? nameInput.value : (cells[1] ? cells[1].textContent : "");
    return (idText + " " + nameText).toLowerCase();
  }

  function filterItemsTable(term) {
    var needle = term.toLowerCase();
    document.querySelectorAll(".ps-content table tbody tr, .content table tbody tr").forEach(function (row) {
      if (row.querySelector("td[colspan]")) return;
      var hay = productText(row);
      row.style.display = needle && hay.indexOf(needle) === -1 ? "none" : "";
    });
  }

  function itemsSearchUrl(term) {
    return "items_list.php?search=" + encodeURIComponent(term);
  }

  var itemsSearchTimer = 0;
  function scheduleItemsSearch(term) {
    clearTimeout(itemsSearchTimer);
    itemsSearchTimer = setTimeout(function () {
      window.location.href = itemsSearchUrl(term);
    }, 350);
  }

  function drivePosSearch(term) {
    var posSearch = document.getElementById("search");
    if (!posSearch) return;
    if (posSearch.value !== term) posSearch.value = term;
    if (typeof window.loadCatalog === "function") window.loadCatalog();
  }

  function onProductSearch(filter, term) {
    var page = pageOf(filter);
    if (page === "items_list.php") {
      var loaded = (filter.getAttribute("data-ps-loaded-search") || "").trim().toLowerCase();
      var pageSearch = document.querySelector('form input[name="search"]');
      if (pageSearch && pageSearch !== filter) pageSearch.value = term;
      var needle = term.toLowerCase();
      if (loaded && needle.indexOf(loaded) === -1) {
        scheduleItemsSearch(term);
        return;
      }
      clearTimeout(itemsSearchTimer);
      filterItemsTable(term);
      return;
    }
    if (page === "ajax_pos.php") {
      drivePosSearch(term);
      return;
    }
    filterNav(term);
  }

  document.addEventListener("input", function (event) {
    var filter = event.target.closest("[data-ps-nav-filter]");
    if (filter) {
      onProductSearch(filter, filter.value.trim());
      return;
    }
    var named = event.target;
    if (!named || named.name !== "search") return;
    if (!/items_list\.php$/i.test(window.location.pathname || "")) return;
    var header = document.querySelector("[data-ps-nav-filter]");
    var term = named.value.trim();
    if (header && header !== named) {
      header.value = named.value;
      onProductSearch(header, term);
      return;
    }
    filterItemsTable(term);
  });

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Enter") return;
    var filter = event.target.closest("[data-ps-nav-filter]");
    if (!filter) return;
    var page = pageOf(filter);
    if (page === "ajax_pos.php") {
      event.preventDefault();
      drivePosSearch(filter.value.trim());
      return;
    }
    event.preventDefault();
    clearTimeout(itemsSearchTimer);
    if (filter.getAttribute("data-ps-menu-only") === "1") return;
    window.location.href = itemsSearchUrl(filter.value.trim());
  });

  var selectSeq = 0;
  var openSelect = null;
  var CHEVRON_SVG = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
  var CHECK_SVG = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';

  function selectLabel(select) {
    var opt = select.options[select.selectedIndex];
    if (!opt || select.selectedIndex < 0) return "";
    return (opt.textContent || "").replace(/\s+/g, " ").trim();
  }

  function selectIsPlaceholder(select) {
    var opt = select.options[select.selectedIndex];
    if (!opt || select.selectedIndex < 0) return true;
    return opt.value === "";
  }

  function closeSelect() {
    if (!openSelect) return;
    var state = openSelect;
    openSelect = null;
    state.panel.hidden = true;
    state.trigger.setAttribute("aria-expanded", "false");
    state.wrap.classList.remove("is-open");
  }

  function placeSelect(state) {
    var rect = state.trigger.getBoundingClientRect();
    var panel = state.panel;
    panel.hidden = false;
    panel.style.visibility = "hidden";
    panel.style.left = "0px";
    panel.style.top = "0px";
    panel.style.width = "max-content";
    panel.style.minWidth = rect.width + "px";
    var room = Math.min(window.innerWidth - 16, Math.max(rect.width, 320));
    panel.style.maxWidth = room + "px";
    var width = panel.getBoundingClientRect().width;
    if (width > room) width = room;
    var left = rect.left;
    if (left + width > window.innerWidth - 8) {
      left = Math.max(8, window.innerWidth - 8 - width);
    }
    panel.style.left = left + "px";
    panel.style.top = (rect.bottom + 4) + "px";
    panel.style.width = width + "px";
    panel.style.visibility = "visible";
    var selected = panel.querySelector(".is-selected");
    if (selected) {
      var top = selected.offsetTop;
      var bottom = top + selected.offsetHeight;
      if (top < panel.scrollTop) panel.scrollTop = top;
      else if (bottom > panel.scrollTop + panel.clientHeight) panel.scrollTop = bottom - panel.clientHeight;
    }
  }

  function openSelectPanel(state) {
    if (state.trigger.disabled) return;
    if (openSelect && openSelect !== state) closeSelect();
    // A modal <dialog> sits in the top layer and makes the rest of the page inert,
    // so the panel must live inside the dialog to render above it and receive clicks.
    var host = state.wrap.closest("dialog[open]") || document.body;
    if (state.panel.parentNode !== host) host.appendChild(state.panel);
    state.render();
    openSelect = state;
    state.trigger.setAttribute("aria-expanded", "true");
    state.wrap.classList.add("is-open");
    placeSelect(state);
  }

  function moveActive(state, delta) {
    var options = state.panel.querySelectorAll(".ps-select-option:not(:disabled)");
    if (!options.length) return;
    var current = state.panel.querySelector(".ps-select-option.is-active");
    var index = 0;
    if (current) {
      for (var i = 0; i < options.length; i++) {
        if (options[i] === current) index = i;
      }
      index = index + delta;
      if (index < 0) index = options.length - 1;
      if (index >= options.length) index = 0;
    }
    for (var j = 0; j < options.length; j++) {
      options[j].classList.toggle("is-active", j === index);
    }
    var activeTop = options[index].offsetTop;
    var activeBottom = activeTop + options[index].offsetHeight;
    if (activeTop < state.panel.scrollTop) state.panel.scrollTop = activeTop;
    else if (activeBottom > state.panel.scrollTop + state.panel.clientHeight) {
      state.panel.scrollTop = activeBottom - state.panel.clientHeight;
    }
  }

  function chooseOption(state, index) {
    var option = state.select.options[index];
    if (!option || option.disabled) return;
    state.select.selectedIndex = index;
    state.select.dispatchEvent(new Event("change", { bubbles: true }));
    state.sync();
    closeSelect();
    state.trigger.focus();
  }

  function enhanceSelect(select) {
    if (!select || select.getAttribute("data-ps-select") === "1") return;
    if (select.multiple || select.size > 1) return;
    if (!select.parentNode) return;
    select.setAttribute("data-ps-select", "1");

    var wrap = document.createElement("span");
    wrap.className = "ps-select";
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add("ps-select-native");
    select.tabIndex = -1;

    var trigger = document.createElement("button");
    trigger.type = "button";
    trigger.className = "ps-select-trigger";
    trigger.setAttribute("aria-haspopup", "listbox");
    trigger.setAttribute("aria-expanded", "false");
    if (select.id) {
      var labels = document.getElementsByTagName("label");
      var labelled = null;
      for (var n = 0; n < labels.length; n++) {
        if (labels[n].htmlFor === select.id) {
          labelled = labels[n];
          break;
        }
      }
      if (labelled) {
        if (!labelled.id) {
          selectSeq += 1;
          labelled.id = "ps-select-label-" + selectSeq;
        }
        trigger.setAttribute("aria-labelledby", labelled.id);
      }
    }

    var value = document.createElement("span");
    value.className = "ps-select-value";
    var chevron = document.createElement("span");
    chevron.className = "ps-select-chevron";
    chevron.setAttribute("aria-hidden", "true");
    chevron.innerHTML = CHEVRON_SVG;
    trigger.appendChild(value);
    trigger.appendChild(chevron);

    selectSeq += 1;
    var panel = document.createElement("div");
    panel.className = "ps-select-panel";
    panel.id = "ps-select-panel-" + selectSeq;
    panel.setAttribute("role", "listbox");
    panel.hidden = true;
    trigger.setAttribute("aria-controls", panel.id);
    document.body.appendChild(panel);

    var state = { select: select, wrap: wrap, trigger: trigger, panel: panel, value: value };

    state.sync = function () {
      value.textContent = selectLabel(select);
      trigger.classList.toggle("is-placeholder", selectIsPlaceholder(select));
      trigger.disabled = !!select.disabled;
    };

    state.render = function () {
      panel.innerHTML = "";
      function addOption(option) {
        var button = document.createElement("button");
        button.type = "button";
        button.className = "ps-select-option";
        button.setAttribute("role", "option");
        button.setAttribute("data-index", String(option.index));
        var selected = option.index === select.selectedIndex;
        button.classList.toggle("is-selected", selected);
        button.classList.toggle("is-active", selected);
        button.setAttribute("aria-selected", selected ? "true" : "false");
        button.disabled = !!option.disabled;
        var check = document.createElement("span");
        check.className = "ps-select-check";
        check.setAttribute("aria-hidden", "true");
        check.innerHTML = CHECK_SVG;
        var label = document.createElement("span");
        label.className = "ps-select-option-label";
        label.textContent = (option.textContent || "").replace(/\s+/g, " ").trim();
        button.appendChild(check);
        button.appendChild(label);
        button.addEventListener("click", function (event) {
          event.preventDefault();
          event.stopPropagation();
          chooseOption(state, option.index);
        });
        panel.appendChild(button);
      }
      var nodes = select.children;
      for (var i = 0; i < nodes.length; i++) {
        var node = nodes[i];
        if (node.tagName === "OPTGROUP") {
          var group = document.createElement("div");
          group.className = "ps-select-group";
          group.textContent = node.label || "";
          panel.appendChild(group);
          var nested = node.children;
          for (var j = 0; j < nested.length; j++) {
            if (nested[j].tagName === "OPTION") addOption(nested[j]);
          }
        } else if (node.tagName === "OPTION") {
          addOption(node);
        }
      }
    };

    trigger.addEventListener("click", function (event) {
      event.preventDefault();
      event.stopPropagation();
      if (openSelect === state) {
        closeSelect();
        return;
      }
      openSelectPanel(state);
    });

    trigger.addEventListener("keydown", function (event) {
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        if (openSelect !== state) {
          openSelectPanel(state);
          return;
        }
        moveActive(state, event.key === "ArrowDown" ? 1 : -1);
        return;
      }
      if (event.key === "Enter" && openSelect === state) {
        var active = panel.querySelector(".ps-select-option.is-active");
        if (!active) return;
        event.preventDefault();
        chooseOption(state, Number(active.getAttribute("data-index")));
      }
    });

    select.addEventListener("change", state.sync);
    select.addEventListener("focus", function () {
      if (!trigger.disabled) trigger.focus();
    });

    if (window.MutationObserver) {
      var optionWatch = new MutationObserver(function () {
        state.sync();
        if (openSelect === state) state.render();
      });
      optionWatch.observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ["disabled", "label", "selected"] });
    }

    wrap.appendChild(trigger);
    state.sync();
  }

  function enhanceSelects() {
    if (!document.body) return;
    // 2026-11-05: the till's payment sheet uses the same modern dropdown (split payment rows are enhanced as they are
    // added, through window.psEnhanceSelect).
    if (document.body.classList.contains("ps-pos")) {
      document.querySelectorAll("body.ps-pos .pay-sheet select").forEach(enhanceSelect);
      return;
    }
    if (!document.body.classList.contains("ps-app")) return;
    document.querySelectorAll("body.ps-app select").forEach(enhanceSelect);
  }
  window.psEnhanceSelect = enhanceSelect;

  document.addEventListener("click", function (event) {
    if (!openSelect) return;
    if (openSelect.wrap.contains(event.target)) return;
    if (openSelect.panel.contains(event.target)) return;
    closeSelect();
  });

  document.addEventListener("keydown", function (event) {
    if (!openSelect || event.key !== "Escape") return;
    event.preventDefault();
    var trigger = openSelect.trigger;
    closeSelect();
    trigger.focus();
  });

  window.addEventListener("resize", function () {
    if (openSelect) placeSelect(openSelect);
  });

  document.addEventListener("close", function (event) {
    if (openSelect && event.target && event.target.contains && event.target.contains(openSelect.wrap)) closeSelect();
  }, true);

  window.addEventListener("scroll", function (event) {
    if (!openSelect) return;
    if (event.target && openSelect.panel.contains(event.target)) return;
    closeSelect();
  }, true);

  /* Shared account / user-chip menu + logout confirm (shell + POS) */
  function isUserChipMenuOpen() {
    var menu = document.getElementById("userChipMenu");
    return !!(menu && !menu.hidden);
  }

  function closeUserChipMenu() {
    var menu = document.getElementById("userChipMenu");
    var btn = document.getElementById("userChipBtn");
    if (!menu || !btn) return;
    menu.hidden = true;
    btn.setAttribute("aria-expanded", "false");
  }

  function userChipMenuItems() {
    var menu = document.getElementById("userChipMenu");
    if (!menu) return [];
    return Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]')).filter(function (el) {
      return !el.disabled && el.getAttribute("aria-disabled") !== "true";
    });
  }

  function openUserChipMenu() {
    var menu = document.getElementById("userChipMenu");
    var btn = document.getElementById("userChipBtn");
    if (!menu || !btn) return;
    menu.hidden = false;
    btn.setAttribute("aria-expanded", "true");
    var items = userChipMenuItems();
    if (items.length && typeof items[0].focus === "function") items[0].focus();
  }

  function toggleUserChipMenu() {
    if (isUserChipMenuOpen()) closeUserChipMenu();
    else openUserChipMenu();
  }

  function openLogoutConfirm() {
    closeUserChipMenu();
    if (typeof window.closeSheets === "function") {
      try { window.closeSheets(); } catch (e) {}
    }
    var sheet = document.getElementById("logoutSheet");
    if (!sheet) return;
    sheet.classList.add("show");
    var cancel = document.getElementById("logoutCancel");
    if (cancel && typeof cancel.focus === "function") cancel.focus();
  }

  function closeLogoutConfirm() {
    var sheet = document.getElementById("logoutSheet");
    if (!sheet) return;
    sheet.classList.remove("show");
    var btn = document.getElementById("userChipBtn");
    if (btn && typeof btn.focus === "function") btn.focus();
  }

  function confirmLogout() {
    // POST + CSRF so another site cannot log a cashier out with a link (health-check 2026-10-13).
    var meta = document.querySelector('meta[name="csrf-token"]');
    var tok = meta ? meta.getAttribute("content") : "";
    if (!tok) {
      window.location.href = "logout.php";
      return;
    }
    var f = document.createElement("form");
    f.method = "post";
    f.action = "logout.php";
    var i = document.createElement("input");
    i.type = "hidden";
    i.name = "csrf_token";
    i.value = tok;
    f.appendChild(i);
    document.body.appendChild(f);
    f.submit();
  }

  window.isUserChipMenuOpen = isUserChipMenuOpen;
  window.closeUserChipMenu = closeUserChipMenu;
  window.openUserChipMenu = openUserChipMenu;
  window.toggleUserChipMenu = toggleUserChipMenu;
  window.openLogoutConfirm = openLogoutConfirm;
  window.closeLogoutConfirm = closeLogoutConfirm;
  window.confirmLogout = confirmLogout;

  function initAccountMenu() {
    var btn = document.getElementById("userChipBtn");
    var menu = document.getElementById("userChipMenu");
    var logoutBtn = document.getElementById("userChipLogout");
    var logoutConfirm = document.getElementById("logoutConfirm");
    var logoutCancel = document.getElementById("logoutCancel");

    if (btn) {
      btn.addEventListener("click", function (event) {
        event.stopPropagation();
        toggleUserChipMenu();
      });
      btn.addEventListener("keydown", function (event) {
        if (event.key === "ArrowDown" || event.key === "Enter" || event.key === " ") {
          if (event.key === "Enter" || event.key === " ") event.preventDefault();
          if (event.key === "ArrowDown") event.preventDefault();
          openUserChipMenu();
        }
      });
    }

    if (menu) {
      menu.querySelectorAll('a[role="menuitem"]').forEach(function (link) {
        link.addEventListener("click", function () {
          closeUserChipMenu();
        });
      });
      menu.addEventListener("keydown", function (event) {
        var items = userChipMenuItems();
        if (!items.length) return;
        var idx = items.indexOf(document.activeElement);
        if (event.key === "Escape") {
          event.preventDefault();
          closeUserChipMenu();
          if (btn) btn.focus();
          return;
        }
        if (event.key === "ArrowDown") {
          event.preventDefault();
          var next = items[(idx < 0 ? 0 : idx + 1) % items.length];
          if (next) next.focus();
          return;
        }
        if (event.key === "ArrowUp") {
          event.preventDefault();
          var prev = items[(idx <= 0 ? items.length : idx) - 1];
          if (prev) prev.focus();
          return;
        }
        if (event.key === "Home") {
          event.preventDefault();
          items[0].focus();
          return;
        }
        if (event.key === "End") {
          event.preventDefault();
          items[items.length - 1].focus();
        }
      });
    }

    if (logoutBtn) {
      logoutBtn.addEventListener("click", function () {
        openLogoutConfirm();
      });
    }
    if (logoutConfirm) {
      logoutConfirm.addEventListener("click", confirmLogout);
    }
    if (logoutCancel) {
      logoutCancel.addEventListener("click", closeLogoutConfirm);
    }
  }

  function isLogoutHref(href) {
    if (!href) return false;
    href = String(href).trim();
    return /(^|\/)logout\.php(\?|#|$)/i.test(href);
  }

  document.addEventListener("click", function (event) {
    var chipWrap = event.target.closest(".user-chip-wrap");
    if (!chipWrap) closeUserChipMenu();

    var logoutLink = event.target.closest("[data-ps-logout]");
    if (!logoutLink) {
      var anchor = event.target.closest("a[href]");
      if (anchor && isLogoutHref(anchor.getAttribute("href"))) {
        logoutLink = anchor;
      }
    }
    if (logoutLink) {
      event.preventDefault();
      openLogoutConfirm();
    }
  });

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") return;
    var logoutSheet = document.getElementById("logoutSheet");
    if (logoutSheet && logoutSheet.classList.contains("show")) {
      event.preventDefault();
      closeLogoutConfirm();
      return;
    }
    if (isUserChipMenuOpen()) {
      event.preventDefault();
      closeUserChipMenu();
      var chipBtn = document.getElementById("userChipBtn");
      if (chipBtn) chipBtn.focus();
    }
  });

  function closeActionMenus(except) {
    document.querySelectorAll(".ps-action-menu.is-open").forEach(function (menu) {
      if (except && menu === except) return;
      menu.classList.remove("is-open");
      var panel = menu.querySelector(".ps-action-menu-panel");
      var toggle = menu.querySelector("[data-ps-action-toggle]");
      if (panel) panel.hidden = true;
      if (toggle) toggle.setAttribute("aria-expanded", "false");
    });
  }

  function initActionMenus() {
    document.addEventListener("click", function (event) {
      var toggle = event.target.closest("[data-ps-action-toggle]");
      if (toggle) {
        event.preventDefault();
        var menu = toggle.closest(".ps-action-menu");
        if (!menu) return;
        var panel = menu.querySelector(".ps-action-menu-panel");
        if (!panel) return;
        var willOpen = panel.hidden;
        closeActionMenus(willOpen ? menu : null);
        panel.hidden = !willOpen;
        menu.classList.toggle("is-open", willOpen);
        toggle.setAttribute("aria-expanded", willOpen ? "true" : "false");
        return;
      }
      if (!event.target.closest(".ps-action-menu")) {
        closeActionMenus();
      }
    });
    document.addEventListener("keydown", function (event) {
      if (event.key !== "Escape") return;
      closeActionMenus();
    });
  }

  function bootTheme() {
    apply(current());
    enhanceSelects();
    initAccountMenu();
    initActionMenus();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootTheme);
  } else {
    bootTheme();
  }
})();

/* 2026-10-21 Currency tooltips in reports (includes/fx_report.php): hover and keyboard focus are CSS;
   click / tap toggles (touch screens), Escape or a click elsewhere closes. Keeps the tip inside the window. */
(function () {
  function place(el) {
    var tip = el.querySelector(".fxa-tip");
    if (!tip) return;
    el.classList.remove("tip-left");
    var r = el.getBoundingClientRect();
    if (r.right - 360 < 8) el.classList.add("tip-left");
  }
  document.addEventListener("click", function (e) {
    var el = e.target.closest ? e.target.closest("[data-fx-tip]") : null;
    document.querySelectorAll("[data-fx-tip].open").forEach(function (o) { if (o !== el) o.classList.remove("open"); });
    if (el) { place(el); el.classList.toggle("open"); }
  });
  document.addEventListener("keydown", function (e) {
    var el = e.target.closest ? e.target.closest("[data-fx-tip]") : null;
    if (e.key === "Escape") {
      document.querySelectorAll("[data-fx-tip].open").forEach(function (o) { o.classList.remove("open"); });
      if (el) el.blur();
    } else if (el && (e.key === "Enter" || e.key === " ")) {
      e.preventDefault(); place(el); el.classList.toggle("open");
    }
  });
  document.addEventListener("mouseover", function (e) {
    var el = e.target.closest ? e.target.closest("[data-fx-tip]") : null;
    if (el) place(el);
  });
  document.addEventListener("focusin", function (e) {
    var el = e.target.closest ? e.target.closest("[data-fx-tip]") : null;
    if (el) place(el);
  });
})();
