(() => {
  "use strict";

  const normalize = (value) => value.toLocaleLowerCase().normalize("NFKD");

  const init = (form) => {
    let catalog;
    try {
      catalog = JSON.parse(form.dataset.suggestCatalog || "[]");
    } catch {
      return;
    }
    if (!Array.isArray(catalog) || catalog.length === 0) return;

    const input = form.querySelector(".tx-solr-demo-suggest");
    if (!input) return;

    const list = document.createElement("ul");
    list.id = `${input.id}-suggestions`;
    list.className = "autocomplete-suggestions tx-solr-autosuggest";
    list.setAttribute("role", "listbox");
    list.hidden = true;
    document.body.append(list);

    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-controls", list.id);
    input.setAttribute("aria-expanded", "false");

    let selected = -1;
    let matches = [];

    const position = () => {
      const inputRect = input.getBoundingClientRect();
      const formRect = form.getBoundingClientRect();
      list.style.top = `${formRect.bottom + window.scrollY}px`;
      list.style.left = `${inputRect.left + window.scrollX}px`;
      list.style.width = `${inputRect.width}px`;
    };

    const close = () => {
      selected = -1;
      list.hidden = true;
      input.setAttribute("aria-expanded", "false");
      input.removeAttribute("aria-activedescendant");
    };

    const select = (index) => {
      const items = [...list.querySelectorAll("li[role=option]")];
      items.forEach((item) => item.classList.remove("autocomplete-selected"));
      selected = Math.max(0, Math.min(index, items.length - 1));
      const item = items[selected];
      if (!item) return;
      item.classList.add("autocomplete-selected");
      input.setAttribute("aria-activedescendant", item.id);
      item.scrollIntoView({ block: "nearest" });
    };

    const score = (document, tokens) => {
      const title = normalize(document.title || "");
      const words = title.split(/\s+/);
      const keywords = normalize(document.keywords || "").split(/\s+/);
      const searchable = normalize(`${document.title || ""} ${document.content || ""} ${document.keywords || ""}`);
      let value = 0;
      for (const token of tokens) {
        if (!searchable.includes(token)) return 0;
        if (title.startsWith(token)) value += 100;
        else if (words.some((word) => word.startsWith(token))) value += 50;
        else if (keywords.some((word) => word.startsWith(token))) value += 20;
        else value += 5;
      }
      return value;
    };

    const render = () => {
      const query = normalize(input.value.trim());
      if (query.length < 2) return close();
      const tokens = query.split(/\s+/).filter(Boolean);
      matches = catalog
        .map((document) => ({ document, score: score(document, tokens) }))
        .filter((entry) => entry.score > 0)
        .sort((left, right) => right.score - left.score || left.document.title.localeCompare(right.document.title))
        .slice(0, 4)
        .map((entry) => entry.document);

      list.replaceChildren();
      if (matches.length === 0) return close();

      const header = document.createElement("li");
      header.className = "autocomplete-group";
      header.setAttribute("role", "presentation");
      header.textContent = form.dataset.suggestHeader || "Top matches";
      list.append(header);

      matches.forEach((record, index) => {
        const item = document.createElement("li");
        item.id = `${list.id}-${index}`;
        item.className = "autocomplete-suggestion";
        item.setAttribute("role", "option");
        const link = document.createElement("a");
        link.href = record.url;
        link.textContent = record.title;
        item.append(link);
        item.addEventListener("mouseenter", () => select(index));
        list.append(item);
      });

      position();
      selected = -1;
      list.hidden = false;
      input.setAttribute("aria-expanded", "true");
    };

    input.addEventListener("input", render);
    input.addEventListener("focus", render);
    input.addEventListener("keydown", (event) => {
      if (event.key === "Escape") return close();
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        if (list.hidden) render();
        if (!list.hidden) {
          event.preventDefault();
          select(event.key === "ArrowDown" ? selected + 1 : selected <= 0 ? matches.length - 1 : selected - 1);
        }
      }
      if (event.key === "Enter" && selected >= 0 && matches[selected]) {
        event.preventDefault();
        window.location.assign(matches[selected].url);
      }
    });
    document.addEventListener("pointerdown", (event) => {
      if (!form.contains(event.target) && !list.contains(event.target)) close();
    });
    window.addEventListener("resize", position, { passive: true });
    window.addEventListener("scroll", position, { passive: true });
  };

  document.querySelectorAll(".tx-solr-search-form[data-suggest-catalog]").forEach(init);
})();
