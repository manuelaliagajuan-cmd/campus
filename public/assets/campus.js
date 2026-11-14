document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.querySelector('.mobile-toggle');
  if (toggle) toggle.addEventListener('click', () => {
    const open = document.body.classList.toggle('menu-open');
    toggle.setAttribute('aria-expanded', String(open));
  });
  const courseToggle = document.querySelector('.course-outline-toggle');
  const isMobile = () => window.matchMedia('(max-width: 740px)').matches;
  const setCourseCollapsed = collapsed => {
    document.body.classList.toggle('course-nav-collapsed', collapsed);
    courseToggle?.setAttribute('aria-expanded', String(!collapsed));
    try { sessionStorage.setItem('cec:course-navigation-collapsed', String(collapsed)); } catch (_) {}
  };
  const closeCourseNavigation = () => {
    if (isMobile()) {
      document.body.classList.remove('menu-open');
      toggle?.setAttribute('aria-expanded', 'false');
      toggle?.focus();
    } else {
      setCourseCollapsed(true);
      courseToggle?.focus();
    }
  };
  if (courseToggle) {
    try { setCourseCollapsed(sessionStorage.getItem('cec:course-navigation-collapsed') === 'true'); } catch (_) {}
    courseToggle.addEventListener('click', () => setCourseCollapsed(!document.body.classList.contains('course-nav-collapsed')));
  }
  document.querySelectorAll('[data-close-course-nav]').forEach(button => button.addEventListener('click', closeCourseNavigation));
  document.addEventListener('click', event => {
    document.querySelectorAll('.profile-menu[open], .header-language[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const openMenu = document.querySelector('.profile-menu[open], .header-language[open]');
    if (openMenu) { openMenu.open = false; openMenu.querySelector('summary').focus(); }
    else if (document.body.classList.contains('learning-course') && document.body.classList.contains('menu-open')) closeCourseNavigation();
  });
  const outline = document.querySelector('[data-course-outline]');
  if (outline) {
    const key = 'cec:course-outline:' + outline.dataset.courseOutline;
    let saved = {};
    try { saved = JSON.parse(sessionStorage.getItem(key) || '{}'); } catch (_) {}
    const units = [...outline.querySelectorAll('[data-outline-unit]')];
    units.forEach(unit => {
      if (unit.dataset.activeUnit === 'true') unit.open = true;
      else if (saved.open && Object.prototype.hasOwnProperty.call(saved.open, unit.dataset.outlineUnit)) unit.open = saved.open[unit.dataset.outlineUnit];
    });
    if (typeof saved.scroll === 'number') outline.scrollTop = saved.scroll;
    const save = () => {
      const open = Object.fromEntries(units.map(unit => [unit.dataset.outlineUnit, unit.open]));
      try { sessionStorage.setItem(key, JSON.stringify({open, scroll: outline.scrollTop})); } catch (_) {}
    };
    units.forEach(unit => unit.addEventListener('toggle', save));
    outline.addEventListener('scroll', save, {passive: true});
    const reveal = () => {
      const current = outline.querySelector('[aria-current="page"]');
      if (!current) return;
      const box = current.getBoundingClientRect(), viewport = outline.getBoundingClientRect();
      if (box.top < viewport.top) outline.scrollTop -= viewport.top - box.top + 12;
      else if (box.bottom > viewport.bottom) outline.scrollTop += box.bottom - viewport.bottom + 12;
    };
    window.requestAnimationFrame(reveal);
    toggle?.addEventListener('click', () => window.requestAnimationFrame(reveal));
    courseToggle?.addEventListener('click', () => window.requestAnimationFrame(reveal));
  }
  document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  }));

  document.querySelectorAll('.drag-list').forEach(list => {
    const status = document.createElement('p');
    status.className = 'sr-only';
    status.setAttribute('aria-live', 'polite');
    list.after(status);
    const refresh = () => {
      const items = [...list.querySelectorAll(':scope > [data-sortable-item]')];
      items.forEach((item, index) => {
        item.querySelector('.sort-position').textContent = index + 1;
        item.querySelector('[data-move="up"]').disabled = index === 0;
        item.querySelector('[data-move="down"]').disabled = index === items.length - 1;
      });
      return items;
    };
    const announce = item => {
      const items = refresh();
      status.textContent = `${item.querySelector('.text').textContent}: ${items.indexOf(item) + 1} / ${items.length}`;
    };
    list.addEventListener('click', event => {
      const button = event.target.closest('[data-move]');
      if (!button || button.disabled) return;
      const item = button.closest('[data-sortable-item]');
      if (button.dataset.move === 'up' && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
      if (button.dataset.move === 'down' && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
      announce(item);
      const focusButton = button.disabled ? item.querySelector('[data-move]:not(:disabled)') : button;
      focusButton?.focus();
    });
    refresh();
    if (typeof window.Sortable !== 'function') return;
    new window.Sortable(list, {
      draggable: '[data-sortable-item]',
      animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180,
      forceFallback: true,
      fallbackOnBody: true,
      fallbackTolerance: 4,
      ghostClass: 'sort-placeholder',
      chosenClass: 'sort-chosen',
      fallbackClass: 'sort-floating',
      filter: 'button, input, a, select, textarea',
      preventOnFilter: false,
      swapThreshold: 0.65,
      scroll: true,
      bubbleScroll: true,
      scrollSensitivity: 70,
      scrollSpeed: 12,
      onStart: () => { list.classList.add('sort-active'); document.body.classList.add('is-sorting'); },
      onChange: event => {
        refresh();
        const floatingPosition = document.querySelector('.sort-floating .sort-position');
        if (floatingPosition) floatingPosition.textContent = event.newIndex + 1;
      },
      onEnd: event => {
        list.classList.remove('sort-active');
        document.body.classList.remove('is-sorting');
        announce(event.item);
      }
    });
  });

  const notificationCount = document.querySelector('[data-notification-count]');
  if (notificationCount) {
    const poll = async () => {
      if (document.hidden) return;
      try {
        const response = await fetch(notificationCount.dataset.url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
        if (!response.ok) return;
        const {count} = await response.json();
        notificationCount.textContent = count > 99 ? '99+' : count;
        notificationCount.hidden = count === 0;
        notificationCount.closest('a').setAttribute('aria-label', window.cecT('Notificaciones') + ': ' + count + ' ' + window.cecT('sin leer'));
      } catch (_) { /* Existing notices remain available when the connection is interrupted. */ }
    };
    window.setInterval(poll, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  }
});
