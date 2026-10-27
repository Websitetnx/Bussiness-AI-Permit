"use strict";

// Shared browser behavior; server requests use the existing public endpoints.

const $ = (selector, root = document) => root?.querySelector(selector) ?? null;
const $$ = (selector, root = document) => root ? [...root.querySelectorAll(selector)] : [];
const MAX_FILE_SIZE = 5 * 1024 * 1024;
const VALID_EXTENSIONS = /\.(pdf|jpe?g|png)$/i;
const reduceMotionQuery = window.matchMedia
  ? window.matchMedia("(prefers-reduced-motion: reduce)")
  : { matches: false };
const prefersReducedMotion = () => reduceMotionQuery.matches;

const menuButton = $("#menuButton");
if (menuButton) {
  menuButton.addEventListener("click", () => {
    const sidebar = $("#sidebar");
    const open = sidebar.classList.toggle("open");
    menuButton.setAttribute("aria-expanded", String(open));
  });
}

$$('.toast').forEach(toast => setTimeout(() => {
  if (prefersReducedMotion()) {
    toast.remove();
    return;
  }
  toast.classList.add("toast-exit");
  toast.addEventListener("animationend", () => toast.remove(), { once: true });
  setTimeout(() => toast.remove(), 350);
}, 5000));

function setUploadState(input) {
  const card = input.closest(".upload-card");
  const file = input.files?.[0];
  const error = $(".error", card);
  let message = "";
  if (file && file.size > MAX_FILE_SIZE) message = "File must be 5 MB or smaller.";
  if (file && !VALID_EXTENSIONS.test(file.name)) message = "Use PDF, JPG, or PNG only.";
  card.classList.toggle("has-file", Boolean(file) && !message);
  card.classList.toggle("invalid", Boolean(message));
  $("em", card).textContent = file ? file.name : "Choose file";
  if (error) error.textContent = message;
  return !message;
}

$$('.upload-card input[type="file"]').forEach(input => input.addEventListener("change", () => setUploadState(input)));

$$('[data-geolocate]').forEach(button => {
  button.addEventListener("click", () => {
    const form = button.closest("form");
    const status = $('[data-location-status]', form);
    const latitude = $('[name="latitude"]', form);
    const longitude = $('[name="longitude"]', form);
    const accuracy = $('[name="location_accuracy_m"]', form);
    const locationUpdated = $('[name="location_updated"]', form);
    const setStatus = (message, type = "") => {
      status.textContent = message;
      status.classList.toggle("success", type === "success");
      status.classList.toggle("error", type === "error");
    };

    if (!window.isSecureContext) {
      setStatus("Location access requires HTTPS or localhost. You can still enter the address manually.", "error");
      return;
    }
    if (!navigator.geolocation) {
      setStatus("This browser does not support location access. Enter the address manually.", "error");
      return;
    }

    button.disabled = true;
    button.setAttribute("aria-busy", "true");
    setStatus("Requesting your current location…");
    navigator.geolocation.getCurrentPosition(position => {
      latitude.value = position.coords.latitude.toFixed(7);
      longitude.value = position.coords.longitude.toFixed(7);
      accuracy.value = Number.isFinite(position.coords.accuracy) ? position.coords.accuracy.toFixed(2) : "";
      if (locationUpdated) locationUpdated.value = "1";
      const accuracyCopy = accuracy.value ? ` (accuracy ±${Math.round(Number(accuracy.value))} m)` : "";
      setStatus(`Location captured${accuracyCopy}.`, "success");
      button.textContent = "✓ Location captured";
      button.disabled = false;
      button.removeAttribute("aria-busy");
    }, error => {
      const messages = {
        1: "Location permission was denied. Enter the address manually or allow location and try again.",
        2: "Your current location is unavailable. Enter the address manually or try again.",
        3: "Location request timed out. Enter the address manually or try again."
      };
      setStatus(messages[error.code] || "The location could not be captured. Enter the address manually.", "error");
      button.disabled = false;
      button.removeAttribute("aria-busy");
    }, {
      enableHighAccuracy: true,
      timeout: 15000,
      maximumAge: 30000
    });
  });
});

function fieldIsValid(field) {
  if (field.type === "file") {
    if (!setUploadState(field)) return false;
    if (field.required && !field.files?.length) {
      const card = field.closest(".upload-card");
      card.classList.add("invalid");
      $(".error", card).textContent = "This document is required.";
      return false;
    }
    return true;
  }
  if (!field.checkValidity()) {
    field.reportValidity();
    return false;
  }
  return true;
}

function validateStep(stepNumber) {
  const step = $(`[data-form-step="${stepNumber}"]`);
  if (!step) return true;
  const fields = $$('input[required], select[required], input[type="file"]', step);
  for (const field of fields) {
    if (!fieldIsValid(field)) {
      field.focus();
      return false;
    }
  }
  if (stepNumber === 2) {
    const occupancy = $('[name="occupancy_doc"]', step);
    const affidavit = $('[name="occupancy_affidavit_doc"]', step);
    if (occupancy && affidavit && !occupancy.files.length && !affidavit.files.length) {
      const card = occupancy.closest(".upload-card");
      card.classList.add("invalid");
      $(".error", card).textContent = "Upload this permit or the affidavit alternative.";
      occupancy.focus();
      return false;
    }
  }
  return true;
}

function showStep(stepNumber) {
  $$(".form-step").forEach(step => step.classList.toggle("active", Number(step.dataset.formStep) === stepNumber));
  $$('[data-step-indicator]').forEach(indicator => {
    const number = Number(indicator.dataset.stepIndicator);
    indicator.classList.toggle("active", number === stepNumber);
    indicator.classList.toggle("complete", number < stepNumber);
  });
  if (stepNumber === 3) buildReview();
  window.scrollTo({ top: 0, behavior: prefersReducedMotion() ? "auto" : "smooth" });
}

function humanize(name) {
  return name.replace(/_/g, " ").replace(/\b\w/g, character => character.toUpperCase());
}

function buildReview() {
  const form = $("#applicationForm");
  const review = $("#applicationReview");
  if (!form || !review) return;
  const entries = [];
  $$('input:not([type="hidden"]):not([type="checkbox"]), select', form).forEach(field => {
    const label = field.closest(".field, .upload-card")?.querySelector("strong")?.textContent || humanize(field.name);
    const value = field.type === "file" ? (field.files?.[0]?.name || "Not provided — if applicable") : field.value;
    entries.push([label, value || "—"]);
  });
  const inspections = $$('input[type="checkbox"][name^="requires_"]:checked', form).map(field => humanize(field.name.replace(/^requires_/, '')));
  entries.push(["Additional Inspections", inspections.length ? inspections.join(", ") : "None selected"]);
  const latitude = $('[name="latitude"]', form)?.value;
  const longitude = $('[name="longitude"]', form)?.value;
  entries.push(["Business Location", latitude && longitude ? `${latitude}, ${longitude}` : "Not provided — optional"]);
  review.replaceChildren(...entries.map(([label, value]) => {
    const wrapper = document.createElement("div");
    const term = document.createElement("dt");
    const description = document.createElement("dd");
    term.textContent = label;
    description.textContent = value;
    wrapper.append(term, description);
    return wrapper;
  }));
}

const applicationForm = $("#applicationForm");
if (applicationForm) {
  applicationForm.addEventListener("click", event => {
    const next = event.target.closest("[data-next-step]");
    const previous = event.target.closest("[data-prev-step]");
    if (next) {
      const current = Number(next.closest(".form-step").dataset.formStep);
      if (validateStep(current)) showStep(Number(next.dataset.nextStep));
    }
    if (previous) showStep(Number(previous.dataset.prevStep));
  });
  applicationForm.addEventListener("submit", event => {
    if (!validateStep(1) || !validateStep(2) || !applicationForm.checkValidity()) {
      event.preventDefault();
      return;
    }
    showScanningOverlay(applicationForm);
  });
}

// --- Scanning Overlay ---
function showScanningOverlay(sourceForm = null) {
  if (document.getElementById("scanningOverlay")) return;
  if (sourceForm) {
    sourceForm.setAttribute("aria-busy", "true");
    $$('button[type="submit"]', sourceForm).forEach(button => {
      button.disabled = true;
    });
  }
  const overlay = document.createElement("div");
  overlay.id = "scanningOverlay";
  overlay.className = "scanning-overlay";
  overlay.setAttribute("role", "status");
  overlay.setAttribute("aria-live", "polite");
  overlay.setAttribute("aria-label", "Submitting and scanning uploaded documents");
  overlay.innerHTML = `
    <div class="scanning-modal">
      <div class="scanning-spinner" aria-hidden="true"></div>
      <h3>Scanning your documents</h3>
      <p>Your files are being submitted and checked. If automated scanning is unavailable, your application will continue to BPLO review.</p>
      <div class="scanning-progress" aria-hidden="true">
        <div class="scanning-progress-bar"></div>
      </div>
      <small>Please keep this page open until submission is complete.</small>
    </div>
  `;
  document.body.classList.add("scanning-active");
  document.body.appendChild(overlay);
  requestAnimationFrame(() => overlay.classList.add("visible"));
}

// Also show overlay for renewal form
const renewalForm = document.querySelector(".renewal-application-form");
if (renewalForm) {
  renewalForm.addEventListener("submit", () => showScanningOverlay(renewalForm));
}

// --- Re-upload form handling ---
$$(".reupload-form").forEach(form => {
  const input = $('input[type="file"]', form);
  if (input) {
    input.addEventListener("change", () => {
      const file = input.files?.[0];
      const label = $(".reupload-input span", form);
      if (file) {
        let error = "";
        if (file.size > MAX_FILE_SIZE) error = "File must be 5 MB or smaller.";
        if (!VALID_EXTENSIONS.test(file.name)) error = "Use PDF, JPG, or PNG only.";
        if (error) {
          if (label) {
            label.textContent = error;
            label.style.color = "var(--red, #c43d4b)";
          }
          input.value = "";
        } else {
          if (label) {
            label.textContent = file.name;
            label.style.color = "";
          }
        }
      }
    });
  }
  form.addEventListener("submit", () => showScanningOverlay(form));
});

// ----------------------------------------------------
// Intelligent Notification Center Client
// ----------------------------------------------------
const notificationBellBtn = $("#notificationBellBtn");
const notificationDropdown = $("#notificationDropdown");
const notificationBadge = $("#notificationBadge");
const notificationList = $("#notificationList");
const markAllReadBtn = $("#markAllReadBtn");

function getApiUrl(endpoint) {
  const isAdmin = window.location.pathname.includes("/admin");
  return (isAdmin ? "../api/" : "api/") + endpoint;
}

async function fetchNotifications() {
  if (!notificationBellBtn) return;
  try {
    const res = await fetch(getApiUrl("notifications.php?action=list"));
    if (!res.ok) return;
    const data = await res.json();
    if (!data.ok) return;

    // Update Badge
    const unread = Number(data.unread_count || 0);
    if (notificationBadge) {
      if (unread > 0) {
        notificationBadge.textContent = unread > 9 ? "9+" : String(unread);
        notificationBadge.style.display = "inline-flex";
      } else {
        notificationBadge.style.display = "none";
      }
    }

    // Render Dropdown List
    if (notificationList) {
      if (!data.notifications || !data.notifications.length) {
        notificationList.innerHTML = `<div class="notification-empty">No notifications yet. You're all caught up! ✨</div>`;
        return;
      }

      notificationList.innerHTML = data.notifications.map(item => {
        const unreadClass = item.is_read ? "" : "unread";
        const link = item.detail_url || null;
        const actionLabel = item.action_label || "View details";

        return `
          <div class="notification-item ${unreadClass}" data-id="${item.id}">
            <div class="notification-content">
              <p class="notification-text">${escapeHtml(item.message)}</p>
              <div class="notification-meta">
                <small>${escapeHtml(item.time_ago)}</small>
                ${link ? `<a href="${escapeHtml(link)}" class="notification-action-link">${escapeHtml(actionLabel)} →</a>` : ""}
              </div>
            </div>
            ${!item.is_read ? `<button type="button" class="btn-item-read" title="Mark as read" data-mark-read="${item.id}">✓</button>` : ""}
          </div>
        `;
      }).join("");

      // Add click handlers for single mark as read
      $$('[data-mark-read]', notificationList).forEach(btn => {
        btn.addEventListener("click", async (e) => {
          e.stopPropagation();
          const id = btn.dataset.markRead;
          const formData = new FormData();
          formData.append("action", "mark_read");
          formData.append("id", id);
          await fetch(getApiUrl("notifications.php"), { method: "POST", body: formData });
          const item = btn.closest(".notification-item");
          if (item) item.classList.remove("unread");
          btn.remove();
          fetchNotifications();
        });
      });
    }
  } catch (err) {
    console.warn("Notifications could not be loaded", err);
  }
}

if (notificationBellBtn && notificationDropdown) {
  notificationBellBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    const open = notificationDropdown.classList.toggle("open");
    notificationBellBtn.setAttribute("aria-expanded", String(open));
    notificationDropdown.setAttribute("aria-hidden", String(!open));
    if (open) fetchNotifications();
  });

  document.addEventListener("click", (e) => {
    if (!notificationDropdown.contains(e.target) && !notificationBellBtn.contains(e.target)) {
      notificationDropdown.classList.remove("open");
      notificationBellBtn.setAttribute("aria-expanded", "false");
      notificationDropdown.setAttribute("aria-hidden", "true");
    }
  });

  if (markAllReadBtn) {
    markAllReadBtn.addEventListener("click", async () => {
      const formData = new FormData();
      formData.append("action", "mark_all_read");
      await fetch(getApiUrl("notifications.php"), { method: "POST", body: formData });
      fetchNotifications();
    });
  }

  // Initial fetch and light polling interval
  fetchNotifications();
  setInterval(fetchNotifications, 45000);
}

function escapeHtml(str) {
  if (!str) return "";
  return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

// ----------------------------------------------------
// Personalized Search Suggestions & Autocomplete
// ----------------------------------------------------
$$('[data-suggest-search]').forEach(input => {
  const wrapper = input.closest(".search-wrapper") || input.parentElement;
  let suggestionBox = $(".search-suggestions-box", wrapper);
  if (!suggestionBox) {
    suggestionBox = document.createElement("div");
    suggestionBox.className = "search-suggestions-box";
    wrapper.appendChild(suggestionBox);
  }

  let debounceTimer = null;
  input.addEventListener("input", () => {
    clearTimeout(debounceTimer);
    const query = input.value.trim();
    if (query.length < 2) {
      suggestionBox.classList.remove("show");
      suggestionBox.innerHTML = "";
      return;
    }

    debounceTimer = setTimeout(async () => {
      try {
        const res = await fetch(getApiUrl(`search-suggestions.php?q=${encodeURIComponent(query)}`));
        if (!res.ok) return;
        const data = await res.json();
        if (data.ok && data.suggestions && data.suggestions.length) {
          suggestionBox.innerHTML = data.suggestions.map(s => `
            <a href="${s.url}" class="suggestion-item">
              <span class="suggestion-tag">${escapeHtml(s.category)}</span>
              <strong>${escapeHtml(s.title)}</strong>
              <small>${escapeHtml(s.subtitle)}</small>
            </a>
          `).join("");
          suggestionBox.classList.add("show");
        } else {
          suggestionBox.classList.remove("show");
        }
      } catch (err) {
        suggestionBox.classList.remove("show");
      }
    }, 250);
  });

  document.addEventListener("click", (e) => {
    if (!wrapper.contains(e.target)) {
      suggestionBox.classList.remove("show");
    }
  });
});

// ----------------------------------------------------
// Accessible interface motion
// ----------------------------------------------------
(() => {
  const body = document.body;
  if (!body) return;

  const revealSelector = [
    ".page.active > .section-heading",
    ".page.active > .welcome-card",
    ".page.active > .renewal-alert-banner",
    ".page.active > .form-alert",
    ".page.active > .auto-scan-banner",
    ".page.active > .panel:not(.receipt-card)",
    ".stat-grid > .stat-card",
    ".content-grid > .panel",
    ".payment-list > a",
    ".prediction-card",
    ".upload-grid > .upload-card",
    ".ai-document-list > .ai-document-row",
    ".ai-applicant-document-list > .ai-applicant-doc-row",
    ".payment-success",
    ".auth-visual > *",
    ".auth-card > *"
  ].join(",");

  const revealElements = [...new Set($$(revealSelector))].filter(element => {
    return !element.hidden && !element.closest(".scanning-overlay, .chatbot-root");
  });
  revealElements.forEach((element, index) => {
    element.classList.add("motion-reveal");
    element.style.setProperty("--motion-delay", `${Math.min(index, 8) * 45}ms`);
  });

  const sequenceElements = $$(".bar-chart, .forecast-chart, .timeline, .history-list");
  sequenceElements.forEach(sequence => {
    sequence.classList.add("motion-sequence");
    [...sequence.children].forEach((child, index) => {
      child.style.setProperty("--motion-item-delay", `${Math.min(index, 8) * 45}ms`);
    });
  });

  const syncMotionPreference = () => {
    body.classList.toggle("motion-enabled", !prefersReducedMotion());
    body.classList.toggle("motion-reduced", prefersReducedMotion());
  };
  syncMotionPreference();
  if (typeof reduceMotionQuery.addEventListener === "function") {
    reduceMotionQuery.addEventListener("change", syncMotionPreference);
  }

  const reveal = element => element.classList.add("is-revealed");
  const showSequence = element => element.classList.add("is-motion-visible");

  const parseNumericValue = element => {
    const raw = element.textContent.trim();
    if (!raw || /[\u20b1\u0024\u20ac\u00a3\u00a5]/.test(raw)) return null;
    const match = raw.match(/^([^\d+-]*)([+-]?\d[\d,]*(?:\.\d+)?)([^\d]*)$/);
    if (!match) return null;
    const target = Number(match[2].replace(/,/g, ""));
    if (!Number.isFinite(target) || target < 0) return null;
    const decimalPart = match[2].split(".")[1] || "";
    return {
      raw,
      prefix: match[1],
      target,
      suffix: match[3],
      decimals: decimalPart.length,
      grouping: match[2].includes(",")
    };
  };

  const counterElements = $$(".stat-card > strong, .bar-row > strong, .accuracy-score")
    .map(element => ({ element, value: parseNumericValue(element) }))
    .filter(item => item.value !== null);

  const finishCounter = ({ element, value }) => {
    element.textContent = value.raw;
    element.dataset.motionCounted = "true";
  };

  const animateCounter = item => {
    const { element, value } = item;
    if (element.dataset.motionCounted === "true" || prefersReducedMotion() || value.target === 0) {
      finishCounter(item);
      return;
    }

    element.dataset.motionCounted = "running";
    const startedAt = performance.now();
    const duration = Math.min(950, 500 + Math.log10(value.target + 1) * 150);
    const draw = now => {
      if (prefersReducedMotion()) {
        finishCounter(item);
        return;
      }
      const progress = Math.min((now - startedAt) / duration, 1);
      const eased = 1 - Math.pow(1 - progress, 3);
      const current = value.target * eased;
      const formatted = current.toLocaleString(undefined, {
        minimumFractionDigits: value.decimals,
        maximumFractionDigits: value.decimals,
        useGrouping: value.grouping
      });
      element.textContent = `${value.prefix}${formatted}${value.suffix}`;
      if (progress < 1) {
        requestAnimationFrame(draw);
      } else {
        finishCounter(item);
      }
    };
    requestAnimationFrame(draw);
  };

  if (prefersReducedMotion()) {
    revealElements.forEach(reveal);
    sequenceElements.forEach(showSequence);
    counterElements.forEach(finishCounter);
  } else if ("IntersectionObserver" in window) {
    const revealObserver = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        reveal(entry.target);
        revealObserver.unobserve(entry.target);
      });
    }, { threshold: 0.08, rootMargin: "0px 0px -24px" });

    const sequenceObserver = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        showSequence(entry.target);
        sequenceObserver.unobserve(entry.target);
      });
    }, { threshold: 0.1, rootMargin: "0px 0px -20px" });

    const counterObserver = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        const item = counterElements.find(candidate => candidate.element === entry.target);
        if (item) animateCounter(item);
        counterObserver.unobserve(entry.target);
      });
    }, { threshold: 0.35 });

    revealElements.forEach(element => revealObserver.observe(element));
    sequenceElements.forEach(element => sequenceObserver.observe(element));
    counterElements.forEach(item => counterObserver.observe(item.element));
  } else {
    // Older browsers receive the complete interface without hidden content.
    revealElements.forEach(reveal);
    sequenceElements.forEach(showSequence);
    counterElements.forEach(animateCounter);
  }

  if (!prefersReducedMotion()) {
    document.addEventListener("pointerdown", event => {
      if (event.button !== 0 || !(event.target instanceof Element)) return;
      const trigger = event.target.closest(".button:not(:disabled), .icon-button:not(:disabled), .notification-bell:not(:disabled)");
      if (!trigger) return;

      trigger.classList.add("motion-interactive");
      const bounds = trigger.getBoundingClientRect();
      const size = Math.max(bounds.width, bounds.height) * 2;
      const ripple = document.createElement("span");
      ripple.className = "motion-ripple";
      ripple.setAttribute("aria-hidden", "true");
      ripple.style.width = `${size}px`;
      ripple.style.height = `${size}px`;
      ripple.style.left = `${event.clientX - bounds.left - size / 2}px`;
      ripple.style.top = `${event.clientY - bounds.top - size / 2}px`;
      trigger.appendChild(ripple);
      ripple.addEventListener("animationend", () => ripple.remove(), { once: true });
    });
  }

  window.addEventListener("beforeprint", () => {
    revealElements.forEach(reveal);
    sequenceElements.forEach(showSequence);
    counterElements.forEach(finishCounter);
    $$(".motion-ripple").forEach(ripple => ripple.remove());
  });
})();
