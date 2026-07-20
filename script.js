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
