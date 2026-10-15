// Navbar: solid background after scrolling past the top
const navbar = document.querySelector(".navbar");

function updateNavbar() {
  navbar.classList.toggle("scrolled", window.scrollY > 60);
}

window.addEventListener("scroll", updateNavbar, { passive: true });
updateNavbar();

// Mobile nav toggle
const navToggle = document.querySelector(".nav-toggle");
if (navToggle) {
  navToggle.addEventListener("click", () => {
    const open = navbar.classList.toggle("nav-open");
    navToggle.setAttribute("aria-expanded", open);
  });

  document.querySelectorAll(".nav-links a").forEach((link) => {
    link.addEventListener("click", () => {
      navbar.classList.remove("nav-open");
      navToggle.setAttribute("aria-expanded", "false");
    });
  });
}

// Scroll-reveal animations
const revealObserver = "IntersectionObserver" in window
  ? new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const delay = entry.target.dataset.delay || 0;
            entry.target.style.transitionDelay = `${delay}ms`;
            entry.target.classList.add("visible");
            revealObserver.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
    )
  : null;

function watchReveals(root) {
  (root || document).querySelectorAll(".reveal").forEach((el) => {
    if (revealObserver) {
      revealObserver.observe(el);
    } else {
      el.classList.add("visible");
    }
  });
}

watchReveals();

// Page text loaded from plain .txt files (see content/README.md).
// Paragraphs are blocks separated by blank lines.
document.querySelectorAll("[data-content]").forEach(async (container) => {
  try {
    const response = await fetch(container.dataset.content);
    if (!response.ok) throw new Error(response.status);
    const text = await response.text();
    text
      .split(/\n\s*\n/)
      .map((block) => block.trim())
      .filter(Boolean)
      .forEach((paragraph) => {
        const p = document.createElement("p");
        p.className = "reveal";
        p.textContent = paragraph;
        container.appendChild(p);
      });
  } catch (err) {
    const p = document.createElement("p");
    p.className = "reveal";
    p.textContent = "This content could not be loaded. Please try refreshing the page.";
    container.appendChild(p);
  }
  watchReveals(container);
});

// The Print button on a message page (the print window can also save a PDF).
const messageActions = document.querySelector(".message-actions");
if (messageActions) {
  messageActions.hidden = false;
  messageActions.querySelector("[data-print]")?.addEventListener("click", () => window.print());
}

// Message list: picking a category (or another page) swaps the list in place with a short
// fade, instead of reloading the page. If that fails, the link just loads normally (keeping the
// scroll position); without JavaScript, the links end in #messages and open at the list.
const SCROLL_KEY = "cahob-category-scroll";
const listSection = document.getElementById("messages");
const listMain = listSection?.querySelector(".messages-main");

function loadNormally(url) {
  const target = new URL(url, window.location.href);
  try {
    sessionStorage.setItem(SCROLL_KEY, JSON.stringify({ to: target.pathname + target.search, y: window.scrollY }));
  } catch { /* storage blocked: the page opens at #messages instead */ }
  window.location.href = url;
}

if (listSection && listMain) {
  const fadeMs = window.matchMedia("(prefers-reduced-motion: reduce)").matches ? 0 : 200;
  let latest = 0;

  const showList = async (url, { push, scrollToList }) => {
    const request = ++latest;
    listMain.classList.add("is-leaving");
    listMain.setAttribute("aria-busy", "true");
    let page;
    try {
      const [response] = await Promise.all([
        fetch(url, { credentials: "same-origin" }),
        new Promise((resolve) => setTimeout(resolve, fadeMs)),
      ]);
      if (!response.ok) throw new Error(String(response.status));
      page = new DOMParser().parseFromString(await response.text(), "text/html");
    } catch {
      loadNormally(url);
      return;
    }
    if (request !== latest) return; // a newer click is already on its way

    const newMain = page.querySelector("#messages .messages-main");
    if (!newMain) {
      loadNormally(url);
      return;
    }
    listMain.innerHTML = newMain.innerHTML;
    // The list fades in as a whole, so its items don't need their own scroll-in animation.
    listMain.querySelectorAll(".reveal").forEach((el) => el.classList.remove("reveal"));

    // The rest of the page that depends on the category: heading, tab title, language switch.
    const title = page.querySelector(".hero-title");
    const heroTitle = document.querySelector(".hero-title");
    if (title && heroTitle) heroTitle.textContent = title.textContent;
    document.title = page.title;
    const langSwitch = page.querySelector(".lang-switch");
    const ourSwitch = document.querySelector(".lang-switch");
    if (langSwitch && ourSwitch) ourSwitch.setAttribute("href", langSwitch.getAttribute("href"));
    // The sidebar stays (keeping keyboard focus); only which category is current changes.
    const current = new URL(url, window.location.href);
    const category = current.searchParams.get("category");
    document.querySelectorAll(".category-nav a").forEach((link) => {
      const linkCategory = new URL(link.href).searchParams.get("category");
      if (linkCategory === category) link.setAttribute("aria-current", "page");
      else link.removeAttribute("aria-current");
    });

    if (push) history.pushState({ messageList: true }, "", current.pathname + current.search);
    if (scrollToList) listSection.scrollIntoView({ behavior: fadeMs ? "smooth" : "auto", block: "start" });
    requestAnimationFrame(() => {
      listMain.classList.remove("is-leaving");
      listMain.removeAttribute("aria-busy");
    });
  };

  history.replaceState({ messageList: true }, "");
  listSection.addEventListener("click", (event) => {
    const link = event.target.closest(".category-nav a, .pagination a");
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (new URL(link.href).origin !== window.location.origin) return;
    event.preventDefault();
    // Another page of the same list starts at its top; another category stays where you are.
    showList(link.href, { push: true, scrollToList: link.closest(".pagination") !== null });
  });
  window.addEventListener("popstate", (event) => {
    if (event.state?.messageList) showList(window.location.href, { push: false, scrollToList: false });
  });
}

// After a normal reload from the list (see loadNormally), come back to the same spot.
try {
  const kept = JSON.parse(sessionStorage.getItem(SCROLL_KEY) || "null");
  sessionStorage.removeItem(SCROLL_KEY);
  if (kept && kept.to === window.location.pathname + window.location.search) {
    // Again once loaded, since the browser's own jump to #messages can come after this.
    const restore = () => window.scrollTo({ top: kept.y, behavior: "instant" });
    restore();
    window.addEventListener("load", restore, { once: true });
  }
} catch { /* nothing kept */ }

// The home page answers at both / and /index.html; show the shorter address.
if (window.location.pathname.endsWith("/index.html")) {
  history.replaceState(null, "", window.location.pathname.slice(0, -"index.html".length) + window.location.search + window.location.hash);
}
