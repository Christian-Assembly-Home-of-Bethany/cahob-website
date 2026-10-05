// Show/hide buttons on password fields. The button starts hidden, so without JavaScript the
// field is just a normal password box.
document.querySelectorAll(".password-toggle").forEach((button) => {
  const input = document.getElementById(button.getAttribute("aria-controls"));
  if (!input) return;

  button.hidden = false;
  button.addEventListener("click", () => {
    const show = input.type === "password";
    const label = show ? button.dataset.hideLabel : button.dataset.showLabel;
    input.type = show ? "text" : "password";
    button.setAttribute("aria-pressed", String(show));
    button.setAttribute("aria-label", label);
    button.title = label;
    input.focus();
  });

  // Hide it again before submitting, so the browser never saves it as plain text.
  input.form?.addEventListener("submit", () => {
    input.type = "password";
  });
});
