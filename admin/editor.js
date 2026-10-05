// The message editor (admin/edit.php). Turns each .message-editor into a Quill editor, copies
// the text into the form's hidden fields on submit, keeps a copy of unsaved work in this
// browser, and keeps the login alive while the pastor is typing.
(() => {
  const form = document.getElementById("editor-form");
  if (!form || !window.Quill) return;
  const text = window.CAHOB_EDITOR || {};

  // ---------- Formats Quill doesn't have built in ----------

  // A divider (horizontal line), saved as <hr>.
  const BlockEmbed = Quill.import("blots/block/embed");
  class Divider extends BlockEmbed {}
  Divider.blotName = "divider";
  Divider.tagName = "HR";
  Quill.register(Divider);

  // Centering as style="text-align: center" (what the server keeps) instead of a CSS class.
  Quill.register(Quill.import("attributors/style/align"), true);

  const icons = Quill.import("ui/icons");
  const svg = (body) => `<svg viewBox="0 0 18 18">${body}</svg>`;
  // The scripture sidebar (a blockquote) gets a word instead of an icon, so it is easy to find.
  const label = document.createElement("span");
  label.className = "ql-text-label";
  label.textContent = text.tb_scripture_label || "";
  icons.blockquote = label.outerHTML;
  icons.divider = svg('<line class="ql-stroke" x1="2" x2="16" y1="9" y2="9"/>');
  icons.table = svg('<rect class="ql-stroke" x="2.5" y="3.5" width="13" height="11" rx="1" fill="none"/><line class="ql-stroke" x1="2.5" x2="15.5" y1="7.5" y2="7.5"/><line class="ql-stroke" x1="2.5" x2="15.5" y1="11" y2="11"/><line class="ql-stroke" x1="9" x2="9" y1="7.5" y2="14.5"/>');
  icons["table-row"] = svg('<rect class="ql-stroke" x="2.5" y="3" width="13" height="5" rx="1" fill="none"/><line class="ql-stroke" x1="9" x2="9" y1="10.5" y2="16"/><line class="ql-stroke" x1="6.25" x2="11.75" y1="13.25" y2="13.25"/>');
  icons["table-column"] = svg('<rect class="ql-stroke" x="2.5" y="2.5" width="5" height="13" rx="1" fill="none"/><line class="ql-stroke" x1="10.5" x2="16" y1="9" y2="9"/><line class="ql-stroke" x1="13.25" x2="13.25" y1="6.25" y2="11.75"/>');
  icons["table-delete"] = svg('<rect class="ql-stroke" x="2.5" y="3.5" width="13" height="11" rx="1" fill="none"/><line class="ql-stroke" x1="6" x2="12" y1="6.5" y2="11.5"/><line class="ql-stroke" x1="12" x2="6" y1="6.5" y2="11.5"/>');

  const TOOLBAR = [
    [{ header: 2 }, { header: 3 }],
    ["bold", "italic"],
    [{ list: "ordered" }, { list: "bullet" }],
    ["blockquote"],
    ["link", { align: "center" }, "divider"],
    ["table", "table-row", "table-column", "table-delete"],
    ["clean"],
  ];
  const BUTTON_LABELS = {
    "header-2": text.tb_h2, "header-3": text.tb_h3, bold: text.tb_bold, italic: text.tb_italic,
    "list-ordered": text.tb_ol, "list-bullet": text.tb_ul, blockquote: text.tb_scripture, link: text.tb_link,
    "align-center": text.tb_center, divider: text.tb_divider, table: text.tb_table,
    "table-row": text.tb_row, "table-column": text.tb_col, "table-delete": text.tb_table_delete,
    clean: text.tb_clean,
  };

  // ---------- The two editors ----------

  const editors = [];
  form.querySelectorAll(".message-editor").forEach((element) => {
    const input = document.getElementById(element.dataset.input);
    const quill = new Quill(element, {
      theme: "snow",
      // Only these formats are kept, including when pasting from Word: fonts, sizes, and
      // colors are dropped right away.
      formats: ["header", "bold", "italic", "list", "blockquote", "link", "align", "divider", "table"],
      modules: {
        table: true,
        toolbar: {
          container: TOOLBAR,
          handlers: {
            divider() {
              const range = this.quill.getSelection(true);
              this.quill.insertEmbed(range.index, "divider", true, "user");
              this.quill.setSelection(range.index + 1, "silent");
            },
            table() { this.quill.getModule("table").insertTable(3, 3); },
            "table-row"() { this.quill.getModule("table").insertRowBelow(); },
            "table-column"() { this.quill.getModule("table").insertColumnRight(); },
            "table-delete"() { this.quill.getModule("table").deleteTable(); },
          },
        },
      },
    });
    quill.root.setAttribute("aria-labelledby", element.dataset.label);
    quill.getModule("toolbar").container.querySelectorAll("button").forEach((button) => {
      const name = button.classList[0].replace("ql-", "") + (button.value ? `-${button.value}` : "");
      if (BUTTON_LABELS[name]) {
        button.title = BUTTON_LABELS[name];
        button.setAttribute("aria-label", BUTTON_LABELS[name]);
      }
    });
    editors.push({ quill, input });
  });

  // Quill writes every space as &nbsp;; the server undoes that too, this keeps previews right.
  const htmlOf = (quill) => (quill.getLength() <= 1 ? "" : quill.getSemanticHTML().replace(/&nbsp;/g, " "));
  const syncInputs = () => editors.forEach(({ quill, input }) => { input.value = htmlOf(quill); });

  // ---------- A copy of unsaved work in this browser ----------

  const storage = {
    get(key) { try { return JSON.parse(localStorage.getItem(key)); } catch { return null; } },
    set(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* storage full or blocked */ } },
    remove(key) { try { localStorage.removeItem(key); } catch { /* blocked */ } },
  };
  const key = `cahob-message-${form.dataset.key}`;
  const fields = ["published_at", "title_zh", "title_en"].map((id) => document.getElementById(id));
  const snapshot = () => {
    syncInputs();
    const data = {};
    fields.forEach((field) => { data[field.name] = field.value; });
    editors.forEach(({ input }) => { data[input.name] = input.value; });
    return data;
  };

  const onPage = JSON.stringify(snapshot());
  if (form.dataset.saved === "1") {
    // The server just saved this message, so any older copy is out of date.
    storage.remove(key);
    if (form.dataset.created === "1") storage.remove("cahob-message-new");
  } else {
    const kept = storage.get(key);
    if (kept && kept.data && JSON.stringify(kept.data) !== onPage) offerRestore(kept);
  }

  function offerRestore(kept) {
    const banner = document.getElementById("restore-banner");
    const when = new Date(kept.at).toLocaleString(text.locale, { dateStyle: "medium", timeStyle: "short" });
    document.getElementById("restore-text").textContent = (text.restore_found || "").replace("{time}", when);
    banner.hidden = false;
    document.getElementById("restore-button").addEventListener("click", () => {
      fields.forEach((field) => { if (typeof kept.data[field.name] === "string") field.value = kept.data[field.name]; });
      editors.forEach(({ quill, input }) => {
        quill.setContents(quill.clipboard.convert({ html: kept.data[input.name] || "" }), "silent");
      });
      banner.hidden = true;
      dirty = true;
    });
    document.getElementById("discard-button").addEventListener("click", () => {
      storage.remove(key);
      banner.hidden = true;
    });
  }

  let dirty = false;
  let lastTyped = 0;
  let saveTimer;
  const changed = () => {
    dirty = true;
    lastTyped = Date.now();
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => storage.set(key, { at: Date.now(), data: snapshot() }), 800);
  };
  editors.forEach(({ quill }) => quill.on("text-change", (delta, old, source) => { if (source === "user") changed(); }));
  fields.forEach((field) => field.addEventListener("input", changed));

  // ---------- Submitting ----------

  let leaving = false;
  form.addEventListener("submit", (event) => {
    syncInputs();
    // Preview opens in a new tab; this page stays open with the work still unsaved.
    if (event.submitter && event.submitter.value !== "preview") leaving = true;
  });
  // Enter in a title or the date shouldn't submit the form by accident.
  form.querySelectorAll('input[type="text"], input[type="datetime-local"]').forEach((input) => {
    input.addEventListener("keydown", (event) => { if (event.key === "Enter") event.preventDefault(); });
  });
  window.addEventListener("beforeunload", (event) => {
    if (dirty && !leaving) {
      event.preventDefault();
      event.returnValue = "";
    }
  });

  // ---------- Keeping the login alive while typing ----------

  setInterval(async () => {
    if (Date.now() - lastTyped > 10 * 60 * 1000) return; // only while actively writing
    try {
      const response = await fetch("/admin/ping.php", { credentials: "same-origin", cache: "no-store" });
      if (response.status === 401) document.getElementById("session-lost").hidden = false;
    } catch { /* offline for a moment; try again next time */ }
  }, 5 * 60 * 1000);
})();
