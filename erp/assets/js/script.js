/*
  TEK-C PMC Admin Common Script
  Safe for all admin pages.
  No demo dashboard data.
*/
// Add this to assets/js/script.js for all pages

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
      setTimeout(hidePageToast, 3000);
    }
  } else if (messageModalEl && window.bootstrap) {
    const messageModal = new bootstrap.Modal(messageModalEl);
    messageModal.show();

    setTimeout(function () {
      messageModal.hide();
    }, 2000);
  }
});

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
      setTimeout(function () {
        refreshIcons(retryCount + 1);
      }, 100);
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    const body = document.body;
    const html = document.documentElement;

    const sidebarToggle = qs("#sidebarToggle");
    const mobileOverlay = qs("#mobileOverlay");
    const closeMobileSidebar = qs("#closeMobileSidebar");

    if (sidebarToggle) {
      sidebarToggle.addEventListener("click", function () {
        if (window.innerWidth < 1280) {
          body.classList.toggle("mobile-sidebar-open");

          if (mobileOverlay) {
            mobileOverlay.classList.toggle(
              "d-none",
              !body.classList.contains("mobile-sidebar-open")
            );
          }
        } else {
          body.classList.toggle("sidebar-collapsed");
          localStorage.setItem(
            "tekc-sidebar-collapsed",
            body.classList.contains("sidebar-collapsed") ? "true" : "false"
          );
        }
      });
    }

    if (mobileOverlay) {
      mobileOverlay.addEventListener("click", function () {
        body.classList.remove("mobile-sidebar-open");
        mobileOverlay.classList.add("d-none");
      });
    }

    if (closeMobileSidebar) {
      closeMobileSidebar.addEventListener("click", function () {
        body.classList.remove("mobile-sidebar-open");

        if (mobileOverlay) {
          mobileOverlay.classList.add("d-none");
        }
      });
    }

    const savedSidebar = localStorage.getItem("tekc-sidebar-collapsed");
    if (savedSidebar === "true" && window.innerWidth >= 1280) {
      body.classList.add("sidebar-collapsed");
    }

    qsa(".dropdown-btn").forEach(function (btn) {
      btn.addEventListener("click", function (event) {
        event.stopPropagation();

        const id = btn.getAttribute("data-dropdown-target");
        const menu = id ? qs("#" + id) : null;

        if (!menu) return;

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

    document.addEventListener("keydown", function (event) {
      if (event.key !== "Escape") return;

      closeDropdowns();
      body.classList.remove("mobile-sidebar-open");
      body.classList.remove("settings-panel-open");

      if (mobileOverlay) {
        mobileOverlay.classList.add("d-none");
      }
    });

    qsa(".date-option").forEach(function (option) {
      option.addEventListener("click", function () {
        const selectedDateLabel = qs("#selectedDateLabel");

        if (selectedDateLabel && option.dataset.label) {
          selectedDateLabel.textContent = option.dataset.label;
        }

        closeDropdowns();
      });
    });

    const currentYear = qs("#currentYear");
    if (currentYear) {
      currentYear.textContent = new Date().getFullYear();
    }

    const settingsToggle = qs("#settingsToggle");
    const settingsClose = qs("#settingsClose");
    const settingsOverlay = qs("#settingsOverlay");

    if (settingsToggle) {
      settingsToggle.addEventListener("click", function () {
        body.classList.add("settings-panel-open");
        closeDropdowns();
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

    const themeToggle = qs("#themeToggle");
    const themeIcon = qs("#themeIcon");

    function updateThemeIcon() {
      if (themeIcon) {
        themeIcon.setAttribute(
          "data-lucide",
          html.classList.contains("dark") ? "sun" : "moon"
        );
      }

      refreshIcons();
    }

    const savedTheme = localStorage.getItem("tekc-theme");

    if (savedTheme === "dark") {
      html.classList.add("dark");
    }

    if (savedTheme === "light") {
      html.classList.remove("dark");
    }

    if (themeToggle) {
      themeToggle.addEventListener("click", function () {
        html.classList.toggle("dark");

        localStorage.setItem(
          "tekc-theme",
          html.classList.contains("dark") ? "dark" : "light"
        );

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