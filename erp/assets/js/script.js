/*
  TEK-C PMC Admin Common Script
  Safe for all admin pages.
  No demo dashboard data.
*/

/* =========================================================
   PAGE TOAST / MESSAGE
========================================================= */

function hidePageToast() {
  const toast = document.getElementById("pageToastWrap");

  if (toast) {
    toast.style.display = "none";
  }
}

document.addEventListener("DOMContentLoaded", function () {
  const messageModalEl = document.getElementById("pageMessageModal");
  const pageToast = document.getElementById("pageToastWrap");

  if (!messageModalEl && !pageToast) {
    return;
  }

  const isDesktop = window.matchMedia("(min-width: 992px)").matches;

  if (isDesktop) {
    if (pageToast) {
      pageToast.style.display = "block";

      window.setTimeout(function () {
        hidePageToast();
      }, 3000);
    }

    return;
  }

  if (messageModalEl && window.bootstrap) {
    const messageModal = new window.bootstrap.Modal(messageModalEl);

    messageModal.show();

    window.setTimeout(function () {
      messageModal.hide();
    }, 2000);
  }
});

/* =========================================================
   COMMON ADMIN FUNCTIONS
========================================================= */

(function () {
  "use strict";

  function qs(selector, parent = document) {
    return parent.querySelector(selector);
  }

  function qsa(selector, parent = document) {
    return Array.from(parent.querySelectorAll(selector));
  }

  function closeDropdowns(exceptId = null) {
    qsa(".dropdown-menu-custom").forEach(function (menu) {
      if (!exceptId || menu.id !== exceptId) {
        menu.classList.remove("show");
      }
    });
  }

  function refreshIcons(retryCount = 0) {
    if (window.lucide && typeof window.lucide.createIcons === "function") {
      window.lucide.createIcons();
      return;
    }

    if (retryCount < 20) {
      window.setTimeout(function () {
        refreshIcons(retryCount + 1);
      }, 100);
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    const body = document.body;
    const html = document.documentElement;

    /* =====================================================
       SIDEBAR TOGGLE
    ===================================================== */

    const sidebar = qs("#sidebar");
    const sidebarToggle = qs("#sidebarToggle");
    const mobileOverlay = qs("#mobileOverlay");
    const closeMobileSidebarButton = qs("#closeMobileSidebar");

    const desktopBreakpoint = 1280;

    function isDesktopSidebar() {
      return window.innerWidth >= desktopBreakpoint;
    }

    function isSidebarCollapsed() {
      return body.classList.contains("sidebar-collapsed");
    }

    function closeSidebarFlyouts() {
      qsa("#sidebar .sidebar-submenu.sidebar-flyout-open").forEach(
        function (submenu) {
          submenu.classList.remove("sidebar-flyout-open");
          submenu.style.top = "";
          submenu.style.left = "";
        },
      );

      qsa("#sidebar .sidebar-collapse-link").forEach(function (link) {
        if (isSidebarCollapsed()) {
          link.setAttribute("aria-expanded", "false");
        }
      });
    }

    function openMobileSidebar() {
      body.classList.add("mobile-sidebar-open");

      if (mobileOverlay) {
        mobileOverlay.classList.remove("d-none");
      }
    }

    function closeMobileSidebarPanel() {
      body.classList.remove("mobile-sidebar-open");

      if (mobileOverlay) {
        mobileOverlay.classList.add("d-none");
      }
    }

    function setDesktopSidebarState(collapsed, saveState = true) {
      body.classList.toggle("sidebar-collapsed", collapsed);

      if (sidebar) {
        sidebar.classList.toggle("collapsed", collapsed);
      }

      closeSidebarFlyouts();

      if (collapsed) {
        /*
         * Remove normal Bootstrap-opened submenus.
         * They will open as flyouts when the sidebar is collapsed.
         */
        qsa("#sidebar .sidebar-submenu.show").forEach(function (submenu) {
          submenu.classList.remove("show");
        });

        qsa("#sidebar .sidebar-collapse-link").forEach(function (link) {
          link.setAttribute("aria-expanded", "false");
        });
      }

      if (saveState) {
        try {
          localStorage.setItem(
            "tekc-sidebar-collapsed",
            collapsed ? "true" : "false",
          );
        } catch (error) {
          /*
           * Ignore localStorage errors.
           */
        }
      }
    }

    function toggleSidebar() {
      if (isDesktopSidebar()) {
        closeMobileSidebarPanel();

        const willCollapse = !isSidebarCollapsed();

        setDesktopSidebarState(willCollapse, true);
        return;
      }

      /*
       * Mobile must always use the full sidebar.
       */
      setDesktopSidebarState(false, false);

      if (body.classList.contains("mobile-sidebar-open")) {
        closeMobileSidebarPanel();
      } else {
        openMobileSidebar();
      }
    }

    if (sidebarToggle) {
      sidebarToggle.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();

        toggleSidebar();
      });
    }

    if (mobileOverlay) {
      mobileOverlay.addEventListener("click", function () {
        closeMobileSidebarPanel();
      });
    }

    if (closeMobileSidebarButton) {
      closeMobileSidebarButton.addEventListener("click", function () {
        closeMobileSidebarPanel();
      });
    }

    /*
     * Restore saved desktop sidebar state.
     */
    let savedSidebarState = false;

    try {
      savedSidebarState =
        localStorage.getItem("tekc-sidebar-collapsed") === "true";
    } catch (error) {
      savedSidebarState = false;
    }

    if (isDesktopSidebar()) {
      setDesktopSidebarState(savedSidebarState, false);
    } else {
      setDesktopSidebarState(false, false);
      closeMobileSidebarPanel();
    }

    /*
     * Correct the sidebar when crossing desktop/mobile breakpoint.
     */
    let previousDesktopState = isDesktopSidebar();

    window.addEventListener("resize", function () {
      const currentDesktopState = isDesktopSidebar();

      if (currentDesktopState === previousDesktopState) {
        return;
      }

      previousDesktopState = currentDesktopState;

      closeSidebarFlyouts();
      closeMobileSidebarPanel();

      if (currentDesktopState) {
        let restoredState = false;

        try {
          restoredState =
            localStorage.getItem("tekc-sidebar-collapsed") === "true";
        } catch (error) {
          restoredState = false;
        }

        setDesktopSidebarState(restoredState, false);
      } else {
        setDesktopSidebarState(false, false);
      }
    });

    /* =====================================================
       COLLAPSED SIDEBAR FLYOUT MENUS
    ===================================================== */

    let activeFlyout = null;
    let activeFlyoutTrigger = null;

    function closeActiveFlyout() {
      if (activeFlyout) {
        activeFlyout.classList.remove("sidebar-flyout-open");

        activeFlyout.style.top = "";
        activeFlyout.style.left = "";
      }

      if (activeFlyoutTrigger) {
        activeFlyoutTrigger.setAttribute("aria-expanded", "false");
      }

      activeFlyout = null;
      activeFlyoutTrigger = null;
    }

    function positionSidebarFlyout(trigger, flyout) {
      if (!trigger || !flyout) {
        return;
      }

      const triggerRect = trigger.getBoundingClientRect();

      const flyoutWidth = flyout.offsetWidth || 306;

      const flyoutHeight = flyout.offsetHeight || 200;

      const viewportWidth = window.innerWidth;

      const viewportHeight = window.innerHeight;

      const gap = 10;

      let left = triggerRect.right + gap;

      let top = triggerRect.top;

      if (left + flyoutWidth > viewportWidth - 12) {
        left = triggerRect.left - flyoutWidth - gap;
      }

      if (top + flyoutHeight > viewportHeight - 12) {
        top = Math.max(12, viewportHeight - flyoutHeight - 12);
      }

      flyout.style.left = Math.max(12, left) + "px";

      flyout.style.top = Math.max(12, top) + "px";
    }

    function openSidebarFlyout(trigger) {
      const targetId = trigger.getAttribute("data-sidebar-target");

      if (!targetId) {
        return;
      }

      const flyout = document.getElementById(targetId);

      if (!flyout) {
        return;
      }

      if (activeFlyout === flyout) {
        closeActiveFlyout();
        return;
      }

      closeActiveFlyout();

      flyout.classList.remove("show");
      flyout.classList.add("sidebar-flyout-open");

      trigger.setAttribute("aria-expanded", "true");

      activeFlyout = flyout;
      activeFlyoutTrigger = trigger;

      window.requestAnimationFrame(function () {
        positionSidebarFlyout(trigger, flyout);
      });
    }

    if (sidebar) {
      sidebar.addEventListener(
        "click",
        function (event) {
          const trigger = event.target.closest(".sidebar-collapse-link");

          if (!trigger || !isDesktopSidebar() || !isSidebarCollapsed()) {
            return;
          }

          event.preventDefault();
          event.stopImmediatePropagation();

          openSidebarFlyout(trigger);
        },
        true,
      );
    }

    document.addEventListener("click", function (event) {
      if (!activeFlyout) {
        return;
      }

      if (
        activeFlyout.contains(event.target) ||
        (activeFlyoutTrigger && activeFlyoutTrigger.contains(event.target))
      ) {
        return;
      }

      closeActiveFlyout();
    });

    window.addEventListener("resize", function () {
      if (!activeFlyout) {
        return;
      }

      if (!isDesktopSidebar() || !isSidebarCollapsed()) {
        closeActiveFlyout();
        return;
      }

      positionSidebarFlyout(activeFlyoutTrigger, activeFlyout);
    });

    window.addEventListener(
      "scroll",
      function () {
        if (activeFlyout && activeFlyoutTrigger) {
          positionSidebarFlyout(activeFlyoutTrigger, activeFlyout);
        }
      },
      true,
    );

    /* =====================================================
       CUSTOM DROPDOWNS
    ===================================================== */

    qsa(".dropdown-btn").forEach(function (btn) {
      btn.addEventListener("click", function (event) {
        event.stopPropagation();

        const id = btn.getAttribute("data-dropdown-target");

        const menu = id ? qs("#" + id) : null;

        if (!menu) {
          return;
        }

        const willOpen = !menu.classList.contains("show");

        closeDropdowns(id);

        menu.classList.toggle("show", willOpen);
      });
    });

    qsa(".dropdown-menu-custom").forEach(function (menu) {
      menu.addEventListener("click", function (event) {
        event.stopPropagation();
      });
    });

    document.addEventListener("click", function () {
      closeDropdowns();
    });

    /* =====================================================
       ESCAPE KEY
    ===================================================== */

    document.addEventListener("keydown", function (event) {
      if (event.key !== "Escape") {
        return;
      }

      closeDropdowns();
      closeActiveFlyout();
      closeSidebarFlyouts();
      closeMobileSidebarPanel();

      body.classList.remove("settings-panel-open");
    });

    /* =====================================================
       DATE DROPDOWN
    ===================================================== */

    qsa(".date-option").forEach(function (option) {
      option.addEventListener("click", function () {
        const selectedDateLabel = qs("#selectedDateLabel");

        if (selectedDateLabel && option.dataset.label) {
          selectedDateLabel.textContent = option.dataset.label;
        }

        closeDropdowns();
      });
    });

    /* =====================================================
       DYNAMIC FOOTER YEAR
    ===================================================== */

    const currentYear = qs("#currentYear");

    if (currentYear) {
      currentYear.textContent = new Date().getFullYear();
    }

    /* =====================================================
       SETTINGS PANEL
    ===================================================== */

    const settingsToggle = qs("#settingsToggle");

    const settingsClose = qs("#settingsClose");

    const settingsOverlay = qs("#settingsOverlay");

    if (settingsToggle) {
      settingsToggle.addEventListener("click", function () {
        body.classList.add("settings-panel-open");

        closeDropdowns();
        closeActiveFlyout();
      });
    }

    if (settingsClose) {
      settingsClose.addEventListener("click", function () {
        body.classList.remove("settings-panel-open");
      });
    }

    if (settingsOverlay) {
      settingsOverlay.addEventListener("click", function () {
        body.classList.remove("settings-panel-open");
      });
    }

    /* =====================================================
       DARK / LIGHT THEME
    ===================================================== */

    const themeToggle = qs("#themeToggle");

    const themeIcon = qs("#themeIcon");

    function updateThemeIcon() {
      if (themeIcon) {
        themeIcon.setAttribute(
          "data-lucide",
          html.classList.contains("dark") ? "sun" : "moon",
        );
      }

      refreshIcons();
    }

    let savedTheme = null;

    try {
      savedTheme = localStorage.getItem("tekc-theme");
    } catch (error) {
      savedTheme = null;
    }

    if (savedTheme === "dark") {
      html.classList.add("dark");
    }

    if (savedTheme === "light") {
      html.classList.remove("dark");
    }

    if (themeToggle) {
      themeToggle.addEventListener("click", function () {
        html.classList.toggle("dark");

        try {
          localStorage.setItem(
            "tekc-theme",
            html.classList.contains("dark") ? "dark" : "light",
          );
        } catch (error) {
          /*
           * Ignore localStorage errors.
           */
        }

        updateThemeIcon();
      });
    }

    updateThemeIcon();
    refreshIcons();
  });

  window.addEventListener("load", function () {
    refreshIcons();
  });
})();
