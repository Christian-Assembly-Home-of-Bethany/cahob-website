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

// Picking a category on the message list reloads the page; keep it scrolled where it was
// instead of going back to the top. (Without JavaScript, the #messages link still opens at the list.)
const SCROLL_KEY = "cahob-category-scroll";
document.querySelectorAll(".category-nav a").forEach((link) => {
  link.addEventListener("click", () => {
    try {
      sessionStorage.setItem(SCROLL_KEY, JSON.stringify({ to: link.pathname + link.search, y: window.scrollY }));
    } catch { /* storage blocked: the #messages link still lands on the list */ }
  });
});
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
