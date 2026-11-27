(() => {
  const storageKey = "segnoBag";
  const cards = [...document.querySelectorAll("[data-product]")];
  const hero = document.querySelector(".hero");
  const heroSlides = [...document.querySelectorAll(".hero-slide")];
  const slideNumber = document.getElementById("slideNumber");
  const bag = (() => {
    try {
      const saved = JSON.parse(localStorage.getItem(storageKey) || "[]");
      return Array.isArray(saved) ? saved : [];
    } catch {
      return [];
    }
  })();
  const successMessage = document.querySelector(".flash");

  if (successMessage && successMessage.textContent.trim().startsWith("Order #")) {
    localStorage.removeItem(storageKey);
    bag.splice(0, bag.length);
  }

  const safeBag = () =>
    bag.filter(
      (item) =>
        item &&
        Number.isInteger(Number(item.id)) &&
        Number.isInteger(Number(item.qty)) &&
        Number(item.qty) > 0,
    );
  const persist = () => localStorage.setItem(storageKey, JSON.stringify(bag));

  const renderBag = () => {
    const valid = safeBag();
    const quantity = valid.reduce((sum, item) => sum + Number(item.qty), 0);
    const total = valid.reduce(
      (sum, item) => sum + Number(item.price) * Number(item.qty),
      0,
    );
    document.getElementById("count").textContent = `(${quantity})`;
    document.getElementById("drawerCount").textContent = `(${quantity})`;
    document.getElementById("total").textContent = `$${total.toFixed(2)}`;

    const items = document.getElementById("items");
    items.replaceChildren();

    if (!valid.length) {
      const empty = document.createElement("p");
      empty.className = "sub";
      empty.textContent = "Your bag is waiting for its first score.";
      items.append(empty);
      return;
    }

    valid.forEach((item) => {
      const row = document.createElement("div");
      row.className = "item";

      const name = document.createElement("strong");
      name.textContent = item.title;
      const details = document.createElement("small");
      details.textContent = `${item.category} · Qty ${item.qty}`;
      const info = document.createElement("div");
      info.append(name, details);

      const price = document.createElement("div");
      price.textContent = `$${(Number(item.price) * Number(item.qty)).toFixed(2)}`;
      const remove = document.createElement("button");
      remove.className = "remove";
      remove.type = "button";
      remove.textContent = "Remove";
      remove.addEventListener("click", () => {
        const index = bag.findIndex(
          (entry) => Number(entry.id) === Number(item.id),
        );
        if (index >= 0) bag.splice(index, 1);
        persist();
        renderBag();
      });

      price.append(document.createElement("br"), remove);
      row.append(info, price);
      items.append(row);
    });
  };

  const closeCart = () => {
    document.getElementById("drawer").classList.remove("open");
    document.getElementById("overlay").classList.remove("open");
  };

  document.querySelectorAll(".add").forEach((button) => {
    button.addEventListener("click", () => {
      const id = Number(button.dataset.id);
      const existing = bag.find((item) => Number(item.id) === id);

      if (existing) {
        existing.qty = Math.min(20, Number(existing.qty) + 1);
      } else {
        bag.push({
          id,
          title: button.dataset.title,
          category: button.dataset.category,
          price: Number(button.dataset.price),
          qty: 1,
        });
      }

      persist();
      renderBag();
    });
  });

  const filterProducts = () => {
    const query = document.getElementById("search").value.trim().toLowerCase();
    const category = document.querySelector(".filter.active").dataset.filter;
    let visible = 0;

    cards.forEach((card) => {
      const matchesCategory =
        category === "all" || card.dataset.category === category;
      const matchesQuery = card.dataset.search.includes(query);
      card.hidden = !matchesCategory || !matchesQuery;
      if (!card.hidden) visible += 1;
    });

    document.getElementById("empty").hidden = visible > 0;
  };

  document.querySelectorAll("[data-hero-filter], [data-collection]").forEach((link) => {
    link.addEventListener("click", () => {
      const category = link.dataset.heroFilter || link.dataset.collection;
      const filter = [...document.querySelectorAll(".filter")].find(
        (button) => button.dataset.filter === category,
      );
      if (filter) filter.click();
    });
  });

  document.querySelectorAll(".filter").forEach((button) => {
    button.addEventListener("click", () => {
      document
        .querySelectorAll(".filter")
        .forEach((filter) =>
          filter.classList.toggle("active", filter === button),
        );
      document.querySelector(".shop h2").textContent =
        button.dataset.filter === "all"
          ? "Music that you come back to."
          : `${button.dataset.filter} scores.`;
      filterProducts();
    });
  });

  if (hero && heroSlides.length > 1) {
    const reducedMotion = window.matchMedia(
      "(prefers-reduced-motion: reduce)",
    ).matches;
    let activeSlide = 0;
    let carouselTimer;

    const showSlide = (index) => {
      activeSlide = (index + heroSlides.length) % heroSlides.length;
      heroSlides.forEach((slide, slideIndex) => {
        const active = slideIndex === activeSlide;
        slide.classList.toggle("is-active", active);
        slide.setAttribute("aria-hidden", String(!active));
      });
      slideNumber.textContent = String(activeSlide + 1).padStart(2, "0");
    };

    const stopCarousel = () => {
      window.clearInterval(carouselTimer);
    };

    const startCarousel = () => {
      stopCarousel();
      if (!reducedMotion && !document.hidden) {
        carouselTimer = window.setInterval(
          () => showSlide(activeSlide + 1),
          6500,
        );
      }
    };

    document.getElementById("heroPrev").addEventListener("click", () => {
      showSlide(activeSlide - 1);
      startCarousel();
    });
    document.getElementById("heroNext").addEventListener("click", () => {
      showSlide(activeSlide + 1);
      startCarousel();
    });
    hero.addEventListener("mouseenter", stopCarousel);
    hero.addEventListener("mouseleave", startCarousel);
    hero.addEventListener("focusin", stopCarousel);
    hero.addEventListener("focusout", (event) => {
      if (!hero.contains(event.relatedTarget)) startCarousel();
    });
    document.addEventListener("visibilitychange", startCarousel);
    startCarousel();
  }

  document.getElementById("search").addEventListener("input", filterProducts);
  document.getElementById("openCart").addEventListener("click", () => {
    document.getElementById("drawer").classList.add("open");
    document.getElementById("overlay").classList.add("open");
  });
  document.getElementById("close").addEventListener("click", closeCart);
  document.getElementById("overlay").addEventListener("click", closeCart);
  document
    .getElementById("checkoutForm")
    .addEventListener("submit", (event) => {
      const saved = safeBag();
      if (!saved.length) {
        event.preventDefault();
        const message = document.getElementById("checkoutMessage");
        message.textContent =
          "Add a score to your bag before placing an order.";
        message.hidden = false;
        return;
      }

      document.getElementById("checkoutItems").value = JSON.stringify(
        saved.map((item) => ({ id: item.id, qty: item.qty })),
      );
    });

  renderBag();
})();
